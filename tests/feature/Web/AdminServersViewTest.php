<?php

use App\Models\ServerModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * View-rendering coverage for the admin server management UI
 * (`admin/servers/index.php`). Controller/model logic (CRUD, validation,
 * soft-delete, gating) is already covered by ServerModelTest and
 * AdminServersTest — this suite closes the remaining gap: does the view
 * actually render, is dynamic output (name/ip) escaped, does the "Servers"
 * nav link show/hide correctly per zone, and is the route gated the same
 * way as the rest of the admin area.
 *
 * @internal
 */
final class AdminServersViewTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use WebAuthFixture;

    private const XSS_SCRIPT = '<script>alert(1)</script>';

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    protected function tearDown(): void
    {
        parent::tearDown();
        session()->destroy();
    }

    public function testIndexRendersTheListWithSearchFormAndAddServerControl(): void
    {
        $admin = $this->seedWebUser(['email' => 'view-servers-admin@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $result = $this->withSession()->get('/admin/servers');

        $result->assertStatus(200);
        $result->assertSee('Servers');
        $result->assertSee('+ Add server');
        $result->assertSeeElement('table');
        $result->assertSeeElement('form');
        $body = (string) $result->response()->getBody();
        $this->assertStringContainsString('<button', $body);
        $this->assertStringContainsString('Clear', $body);
    }

    public function testNameAndIpXssIsEscapedOnTheList(): void
    {
        $admin = $this->seedWebUser(['email' => 'view-servers-admin2@example.com', 'zone' => 'global']);
        model(ServerModel::class)->insert([
            'name'   => self::XSS_SCRIPT,
            'ip'     => self::XSS_SCRIPT,
            'status' => 1,
        ]);
        $this->loginAs($admin);

        $result = $this->withSession()->get('/admin/servers');

        $result->assertStatus(200);
        $body = (string) $result->response()->getBody();
        $this->assertStringNotContainsString(self::XSS_SCRIPT, $body);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
    }

    public function testDashboardShowsServersLinkForAdminAndHidesItForClient(): void
    {
        // Nav hrefs are rendered through esc(..., 'attr') (slashes become
        // `&#x2F;`), so match the encoded form the browser actually receives.
        $serversHref = 'admin&#x2F;servers';

        $admin = $this->seedWebUser(['email' => 'view-servers-admin3@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $adminDashboard = $this->withSession()->get('/dashboard');
        $adminDashboard->assertStatus(200);
        $this->assertStringContainsString($serversHref, (string) $adminDashboard->response()->getBody());

        $target = $this->seedWebUser(['email' => 'view-servers-target@example.com', 'zone' => 'client']);
        $this->withSession()->post('/admin/users/act-as/' . $target->id, $this->withCsrf([]));

        $actingAsClientDashboard = $this->withSession()->get('/dashboard');
        $actingAsClientDashboard->assertStatus(200);
        $this->assertStringNotContainsString($serversHref, (string) $actingAsClientDashboard->response()->getBody());

        $this->withSession()->post('/admin/users/stop-acting-as', $this->withCsrf([]));

        $client = $this->seedWebUser(['email' => 'view-servers-client@example.com', 'zone' => 'client']);
        $this->loginAs($client);

        $clientDashboard = $this->withSession()->get('/dashboard');
        $clientDashboard->assertStatus(200);
        $this->assertStringNotContainsString($serversHref, (string) $clientDashboard->response()->getBody());
    }

    public function testGuestIsRedirectedToLoginFromServersIndex(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $result = $this->get('/admin/servers');

        $result->assertRedirectTo('/login');
    }

    public function testClientSessionIsBlockedFromServersIndexByAdminGate(): void
    {
        $client = $this->seedWebUser(['email' => 'view-servers-client-gate@example.com', 'zone' => 'client']);
        $this->loginAs($client);

        $result = $this->withSession()->get('/admin/servers');

        $result->assertRedirectTo('/dashboard');
    }
}
