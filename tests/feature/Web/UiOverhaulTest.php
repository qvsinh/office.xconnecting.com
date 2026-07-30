<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * Feature-level coverage for the UI/UX overhaul
 * (kanban/processing/20260714-ui-ux-overhaul.md): the zone-aware sidebar
 * (item 2/3), the x-cloak FOUC guard + dark-mode plumbing (item 5/6), the
 * favicon link (item 8), and the filter "Clear" control being a real
 * `<button>` (item 1). Purely HTTP-assertable; the Alpine-driven toggle
 * behaviour itself is out of scope here (see plan §6 "Browser / E2E").
 *
 * @internal
 */
final class UiOverhaulTest extends CIUnitTestCase
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

    public function testAdminSessionOnDashboardSeesAdminNavGroup(): void
    {
        $admin = $this->seedWebUser(['email' => 'ui-admin-nav@example.com', 'zone' => 'admin']);
        $this->loginAs($admin);

        $result = $this->withSession()->get('/dashboard');

        $result->assertStatus(200);
        $result->assertSee('/admin/users');
        $result->assertSee('Admin');
    }

    public function testActingAsClientHidesAdminNavAndShowsBanner(): void
    {
        $admin  = $this->seedWebUser(['email' => 'ui-actas-admin@example.com', 'zone' => 'admin']);
        $client = $this->seedWebUser(['email' => 'ui-actas-client@example.com', 'zone' => 'user']);

        $result = $this->withSession([
            'basicAuthAuthenticated' => 1,
            'userAuthenticated'      => $admin,
            'userWorking'            => $client,
        ])->get('/dashboard');

        $result->assertStatus(200);
        $body = (string) $result->response()->getBody();
        // Exact sidebar-link match (quoted href) — the acting-as banner's
        // own "Return to admin" form posts to /admin/users/stop-acting-as,
        // which contains "/admin/users" as a substring, so a loose
        // assertStringNotContainsString('/admin/users', ...) would false-fail.
        $this->assertStringNotContainsString('href="/admin/users"', $body);
        $this->assertStringNotContainsString('>Admin<', $body);
        $this->assertStringContainsString('Acting as', $body);
        $this->assertStringContainsString('Return to admin', $body);
    }

    public function testPlainClientSessionSeesClientNavWithoutAdminGroup(): void
    {
        $client = $this->seedWebUser(['email' => 'ui-client-nav@example.com', 'zone' => 'user']);
        $this->loginAs($client);

        $result = $this->withSession()->get('/dashboard');

        $result->assertStatus(200);
        $body = (string) $result->response()->getBody();
        $this->assertStringNotContainsString('/admin/users', $body);
        $this->assertStringNotContainsString('Acting as', $body);
    }

    /**
     * `[x-cloak]{display:none}` has to reach the browser before Alpine renders,
     * or every collapsed panel flashes open on load.
     *
     * It used to be an inline `<style>` in the layout *and* in each standalone
     * auth page — four copies, one per template, and a new template that forgot
     * one would flash. It now lives in the compiled stylesheet, which is a
     * render-blocking `<head>` link and therefore lands just as early.
     *
     * So this asserts the property, not the mechanism: the rule is in the CSS the
     * page loads, and the page loads that CSS.
     */
    public function testEveryPageShipsTheXCloakFoucGuard(): void
    {
        $css = (string) file_get_contents(FCPATH . 'css/app.css');
        $this->assertMatchesRegularExpression('/\[x-cloak\]\s*\{[^}]*display:\s*none/', $css, 'The compiled CSS must define the x-cloak guard.');

        // Admin zone, so /admin/users renders rather than redirecting.
        $this->loginAs($this->seedWebUser(['email' => 'ui-xcloak@example.com', 'zone' => 'admin']));

        foreach (['/dashboard', '/admin/users'] as $page) {
            $body = (string) $this->withSession()->get($page)->response()->getBody();
            $this->assertStringContainsString('/css/app.css', $body, $page . ' must load the compiled stylesheet.');
        }

        session()->destroy();

        // The standalone auth templates do not extend the layout, so they are the
        // ones that historically drifted.
        foreach ([['/login', ['basicAuthAuthenticated' => 1]], ['/basic-auth', []]] as [$page, $session]) {
            $result = $session === [] ? $this->get($page) : $this->withSession($session)->get($page);
            $result->assertStatus(200);
            $this->assertStringContainsString('/css/app.css', (string) $result->response()->getBody(), $page . ' must load the compiled stylesheet.');
        }
    }

    public function testDashboardShipsDarkModePrePaintScriptAndToggle(): void
    {
        $client = $this->seedWebUser(['email' => 'ui-darkmode@example.com']);
        $this->loginAs($client);

        $result = $this->withSession()->get('/dashboard');

        $result->assertStatus(200);
        $body = (string) $result->response()->getBody();
        $this->assertStringContainsString('localStorage', $body);
        $this->assertStringContainsString('documentElement.classList', $body);
        // darkMode is compiled into /css/app.css now (no inline tailwind.config).
        $this->assertStringContainsString('/css/app.css', $body);
        $this->assertStringContainsString('Toggle dark mode', $body);
    }

    public function testDashboardRendersGroupedSidebarSectionHeaders(): void
    {
        $admin = $this->seedWebUser(['email' => 'ui-groups-admin@example.com', 'zone' => 'admin']);
        $this->loginAs($admin);

        $result = $this->withSession()->get('/dashboard');

        $result->assertStatus(200);
        $result->assertSee('Dashboard');
        $result->assertSee('Account');
        $result->assertSee('Admin');
    }

    public function testClientDashboardRendersGroupsWithoutAdminHeader(): void
    {
        $client = $this->seedWebUser(['email' => 'ui-groups-client@example.com', 'zone' => 'user']);
        $this->loginAs($client);

        $result = $this->withSession()->get('/dashboard');

        $result->assertStatus(200);
        $result->assertSee('Dashboard');
        $result->assertSee('Account');
        $body = (string) $result->response()->getBody();
        $this->assertStringNotContainsString('>Admin<', $body);
    }

    public function testDashboardAndLoginReferenceTheFaviconLink(): void
    {
        $client = $this->seedWebUser(['email' => 'ui-favicon@example.com']);
        $this->loginAs($client);

        $dashboard = $this->withSession()->get('/dashboard');
        $dashboard->assertStatus(200);
        $this->assertStringContainsString('rel="icon" href="/favicon.svg"', (string) $dashboard->response()->getBody());

        session()->destroy();
        $login = $this->withSession(['basicAuthAuthenticated' => 1])->get('/login');
        $login->assertStatus(200);
        $this->assertStringContainsString('rel="icon" href="/favicon.svg"', (string) $login->response()->getBody());
    }

    /**
     * `favicon.ico`/`favicon.svg` are static files under `public/` served by
     * the web server (front-controller passthrough), not by a CI4 route —
     * FeatureTestTrait dispatches straight through the Router and has no
     * such passthrough, so a direct `GET /favicon.svg` here 404s regardless
     * of the real static asset being served in production. Assert the file
     * exists on disk instead, and rely on the `<link>` assertion above +
     * manual/e2e smoke (plan §6) for the actual HTTP 200.
     */
    public function testFaviconAssetsExistOnDisk(): void
    {
        $this->assertFileExists(FCPATH . 'favicon.svg');
        $this->assertFileExists(FCPATH . 'favicon.ico');
    }

    public function testAdminUsersFilterClearIsARealButtonNotALink(): void
    {
        $admin = $this->seedWebUser(['email' => 'ui-clear-admin@example.com', 'zone' => 'admin']);
        $this->loginAs($admin);

        $result = $this->withSession()->get('/admin/users');

        $result->assertStatus(200);
        $body = (string) $result->response()->getBody();
        $this->assertMatchesRegularExpression('/<button[^>]*>\s*Clear\s*<\/button>/', $body);
        $this->assertDoesNotMatchRegularExpression('/<a[^>]*>\s*Clear\s*<\/a>/', $body);
    }

    public function testGuestIsStillRedirectedToLoginFromDashboard(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $result = $this->get('/dashboard');

        $result->assertRedirectTo('/login');
    }
}
