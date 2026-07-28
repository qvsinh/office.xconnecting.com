<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * View-rendering coverage for the four runtime panels appended to
 * `admin/system/info` (OPcache, PHP Configuration, Server Information, PHP
 * Extensions). `Admin\System::buildRuntimeDetails()` itself (no-secrets,
 * no-path, shape) is already covered by AdminSystemRuntimeDetailsTest
 * (unit) and the reset endpoint's gating by AdminSystemOpcacheResetTest
 * (feature) — this suite closes the last gap: does the *rendered HTML*
 * actually show the four panels, stay secret/path-free on the whole (now
 * larger) page, keep Extensions collapsed by default, and only offer the
 * Reset OPcache control when OPcache is actually available in this SAPI.
 *
 * @internal
 */
final class AdminSystemInfoRuntimeViewTest extends CIUnitTestCase
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

    public function testAdminSeesAllFourRuntimePanels(): void
    {
        $admin = $this->seedWebUser(['email' => 'view-runtime-panels@example.com', 'zone' => 'admin']);
        $this->loginAs($admin);

        $result = $this->withSession()->get('/admin/system');

        $result->assertStatus(200);
        $result->assertSee('OPcache');
        $result->assertSee('PHP configuration');
        $result->assertSee('memory_limit');
        $result->assertSee('Server information');
        $result->assertSee('PHP extensions');

        $body = (string) $result->response()->getBody();
        $opcacheAvailable = function_exists('opcache_get_status') && opcache_get_status(false) !== false;

        if ($opcacheAvailable) {
            $this->assertStringContainsString('Status', $body);
        } else {
            $this->assertStringContainsString('OPcache is not enabled on this server.', $body);
        }
    }

    public function testRuntimePanelsRenderedHtmlContainsNoKnownSecretOrAbsolutePath(): void
    {
        $admin = $this->seedWebUser(['email' => 'view-runtime-nosecret@example.com', 'zone' => 'admin']);
        $this->loginAs($admin);

        $result = $this->withSession()->get('/admin/system');
        $result->assertStatus(200);
        $body = (string) $result->response()->getBody();

        $secrets = [
            getenv('database.tests.password'),
            getenv('database.default.password'),
            getenv('hashid_salt'),
            getenv('encryption.key'),
            getenv('app.basic.auth.password'),
        ];

        foreach ($secrets as $secret) {
            if ($secret === false || $secret === '') {
                continue;
            }

            $this->assertStringNotContainsString((string) $secret, $body);
        }

        $this->assertStringNotContainsString(APPPATH, $body);
        $this->assertStringNotContainsString(ROOTPATH, $body);
        $this->assertStringNotContainsString(WRITEPATH, $body);
    }

    public function testExtensionsListIsCollapsedByDefaultViaAlpineCloak(): void
    {
        $admin = $this->seedWebUser(['email' => 'view-runtime-extensions@example.com', 'zone' => 'admin']);
        $this->loginAs($admin);

        $result = $this->withSession()->get('/admin/system');
        $result->assertStatus(200);
        $body = (string) $result->response()->getBody();

        $this->assertStringContainsString('x-show="extensionsOpen"', $body);
        $this->assertStringContainsString('x-cloak', $body);
        // Alpine state initialises collapsed; no server-side `open`/`show` override.
        $this->assertStringContainsString('extensionsOpen: false', $body);
    }

    /**
     * `opcache_reset` is commonly compiled in but disabled for the CLI SAPI,
     * which PHPUnit runs under — branch on real availability like
     * AdminSystemRuntimeDetailsTest / AdminSystemOpcacheResetTest do, never
     * assume it's loaded.
     */
    public function testResetOpcacheControlOnlyRendersWhenOpcacheIsAvailable(): void
    {
        $admin = $this->seedWebUser(['email' => 'view-runtime-opcache-btn@example.com', 'zone' => 'admin']);
        $this->loginAs($admin);

        $result = $this->withSession()->get('/admin/system');
        $result->assertStatus(200);
        $body = (string) $result->response()->getBody();

        $opcacheAvailable = function_exists('opcache_get_status') && opcache_get_status(false) !== false;

        // "Reset OPcache" text alone isn't a reliable marker: it also appears
        // unconditionally inside the page's static confirm-dialog JS. The
        // conditional button markup (`@click="confirmResetOpcache()"`) only
        // renders when `$runtime['opcache']['available']` is true.
        if ($opcacheAvailable) {
            $this->assertStringContainsString('@click="confirmResetOpcache()"', $body);
        } else {
            $this->assertStringNotContainsString('@click="confirmResetOpcache()"', $body);
            $this->assertStringContainsString('OPcache is not enabled on this server.', $body);
        }

        // The endpoint itself is always referenced by the shared postForm()
        // call in the page script, regardless of availability.
        $this->assertStringContainsString('/admin/system/opcache/reset', $body);
    }

    public function testGuestIsRedirectedToLogin(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $this->get('/admin/system')->assertRedirectTo('/login');
    }

    public function testNonAdminClientIsBlockedByAdminGate(): void
    {
        $client = $this->seedWebUser(['email' => 'view-runtime-gate-client@example.com', 'zone' => 'user']);
        $this->loginAs($client);

        $this->withSession()->get('/admin/system')->assertRedirectTo('/dashboard');
    }
}
