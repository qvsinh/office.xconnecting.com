<?php

use App\Models\UserModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * Impersonation security (`Admin\Users::actAs()`/`stopActing()`) — the
 * required sign-off gates from kanban plan §6: `act-as` mutates only
 * `session('userWorking')`, `userAuthenticated` (and therefore `is_admin()`)
 * is never touched, a non-admin session can't reach the endpoint, and
 * `stop-acting-as` restores the admin identity.
 *
 * @internal
 */
final class AdminActAsTest extends CIUnitTestCase
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

    public function testActAsSetsUserWorkingButKeepsUserAuthenticatedAsTheAdmin(): void
    {
        $admin  = $this->seedWebUser(['email' => 'admin-actas@example.com', 'zone' => 'admin']);
        $target = $this->seedWebUser(['email' => 'target-actas@example.com', 'zone' => 'user']);
        $this->loginAs($admin);

        $result = $this->withSession()->post('/admin/users/act-as/' . $target->id, $this->withCsrf([]));

        $result->assertRedirectTo('/dashboard');
        $this->assertSame((int) $target->id, (int) session('userWorking')->id);
        $this->assertSame((int) $admin->id, (int) session('userAuthenticated')->id);
    }

    public function testIsAdminStaysTrueAndClientScopedReadReflectsTheTargetAfterActAs(): void
    {
        $admin  = $this->seedWebUser(['email' => 'admin-scope@example.com', 'zone' => 'admin']);
        $target = $this->seedWebUser(['name' => 'Target Person', 'email' => 'target-scope@example.com', 'zone' => 'user']);
        $this->loginAs($admin);
        $this->withSession()->post('/admin/users/act-as/' . $target->id, $this->withCsrf([]));

        // is_admin() reads userAuthenticated (unaffected) -> the admin area
        // must still be reachable, not redirected away. Uses admin/users
        // purely as an adminFilter probe.
        $adminStillReachable = $this->withSession()->get('/admin/users');
        $adminStillReachable->assertStatus(200);

        // The client-scoped dashboard reads userWorking -> reflects the target.
        $dashboard = $this->withSession()->get('/dashboard');
        $dashboard->assertStatus(200);
        $dashboard->assertSee('Target Person');
    }

    public function testStopActingRestoresUserWorkingToTheAdmin(): void
    {
        $admin  = $this->seedWebUser(['email' => 'admin-stop@example.com', 'zone' => 'admin']);
        $target = $this->seedWebUser(['email' => 'target-stop@example.com', 'zone' => 'user']);
        $this->loginAs($admin);
        $this->withSession()->post('/admin/users/act-as/' . $target->id, $this->withCsrf([]));
        $this->assertSame((int) $target->id, (int) session('userWorking')->id);

        $result = $this->withSession()->post('/admin/users/stop-acting-as', $this->withCsrf([]));

        $result->assertRedirectTo('/admin/users');
        $this->assertSame((int) $admin->id, (int) session('userWorking')->id);
        $this->assertSame((int) $admin->id, (int) session('userAuthenticated')->id);
    }

    public function testStopActingIsSafeToCallWhenNotImpersonating(): void
    {
        $admin = $this->seedWebUser(['email' => 'admin-idempotent@example.com', 'zone' => 'admin']);
        $this->loginAs($admin);

        $result = $this->withSession()->post('/admin/users/stop-acting-as', $this->withCsrf([]));

        $result->assertRedirectTo('/admin/users');
        $this->assertSame((int) $admin->id, (int) session('userWorking')->id);
    }

    public function testNonAdminClientSessionCannotImpersonate(): void
    {
        $client = $this->seedWebUser(['email' => 'client-actas@example.com', 'zone' => 'user']);
        $target = $this->seedWebUser(['email' => 'target-blocked@example.com', 'zone' => 'user']);
        $this->loginAs($client);

        $result = $this->withSession()->post('/admin/users/act-as/' . $target->id, $this->withCsrf([]));

        // adminFilter redirects non-admin sessions away before actAs() runs.
        $result->assertRedirectTo('/dashboard');
        $this->assertSame((int) $client->id, (int) session('userWorking')->id);
    }

    public function testActAsRejectsUnknownUserId(): void
    {
        $admin = $this->seedWebUser(['email' => 'admin-unknown@example.com', 'zone' => 'admin']);
        $this->loginAs($admin);

        $result = $this->withSession()->post('/admin/users/act-as/999999', $this->withCsrf([]));

        $result->assertStatus(404);
        $this->assertSame((int) $admin->id, (int) session('userWorking')->id);
    }

    public function testActAsIsNotRoutedForGet(): void
    {
        $admin  = $this->seedWebUser(['email' => 'admin-getcheck@example.com', 'zone' => 'admin']);
        $target = $this->seedWebUser(['email' => 'target-getcheck@example.com', 'zone' => 'user']);
        $this->loginAs($admin);

        $this->expectException(PageNotFoundException::class);
        $this->withSession()->get('/admin/users/act-as/' . $target->id);
    }

    public function testActAsWithoutCsrfTokenIsRejected(): void
    {
        $admin  = $this->seedWebUser(['email' => 'admin-nocsrf@example.com', 'zone' => 'admin']);
        $target = $this->seedWebUser(['email' => 'target-nocsrf@example.com', 'zone' => 'user']);
        $this->loginAs($admin);

        $result = $this->withSession()->post('/admin/users/act-as/' . $target->id, []);

        $result->assertRedirect(); // Security::$redirect === true -> CSRF failure redirects, not 403
        $this->assertSame((int) $admin->id, (int) session('userWorking')->id);
    }
}
