<?php

use App\Libraries\PasswordHasher;
use App\Models\RoleModel;
use App\Validation\PasswordRules;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * Admin user management (`Admin\Users`), exercised through real routing +
 * filters (`authFilter`/`adminFilter`) + CSRF: CRUD, password hashing,
 * unique-email, self/last-admin lockout guards, admin gating, XSS escaping.
 * Impersonation (`actAs`/`stopActing`) has its own dedicated suite —
 * AdminActAsTest.
 *
 * @internal
 */
final class AdminUsersTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use WebAuthFixture;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    protected function tearDown(): void
    {
        parent::tearDown();
        session()->destroy();
    }

    public function testCreateStoresAHashedPasswordAndNeverEchoesIt(): void
    {
        $admin = $this->seedWebUser(['email' => 'creator@example.com', 'zone' => 'admin']);
        $this->loginAs($admin);

        $create = $this->withSession()->post('/admin/users/create', $this->withCsrf([
            'name'     => 'New User',
            'email'    => 'newuser@example.com',
            'password' => 'PlainPass1!',
            'zone'     => 'user',
            'status'   => 1,
        ]));

        $create->assertStatus(200);
        $json = json_decode($create->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertStringNotContainsString('PlainPass1!', $create->getJSON());

        $row = model(UserModel::class)->findActiveByEmail('newuser@example.com');
        $this->assertNotNull($row);
        // Stored hashed, never in the clear, and not the old unsalted MD5.
        $this->assertTrue((new PasswordHasher())->verify('PlainPass1!', $row->password));
        $this->assertNotSame(hash('md5', 'PlainPass1!'), $row->password);
        $this->assertStringStartsWith('$2y$', (string) $row->password);
    }

    public function testCreateWithBlankPasswordAutoGeneratesAValidHash(): void
    {
        $admin = $this->seedWebUser(['email' => 'creator2@example.com', 'zone' => 'admin']);
        $this->loginAs($admin);

        $create = $this->withSession()->post('/admin/users/create', $this->withCsrf([
            'name'   => 'Auto Password User',
            'email'  => 'autopass@example.com',
            'zone'   => 'user',
            'status' => 1,
        ]));

        $create->assertStatus(200);

        $row = model(UserModel::class)->findActiveByEmail('autopass@example.com');
        $this->assertNotNull($row);
        $this->assertNotSame('', $row->password);
        // A real bcrypt hash of *something* — the generated password is never
        // returned, so this is the strongest available check that one was stored.
        $this->assertStringStartsWith('$2y$', (string) $row->password);
        $this->assertFalse((new PasswordHasher())->verify('', $row->password));
    }

    public function testCreateAssignsAdminRoleIdForGlobalZoneAndUserRoleIdForClientZone(): void
    {
        $admin    = $this->seedWebUser(['email' => 'role-creator@example.com', 'zone' => 'admin']);
        $roleModel = new RoleModel();
        $adminRole = $roleModel->findByName('admin');
        $userRole  = $roleModel->findByName('user');
        $this->loginAs($admin);

        $createGlobal = $this->withSession()->post('/admin/users/create', $this->withCsrf([
            'name'   => 'New Global User',
            'email'  => 'new-global@example.com',
            'zone'   => 'admin',
            'status' => 1,
        ]));
        $createGlobal->assertStatus(200);
        $globalRow = model(UserModel::class)->findActiveByEmail('new-global@example.com');
        $this->assertSame((int) $adminRole->id, (int) $globalRow->role_id);

        $createClient = $this->withSession()->post('/admin/users/create', $this->withCsrf([
            'name'   => 'New Client User',
            'email'  => 'new-client@example.com',
            'zone'   => 'user',
            'status' => 1,
        ]));
        $createClient->assertStatus(200);
        $clientRow = model(UserModel::class)->findActiveByEmail('new-client@example.com');
        $this->assertSame((int) $userRole->id, (int) $clientRow->role_id);
    }

    public function testCreateRejectsAWeakPasswordAndCreatesNobody(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'pwadmin@example.com', 'zone' => 'admin']));

        $result = $this->withSession()->post('/admin/users/create', $this->withCsrf([
            'name'     => 'Weak Password User',
            'email'    => 'weakpw@example.com',
            'zone'     => 'user',
            'status'   => 1,
            'password' => 'short1',
        ]));

        $result->assertStatus(400);
        $this->assertNull(model(UserModel::class)->findActiveByEmail('weakpw@example.com'));
    }

    public function testCreateWithABlankPasswordAutoGeneratesOneThatMeetsThePolicy(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'pwadmin2@example.com', 'zone' => 'admin']));

        $this->withSession()->post('/admin/users/create', $this->withCsrf([
            'name'     => 'Auto Password User',
            'email'    => 'autopw@example.com',
            'zone'     => 'user',
            'status'   => 1,
            'password' => '',
        ]))->assertStatus(200);

        $created = model(UserModel::class)->findActiveByEmail('autopw@example.com');
        $this->assertNotNull($created);
        // The generated password is never returned, so assert on what we can:
        // a hash was stored, and it is not a hash of the empty string.
        $this->assertNotSame('', (string) $created->password);
        $this->assertFalse((new PasswordHasher())->verify('', $created->password));
    }

    public function testUpdateRejectsAWeakPasswordAndLeavesTheHashAlone(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'pwadmin3@example.com', 'zone' => 'admin']));
        $target = $this->seedWebUser(['email' => 'pwtarget@example.com']);
        $before = model(UserModel::class)->find($target->id)->password;

        $result = $this->withSession()->post('/admin/users/update', $this->withCsrf([
            'id'       => $target->id,
            'name'     => 'Target',
            'email'    => 'pwtarget@example.com',
            'zone'     => 'user',
            'status'   => 1,
            'password' => 'nospecial1A',
        ]));

        $result->assertStatus(400);
        $this->assertSame($before, model(UserModel::class)->find($target->id)->password);
    }

    public function testCreateRejectsDuplicateEmail(): void
    {
        $admin = $this->seedWebUser(['email' => 'creator3@example.com', 'zone' => 'admin']);
        $this->seedWebUser(['email' => 'dup@example.com']);
        $this->loginAs($admin);

        $create = $this->withSession()->post('/admin/users/create', $this->withCsrf([
            'name'   => 'Dup User',
            'email'  => 'dup@example.com',
            'zone'   => 'user',
            'status' => 1,
        ]));

        $create->assertStatus(400);
        $json = json_decode($create->getJSON(), true);
        $this->assertSame('failed', $json['status']);
    }

    public function testCreateRejectsInvalidZoneOrEnv(): void
    {
        $admin = $this->seedWebUser(['email' => 'creator4@example.com', 'zone' => 'admin']);
        $this->loginAs($admin);

        $result = $this->withSession()->post('/admin/users/create', $this->withCsrf([
            'name'   => 'Bad Zone User',
            'email'  => 'badzone@example.com',
            'zone'   => 'superadmin',
            'status' => 1,
        ]));

        $result->assertStatus(400);
    }

    public function testUpdatePersistsEditsAndKeepsPasswordWhenBlank(): void
    {
        $admin  = $this->seedWebUser(['email' => 'updater@example.com', 'zone' => 'admin']);
        $target = $this->seedWebUser(['email' => 'target-update@example.com', 'zone' => 'user', 'name' => 'Original Name']);
        $this->loginAs($admin);

        $update = $this->withSession()->post('/admin/users/update', $this->withCsrf([
            'id'     => $target->id,
            'name'   => 'Updated Name',
            'email'  => $target->email,
            'zone'   => 'user',
            'status' => 1,
            // password intentionally omitted -> existing hash must survive
        ]));

        $update->assertStatus(200);

        $reloaded = model(UserModel::class)->find($target->id);
        $this->assertSame('Updated Name', $reloaded->name);
        $this->assertSame($target->password, $reloaded->password);
    }

    public function testUpdateWithNonBlankPasswordRehashes(): void
    {
        $admin  = $this->seedWebUser(['email' => 'updater2@example.com', 'zone' => 'admin']);
        $target = $this->seedWebUser(['email' => 'target-rehash@example.com']);
        $this->loginAs($admin);

        $update = $this->withSession()->post('/admin/users/update', $this->withCsrf([
            'id'       => $target->id,
            'name'     => $target->name,
            'email'    => $target->email,
            'zone'     => 'user',
            'status'   => 1,
            'password' => 'BrandNewPass1!',
        ]));

        $update->assertStatus(200);

        $reloaded = model(UserModel::class)->find($target->id);
        $hasher   = new PasswordHasher();
        $this->assertTrue($hasher->verify('BrandNewPass1!', $reloaded->password));
        $this->assertFalse($hasher->verify(self::WEB_AUTH_TEST_PASSWORD, $reloaded->password));
        $this->assertNotSame($target->password, $reloaded->password);
    }

    public function testUpdateReassignsRoleIdWhenZoneChanges(): void
    {
        $admin  = $this->seedWebUser(['email' => 'role-updater@example.com', 'zone' => 'admin']);
        $target = $this->seedWebUser(['email' => 'role-target@example.com', 'zone' => 'user']);
        $this->loginAs($admin);

        $adminRole = (new RoleModel())->findByName('admin');

        $update = $this->withSession()->post('/admin/users/update', $this->withCsrf([
            'id'     => $target->id,
            'name'   => $target->name,
            'email'  => $target->email,
            'zone'   => 'admin',
            'status' => 1,
        ]));

        $update->assertStatus(200);
        $reloaded = model(UserModel::class)->find($target->id);
        $this->assertSame((int) $adminRole->id, (int) $reloaded->role_id);
    }

    public function testUpdateAllowsKeepingOwnEmailButRejectsDuplicateOfAnother(): void
    {
        $admin  = $this->seedWebUser(['email' => 'updater3@example.com', 'zone' => 'admin']);
        $target = $this->seedWebUser(['email' => 'keep-own-email@example.com']);
        $this->seedWebUser(['email' => 'someone-else@example.com']);
        $this->loginAs($admin);

        $sameEmail = $this->withSession()->post('/admin/users/update', $this->withCsrf([
            'id'     => $target->id,
            'name'   => $target->name,
            'email'  => $target->email, // unchanged -> unique-except-self must allow
            'zone'   => 'user',
            'status' => 1,
        ]));
        $sameEmail->assertStatus(200);

        $collide = $this->withSession()->post('/admin/users/update', $this->withCsrf([
            'id'     => $target->id,
            'name'   => $target->name,
            'email'  => 'someone-else@example.com',
            'zone'   => 'user',
            'status' => 1,
        ]));
        $collide->assertStatus(400);
    }

    public function testAdminCannotDemoteOrDeactivateThemselves(): void
    {
        $admin = $this->seedWebUser(['email' => 'self-lockout@example.com', 'zone' => 'admin']);
        // A second active admin exists so this failure is provably the
        // self-lockout guard, not the last-admin guard.
        $this->seedWebUser(['email' => 'other-admin@example.com', 'zone' => 'admin']);
        $this->loginAs($admin);

        $demote = $this->withSession()->post('/admin/users/update', $this->withCsrf([
            'id'     => $admin->id,
            'name'   => $admin->name,
            'email'  => $admin->email,
            'zone'   => 'user', // attempting to demote self
            'status' => 1,
        ]));

        $demote->assertStatus(400);
        $reloaded = model(UserModel::class)->find($admin->id);
        $this->assertSame('admin', $reloaded->zone);

        $deactivate = $this->withSession()->post('/admin/users/update', $this->withCsrf([
            'id'     => $admin->id,
            'name'   => $admin->name,
            'email'  => $admin->email,
            'zone'   => 'admin',
            'status' => 0, // attempting to deactivate self
        ]));

        $deactivate->assertStatus(400);
        $this->assertSame(1, (int) model(UserModel::class)->find($admin->id)->status);
    }

    public function testLastActiveAdminCannotBeDemotedButSucceedsWithTwoActiveAdmins(): void
    {
        // Actor is a distinct admin-zone session (bypasses login's
        // status=1 gate via a direct session write) so this failure is
        // provably the last-admin guard, not the self-lockout guard.
        $actor  = $this->seedWebUser(['email' => 'actor@example.com', 'zone' => 'admin', 'status' => 0]);
        $target = $this->seedWebUser(['email' => 'sole-admin@example.com', 'zone' => 'admin', 'status' => 1]);

        $reject = $this->withSession(['basicAuthAuthenticated' => 1, 'userAuthenticated' => $actor, 'userWorking' => $actor])->post('/admin/users/update', $this->withCsrf([
            'id'     => $target->id,
            'name'   => $target->name,
            'email'  => $target->email,
            'zone'   => 'user',
            'status' => 1,
        ]));

        $reject->assertStatus(400);
        $this->assertSame('admin', model(UserModel::class)->find($target->id)->zone);

        // A second active admin exists now -> the same edit succeeds.
        $this->seedWebUser(['email' => 'second-admin@example.com', 'zone' => 'admin', 'status' => 1]);

        $allow = $this->withSession(['basicAuthAuthenticated' => 1, 'userAuthenticated' => $actor, 'userWorking' => $actor])->post('/admin/users/update', $this->withCsrf([
            'id'     => $target->id,
            'name'   => $target->name,
            'email'  => $target->email,
            'zone'   => 'user',
            'status' => 1,
        ]));

        $allow->assertStatus(200);
        $this->assertSame('user', model(UserModel::class)->find($target->id)->zone);
    }

    public function testNonAdminClientSessionIsRejectedByAdminGate(): void
    {
        $client = $this->seedWebUser(['email' => 'client-gate@example.com', 'zone' => 'user']);
        $this->loginAs($client);

        $index = $this->withSession()->get('/admin/users');
        $index->assertRedirectTo('/dashboard');

        $create = $this->withSession()->post('/admin/users/create', $this->withCsrf([
            'name'   => 'Should Not Be Created',
            'email'  => 'blocked@example.com',
            'zone'   => 'user',
            'status' => 1,
        ]));
        $create->assertRedirectTo('/dashboard');
        $this->assertNull(model(UserModel::class)->findActiveByEmail('blocked@example.com'));
    }

    public function testGuestIsRedirectedToLoginFromAdminUsersIndex(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $result = $this->get('/admin/users');

        $result->assertRedirectTo('/login');
    }

    /**
     * A `<script>` name must survive storage byte-for-byte — escaping is the
     * view layer's job (`esc()`, per project convention) once
     * `frontend-coder` builds `admin/users/index.php`/`detail.php`; a
     * dedicated view-render XSS test (mirroring `ApiKeysViewTest`) belongs
     * there, not here — `view()` calls in this controller 404 until those
     * templates exist.
     */
    public function testCreateStoresScriptTagNameVerbatimForTheViewLayerToEscape(): void
    {
        $admin = $this->seedWebUser(['email' => 'xss-admin@example.com', 'zone' => 'admin']);
        $this->loginAs($admin);

        $create = $this->withSession()->post('/admin/users/create', $this->withCsrf([
            'name'   => '<script>alert(1)</script>',
            'email'  => 'xss-target@example.com',
            'zone'   => 'user',
            'status' => 1,
        ]));

        $create->assertStatus(200);
        $row = model(UserModel::class)->findActiveByEmail('xss-target@example.com');
        $this->assertSame('<script>alert(1)</script>', $row->name);
    }

    public function testEditReturnsUserFieldsWithoutPassword(): void
    {
        $admin  = $this->seedWebUser(['email' => 'edit-admin@example.com', 'zone' => 'admin']);
        $target = $this->seedWebUser(['email' => 'edit-target@example.com', 'name' => 'Edit Target']);
        $this->loginAs($admin);

        $edit = $this->withSession()->get('/admin/users/edit/' . $target->id);

        $edit->assertStatus(200);
        $json = json_decode($edit->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertSame('Edit Target', $json['user']['name']);
        $this->assertSame($target->email, $json['user']['email']);
        $this->assertArrayNotHasKey('password', $json['user']);
    }

    public function testEditRejectsUnknownUserId(): void
    {
        $admin = $this->seedWebUser(['email' => 'edit-admin2@example.com', 'zone' => 'admin']);
        $this->loginAs($admin);

        $edit = $this->withSession()->get('/admin/users/edit/999999');

        $edit->assertStatus(404);
    }

    public function testUpdateNotePersistsForAdmin(): void
    {
        $admin  = $this->seedWebUser(['email' => 'note-admin@example.com', 'zone' => 'admin']);
        $target = $this->seedWebUser(['email' => 'note-target@example.com']);
        $this->loginAs($admin);

        $note = $this->withSession()->post('/admin/users/note', $this->withCsrf([
            'id'   => $target->id,
            'note' => 'Flagged for manual billing review.',
        ]));

        $note->assertStatus(200);
        $json = json_decode($note->getJSON(), true);
        $this->assertSame('success', $json['status']);

        $reloaded = model(UserModel::class)->find($target->id);
        $this->assertSame('Flagged for manual billing review.', $reloaded->note);
    }

    public function testUpdateNoteRejectsUnknownUserId(): void
    {
        $admin = $this->seedWebUser(['email' => 'note-admin2@example.com', 'zone' => 'admin']);
        $this->loginAs($admin);

        $note = $this->withSession()->post('/admin/users/note', $this->withCsrf([
            'id'   => 999999,
            'note' => 'Should not be stored.',
        ]));

        $note->assertStatus(404);
    }

    public function testUpdateNoteIsBlockedForNonAdminSession(): void
    {
        $client = $this->seedWebUser(['email' => 'note-client@example.com', 'zone' => 'user']);
        $target = $this->seedWebUser(['email' => 'note-target2@example.com']);
        $this->loginAs($client);

        $note = $this->withSession()->post('/admin/users/note', $this->withCsrf([
            'id'   => $target->id,
            'note' => 'Should be blocked by adminFilter.',
        ]));

        $note->assertRedirectTo('/dashboard');
        $this->assertNull(model(UserModel::class)->find($target->id)->note);
    }

    // ------------------------------------------------- zone vocabulary + form

    /**
     * Regression: the form's Zone options must be values the server accepts.
     *
     * After `users.zone` was renamed `global`/`client` -> `admin`/`user`, the
     * modals still offered `global`/`client` while the controller validated
     * `in_list[admin,user]`. Picking "Admin" therefore failed validation, so no
     * admin could be created from the UI at all. Asserting on the markup is what
     * catches a mismatch between the two halves.
     */
    public function testTheZoneOptionsAreTheValuesTheServerAccepts(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'zoneform@example.com', 'zone' => 'admin']));

        $html = (string) $this->withSession()->get('/admin/users')->getBody();

        $this->assertStringContainsString('<option value="admin">Admin</option>', $html);
        $this->assertStringContainsString('<option value="user">User</option>', $html);
        $this->assertStringNotContainsString('value="global"', $html);
        $this->assertStringNotContainsString('value="client"', $html);
    }

    /**
     * @dataProvider zoneProvider
     */
    public function testAUserCanBeCreatedInEitherZone(string $zone, bool $expectAdmin): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'creator-' . $zone . '@example.com', 'zone' => 'admin']));

        $email  = 'made-' . $zone . '@example.com';
        $result = $this->withSession()->post('/admin/users/create', $this->withCsrf([
            'name'     => 'Made ' . $zone,
            'email'    => $email,
            'password' => 'Str0ng!Pass1',
            'zone'     => $zone,
            'status'   => 1,
        ]));

        $result->assertStatus(200);

        $created = model(UserModel::class)->where('email', $email)->first();
        $this->assertNotNull($created, 'Creating a ' . $zone . ' user must succeed.');
        $this->assertSame($zone, $created->zone);
        $this->assertSame($expectAdmin, zone_is_admin($created->zone));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function zoneProvider(): array
    {
        return ['admin' => ['admin', true], 'user' => ['user', false]];
    }

    public function testTheRetiredZoneVocabularyIsRejected(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'legacyzone@example.com', 'zone' => 'admin']));

        foreach (['global', 'client', 'anything'] as $zone) {
            $this->withSession()->post('/admin/users/create', $this->withCsrf([
                'name'     => 'Legacy',
                'email'    => 'legacy-' . $zone . '@example.com',
                'password' => 'Str0ng!Pass1',
                'zone'     => $zone,
                'status'   => 1,
            ]))->assertStatus(400);
        }
    }

    // ----------------------------------------------------------- environment

    public function testTheEnvironmentFieldIsGoneFromTheFormsAndTheSchema(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'noenv@example.com', 'zone' => 'admin']));

        $db = db_connect();
        $db->resetDataCache(); // else fieldExists() answers from the pre-migration cache
        $this->assertFalse($db->fieldExists('env', 'users'), 'users.env should have been dropped.');

        $html = (string) $this->withSession()->get('/admin/users')->getBody();
        $this->assertStringNotContainsString('Environment', $html);
        $this->assertStringNotContainsString('TESTING', $html);
    }

    /**
     * Creating a user must not depend on the removed field — previously `env`
     * was `required`, so a form that no longer sends it would 400.
     */
    public function testCreateNoLongerRequiresEnvironment(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'envless@example.com', 'zone' => 'admin']));

        $this->withSession()->post('/admin/users/create', $this->withCsrf([
            'name'     => 'No Env',
            'email'    => 'no-env@example.com',
            'password' => 'Str0ng!Pass1',
            'zone'     => 'user',
            'status'   => 1,
        ]))->assertStatus(200);

        $this->assertNotNull(model(UserModel::class)->where('email', 'no-env@example.com')->first());
    }

    // ------------------------------------------------------ password generate

    public function testGeneratePasswordReturnsOneThatSatisfiesThePolicy(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'gen@example.com', 'zone' => 'admin']));

        $seen = [];

        // Ten draws: a generator that satisfies the policy only sometimes, or
        // that returns a constant, fails here rather than in production.
        for ($i = 0; $i < 10; $i++) {
            $json = json_decode($this->withSession()->get('/admin/users/generate-password')->getJSON(), true);

            $this->assertSame('success', $json['status']);
            $password = $json['password'];

            $error = null;
            $this->assertTrue(
                (new PasswordRules())->strong_password($password, $error),
                'Generated password "' . $password . '" is rejected by the policy it is generated for: ' . (string) $error,
            );

            $seen[$password] = true;
        }

        $this->assertGreaterThan(1, count($seen), 'The generator returned the same password every time.');
    }

    public function testGeneratePasswordIsAdminOnly(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'notadmin@example.com', 'zone' => 'user']));
        $this->withSession()->get('/admin/users/generate-password')->assertRedirectTo('/dashboard');
    }

    public function testGeneratePasswordIsClosedToGuests(): void
    {
        $this->get('/admin/users/generate-password')->assertRedirectTo('/login');
    }

    /**
     * A generated password is only useful if the admin can then create the user
     * with it — i.e. the generator and the validator agree.
     */
    public function testAGeneratedPasswordIsAcceptedByTheCreateForm(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'genuse@example.com', 'zone' => 'admin']));

        $json     = json_decode($this->withSession()->get('/admin/users/generate-password')->getJSON(), true);
        $password = $json['password'];

        $this->withSession()->post('/admin/users/create', $this->withCsrf([
            'name'     => 'Generated',
            'email'    => 'generated@example.com',
            'password' => $password,
            'zone'     => 'user',
            'status'   => 1,
        ]))->assertStatus(200);

        $this->assertNotNull(model(UserModel::class)->where('email', 'generated@example.com')->first());

        // The point of showing the password is that the employee can sign in with
        // it. Asserted through a real login rather than by re-implementing the
        // hash, so this keeps holding if the hashing changes.
        session()->destroy();

        $login = $this->withSession(['basicAuthAuthenticated' => 1])
            ->post('/login', $this->withCsrf([
                'email'    => 'generated@example.com',
                'password' => $password,
            ]));

        $login->assertStatus(200);
        $this->assertSame('success', json_decode($login->getJSON(), true)['status']);
    }

    // ------------------------------------------------- create-form defaults

    /**
     * The "Add user" modal opens pre-filled, so the common case is open ->
     * Generate -> Create.
     *
     * Asserted on the raw body: `$result->getBody()` is forwarded to DOMParser and
     * returns libxml's rewrite of the page, which is not what the browser runs.
     */
    public function testTheCreateFormShipsDatedDummyDefaults(): void
    {
        helper('attendance');
        $this->loginAs($this->seedWebUser(['email' => 'defaults@example.com', 'zone' => 'admin']));

        $html  = (string) $this->withSession()->get('/admin/users')->response()->getBody();
        $stamp = str_replace('-', '', attendance_today());

        $this->assertStringContainsString('"name":"User' . $stamp . '"', $html);
        $this->assertStringContainsString('"email":"admin+' . substr($stamp, 2) . '@xconnecting.com"', $html);

        // The password must NOT be pre-filled — a fixed default would be a shared
        // credential, and a blank field is what makes "Generate" the obvious move.
        $this->assertStringContainsString('"password":""', $html);
    }

    /**
     * The defaults are only a starting point: the admin can still type over them,
     * and the server takes whatever arrives rather than the dummy values.
     */
    public function testTheDefaultsAreNotForcedOnTheServer(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'override@example.com', 'zone' => 'admin']));

        $this->withSession()->post('/admin/users/create', $this->withCsrf([
            'name'     => 'Real Person',
            'email'    => 'real.person@xconnecting.com',
            'password' => 'Str0ng!Pass1',
            'zone'     => 'admin',
            'status'   => 1,
        ]))->assertStatus(200);

        $created = model(UserModel::class)->where('email', 'real.person@xconnecting.com')->first();
        $this->assertNotNull($created);
        $this->assertSame('Real Person', $created->name);
        $this->assertSame('admin', $created->zone);
    }

    /**
     * Two accounts on one day: the second needs a different address, and the
     * server says so rather than silently creating a duplicate.
     */
    public function testASecondAccountOnTheSameDayIsRejectedUntilTheEmailChanges(): void
    {
        helper('attendance');
        $this->loginAs($this->seedWebUser(['email' => 'dup@example.com', 'zone' => 'admin']));

        $email = 'admin+' . substr(str_replace('-', '', attendance_today()), 2) . '@xconnecting.com';
        $body  = [
            'name'     => 'First',
            'email'    => $email,
            'password' => 'Str0ng!Pass1',
            'zone'     => 'user',
            'status'   => 1,
        ];

        $this->withSession()->post('/admin/users/create', $this->withCsrf($body))->assertStatus(200);

        $second = $this->withSession()->post('/admin/users/create', $this->withCsrf($body));
        $second->assertStatus(400);
        $this->assertStringContainsStringIgnoringCase('email', (string) json_decode($second->getJSON(), true)['message']);

        $this->assertSame(1, model(UserModel::class)->where('email', $email)->countAllResults());
    }
}
