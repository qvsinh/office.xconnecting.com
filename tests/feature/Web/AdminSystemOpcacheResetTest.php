<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * `POST /admin/system/opcache/reset` (`Admin\System::resetOpcache`) — the
 * one state-changing endpoint added by the runtime-details panel. Gated by
 * `authFilter` + `adminFilter` + `csrf` exactly like every other admin
 * mutation route; graceful "OPcache is not enabled" failure (never a fatal)
 * when the extension isn't loaded in this SAPI, which is the common case
 * under PHPUnit's CLI runner.
 *
 * @internal
 */
final class AdminSystemOpcacheResetTest extends CIUnitTestCase
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

    public function testGuestIsRedirectedToLogin(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $this->post('/admin/system/opcache/reset', $this->withCsrf([]))->assertRedirectTo('/login');
    }

    public function testNonAdminClientIsRejectedByAdminGate(): void
    {
        $client = $this->seedWebUser(['email' => 'gate-opcache-client@example.com', 'zone' => 'client']);
        $this->loginAs($client);

        $this->withSession()->post('/admin/system/opcache/reset', $this->withCsrf([]))->assertRedirectTo('/dashboard');
    }

    public function testMissingCsrfTokenIsRejected(): void
    {
        $admin = $this->seedWebUser(['email' => 'admin-opcache-nocsrf@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $result = $this->withSession()->post('/admin/system/opcache/reset', []);

        $result->assertRedirect(); // Security::$redirect === true -> CSRF failure redirects, not 403
    }

    /**
     * `opcache_reset` is commonly compiled in but disabled for the CLI SAPI
     * (`opcache.enable_cli=0`), which PHPUnit runs under — so this asserts
     * whichever of the three graceful branches actually fires here, never a
     * fatal: function missing entirely, function present but the reset call
     * itself returns false, or a real success.
     */
    public function testAdminResetHandlesEveryOpcacheAvailabilityBranchGracefully(): void
    {
        $admin = $this->seedWebUser(['email' => 'admin-opcache-reset@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $result = $this->withSession()->post('/admin/system/opcache/reset', $this->withCsrf([]));
        $json   = json_decode($result->getJSON(), true);

        if (! function_exists('opcache_reset')) {
            $result->assertStatus(400);
            $this->assertSame('failed', $json['status']);
            $this->assertSame('OPcache is not enabled.', $json['message']);

            return;
        }

        if (opcache_reset()) {
            $result->assertStatus(200);
            $this->assertSame('success', $json['status']);
        } else {
            $result->assertStatus(500);
            $this->assertSame('failed', $json['status']);
        }
    }
}
