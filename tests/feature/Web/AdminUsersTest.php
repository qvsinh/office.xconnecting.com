<?php

use App\Models\RoleModel;
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

    public function testCreateStoresMd5HashedPasswordAndNeverEchoesIt(): void
    {
        $admin = $this->seedWebUser(['email' => 'creator@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $create = $this->withSession()->post('/admin/users/create', $this->withCsrf([
            'name'     => 'New User',
            'email'    => 'newuser@example.com',
            'password' => 'PlainPass1',
            'zone'     => 'client',
            'env'      => 'TESTING',
            'status'   => 1,
        ]));

        $create->assertStatus(200);
        $json = json_decode($create->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertStringNotContainsString('PlainPass1', $create->getJSON());

        $row = model(UserModel::class)->findActiveByEmail('newuser@example.com');
        $this->assertNotNull($row);
        $this->assertSame(hash('md5', 'PlainPass1'), $row->password);
    }

    public function testCreateWithBlankPasswordAutoGeneratesAValidHash(): void
    {
        $admin = $this->seedWebUser(['email' => 'creator2@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $create = $this->withSession()->post('/admin/users/create', $this->withCsrf([
            'name'   => 'Auto Password User',
            'email'  => 'autopass@example.com',
            'zone'   => 'client',
            'env'    => 'TESTING',
            'status' => 1,
        ]));

        $create->assertStatus(200);

        $row = model(UserModel::class)->findActiveByEmail('autopass@example.com');
        $this->assertNotNull($row);
        $this->assertNotSame('', $row->password);
        $this->assertSame(32, strlen($row->password)); // md5 hex length
    }

    public function testCreateAssignsAdminRoleIdForGlobalZoneAndUserRoleIdForClientZone(): void
    {
        $admin    = $this->seedWebUser(['email' => 'role-creator@example.com', 'zone' => 'global']);
        $roleModel = new RoleModel();
        $adminRole = $roleModel->findByName('admin');
        $userRole  = $roleModel->findByName('user');
        $this->loginAs($admin);

        $createGlobal = $this->withSession()->post('/admin/users/create', $this->withCsrf([
            'name'   => 'New Global User',
            'email'  => 'new-global@example.com',
            'zone'   => 'global',
            'env'    => 'TESTING',
            'status' => 1,
        ]));
        $createGlobal->assertStatus(200);
        $globalRow = model(UserModel::class)->findActiveByEmail('new-global@example.com');
        $this->assertSame((int) $adminRole->id, (int) $globalRow->role_id);

        $createClient = $this->withSession()->post('/admin/users/create', $this->withCsrf([
            'name'   => 'New Client User',
            'email'  => 'new-client@example.com',
            'zone'   => 'client',
            'env'    => 'TESTING',
            'status' => 1,
        ]));
        $createClient->assertStatus(200);
        $clientRow = model(UserModel::class)->findActiveByEmail('new-client@example.com');
        $this->assertSame((int) $userRole->id, (int) $clientRow->role_id);
    }

    public function testCreateRejectsDuplicateEmail(): void
    {
        $admin = $this->seedWebUser(['email' => 'creator3@example.com', 'zone' => 'global']);
        $this->seedWebUser(['email' => 'dup@example.com']);
        $this->loginAs($admin);

        $create = $this->withSession()->post('/admin/users/create', $this->withCsrf([
            'name'   => 'Dup User',
            'email'  => 'dup@example.com',
            'zone'   => 'client',
            'env'    => 'TESTING',
            'status' => 1,
        ]));

        $create->assertStatus(400);
        $json = json_decode($create->getJSON(), true);
        $this->assertSame('failed', $json['status']);
    }

    public function testCreateRejectsInvalidZoneOrEnv(): void
    {
        $admin = $this->seedWebUser(['email' => 'creator4@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $result = $this->withSession()->post('/admin/users/create', $this->withCsrf([
            'name'   => 'Bad Zone User',
            'email'  => 'badzone@example.com',
            'zone'   => 'superadmin',
            'env'    => 'PRODUCTION',
            'status' => 1,
        ]));

        $result->assertStatus(400);
    }

    public function testUpdatePersistsEditsAndKeepsPasswordWhenBlank(): void
    {
        $admin  = $this->seedWebUser(['email' => 'updater@example.com', 'zone' => 'global']);
        $target = $this->seedWebUser(['email' => 'target-update@example.com', 'zone' => 'client', 'name' => 'Original Name']);
        $this->loginAs($admin);

        $update = $this->withSession()->post('/admin/users/update', $this->withCsrf([
            'id'     => $target->id,
            'name'   => 'Updated Name',
            'email'  => $target->email,
            'zone'   => 'client',
            'env'    => 'LIVE',
            'status' => 1,
            // password intentionally omitted -> existing hash must survive
        ]));

        $update->assertStatus(200);

        $reloaded = model(UserModel::class)->find($target->id);
        $this->assertSame('Updated Name', $reloaded->name);
        $this->assertSame('LIVE', $reloaded->env);
        $this->assertSame($target->password, $reloaded->password);
    }

    public function testUpdateWithNonBlankPasswordRehashes(): void
    {
        $admin  = $this->seedWebUser(['email' => 'updater2@example.com', 'zone' => 'global']);
        $target = $this->seedWebUser(['email' => 'target-rehash@example.com']);
        $this->loginAs($admin);

        $update = $this->withSession()->post('/admin/users/update', $this->withCsrf([
            'id'       => $target->id,
            'name'     => $target->name,
            'email'    => $target->email,
            'zone'     => 'client',
            'env'      => 'TESTING',
            'status'   => 1,
            'password' => 'BrandNewPass1',
        ]));

        $update->assertStatus(200);

        $reloaded = model(UserModel::class)->find($target->id);
        $this->assertSame(hash('md5', 'BrandNewPass1'), $reloaded->password);
        $this->assertNotSame($target->password, $reloaded->password);
    }

    public function testUpdateReassignsRoleIdWhenZoneChanges(): void
    {
        $admin  = $this->seedWebUser(['email' => 'role-updater@example.com', 'zone' => 'global']);
        $target = $this->seedWebUser(['email' => 'role-target@example.com', 'zone' => 'client']);
        $this->loginAs($admin);

        $adminRole = (new RoleModel())->findByName('admin');

        $update = $this->withSession()->post('/admin/users/update', $this->withCsrf([
            'id'     => $target->id,
            'name'   => $target->name,
            'email'  => $target->email,
            'zone'   => 'global',
            'env'    => 'TESTING',
            'status' => 1,
        ]));

        $update->assertStatus(200);
        $reloaded = model(UserModel::class)->find($target->id);
        $this->assertSame((int) $adminRole->id, (int) $reloaded->role_id);
    }

    public function testUpdateAllowsKeepingOwnEmailButRejectsDuplicateOfAnother(): void
    {
        $admin  = $this->seedWebUser(['email' => 'updater3@example.com', 'zone' => 'global']);
        $target = $this->seedWebUser(['email' => 'keep-own-email@example.com']);
        $this->seedWebUser(['email' => 'someone-else@example.com']);
        $this->loginAs($admin);

        $sameEmail = $this->withSession()->post('/admin/users/update', $this->withCsrf([
            'id'     => $target->id,
            'name'   => $target->name,
            'email'  => $target->email, // unchanged -> unique-except-self must allow
            'zone'   => 'client',
            'env'    => 'TESTING',
            'status' => 1,
        ]));
        $sameEmail->assertStatus(200);

        $collide = $this->withSession()->post('/admin/users/update', $this->withCsrf([
            'id'     => $target->id,
            'name'   => $target->name,
            'email'  => 'someone-else@example.com',
            'zone'   => 'client',
            'env'    => 'TESTING',
            'status' => 1,
        ]));
        $collide->assertStatus(400);
    }

    public function testAdminCannotDemoteOrDeactivateThemselves(): void
    {
        $admin = $this->seedWebUser(['email' => 'self-lockout@example.com', 'zone' => 'global']);
        // A second active admin exists so this failure is provably the
        // self-lockout guard, not the last-admin guard.
        $this->seedWebUser(['email' => 'other-admin@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $demote = $this->withSession()->post('/admin/users/update', $this->withCsrf([
            'id'     => $admin->id,
            'name'   => $admin->name,
            'email'  => $admin->email,
            'zone'   => 'client', // attempting to demote self
            'env'    => 'TESTING',
            'status' => 1,
        ]));

        $demote->assertStatus(400);
        $reloaded = model(UserModel::class)->find($admin->id);
        $this->assertSame('global', $reloaded->zone);

        $deactivate = $this->withSession()->post('/admin/users/update', $this->withCsrf([
            'id'     => $admin->id,
            'name'   => $admin->name,
            'email'  => $admin->email,
            'zone'   => 'global',
            'env'    => 'TESTING',
            'status' => 0, // attempting to deactivate self
        ]));

        $deactivate->assertStatus(400);
        $this->assertSame(1, (int) model(UserModel::class)->find($admin->id)->status);
    }

    public function testLastActiveAdminCannotBeDemotedButSucceedsWithTwoActiveAdmins(): void
    {
        // Actor is a distinct global-zone session (bypasses login's
        // status=1 gate via a direct session write) so this failure is
        // provably the last-admin guard, not the self-lockout guard.
        $actor  = $this->seedWebUser(['email' => 'actor@example.com', 'zone' => 'global', 'status' => 0]);
        $target = $this->seedWebUser(['email' => 'sole-admin@example.com', 'zone' => 'global', 'status' => 1]);

        $reject = $this->withSession(['basicAuthAuthenticated' => 1, 'userAuthenticated' => $actor, 'userWorking' => $actor])->post('/admin/users/update', $this->withCsrf([
            'id'     => $target->id,
            'name'   => $target->name,
            'email'  => $target->email,
            'zone'   => 'client',
            'env'    => 'TESTING',
            'status' => 1,
        ]));

        $reject->assertStatus(400);
        $this->assertSame('global', model(UserModel::class)->find($target->id)->zone);

        // A second active admin exists now -> the same edit succeeds.
        $this->seedWebUser(['email' => 'second-admin@example.com', 'zone' => 'global', 'status' => 1]);

        $allow = $this->withSession(['basicAuthAuthenticated' => 1, 'userAuthenticated' => $actor, 'userWorking' => $actor])->post('/admin/users/update', $this->withCsrf([
            'id'     => $target->id,
            'name'   => $target->name,
            'email'  => $target->email,
            'zone'   => 'client',
            'env'    => 'TESTING',
            'status' => 1,
        ]));

        $allow->assertStatus(200);
        $this->assertSame('client', model(UserModel::class)->find($target->id)->zone);
    }

    public function testNonAdminClientSessionIsRejectedByAdminGate(): void
    {
        $client = $this->seedWebUser(['email' => 'client-gate@example.com', 'zone' => 'client']);
        $this->loginAs($client);

        $index = $this->withSession()->get('/admin/users');
        $index->assertRedirectTo('/dashboard');

        $create = $this->withSession()->post('/admin/users/create', $this->withCsrf([
            'name'   => 'Should Not Be Created',
            'email'  => 'blocked@example.com',
            'zone'   => 'client',
            'env'    => 'TESTING',
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
        $admin = $this->seedWebUser(['email' => 'xss-admin@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $create = $this->withSession()->post('/admin/users/create', $this->withCsrf([
            'name'   => '<script>alert(1)</script>',
            'email'  => 'xss-target@example.com',
            'zone'   => 'client',
            'env'    => 'TESTING',
            'status' => 1,
        ]));

        $create->assertStatus(200);
        $row = model(UserModel::class)->findActiveByEmail('xss-target@example.com');
        $this->assertSame('<script>alert(1)</script>', $row->name);
    }

    public function testEditReturnsUserFieldsWithoutPassword(): void
    {
        $admin  = $this->seedWebUser(['email' => 'edit-admin@example.com', 'zone' => 'global']);
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
        $admin = $this->seedWebUser(['email' => 'edit-admin2@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $edit = $this->withSession()->get('/admin/users/edit/999999');

        $edit->assertStatus(404);
    }

    public function testUpdateNotePersistsForAdmin(): void
    {
        $admin  = $this->seedWebUser(['email' => 'note-admin@example.com', 'zone' => 'global']);
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
        $admin = $this->seedWebUser(['email' => 'note-admin2@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $note = $this->withSession()->post('/admin/users/note', $this->withCsrf([
            'id'   => 999999,
            'note' => 'Should not be stored.',
        ]));

        $note->assertStatus(404);
    }

    public function testUpdateNoteIsBlockedForNonAdminSession(): void
    {
        $client = $this->seedWebUser(['email' => 'note-client@example.com', 'zone' => 'client']);
        $target = $this->seedWebUser(['email' => 'note-target2@example.com']);
        $this->loginAs($client);

        $note = $this->withSession()->post('/admin/users/note', $this->withCsrf([
            'id'   => $target->id,
            'note' => 'Should be blocked by adminFilter.',
        ]));

        $note->assertRedirectTo('/dashboard');
        $this->assertNull(model(UserModel::class)->find($target->id)->note);
    }
}
