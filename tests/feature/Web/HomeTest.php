<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * Public homepage: guests see the branded landing page; authenticated users are
 * redirected to the console appropriate to their zone.
 *
 * @internal
 */
final class HomeTest extends CIUnitTestCase
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

    public function testGuestSeesLandingPage(): void
    {
        $result = $this->withSession(['basicAuthAuthenticated' => 1])->get('/');

        $result->assertStatus(200);
        $result->assertSee('Log in');
    }

    public function testClientIsRedirectedToClientDashboard(): void
    {
        $client = $this->seedWebUser(['email' => 'home-client@example.com', 'zone' => 'client']);
        $this->loginAs($client);

        $this->withSession()->get('/')->assertRedirectTo('/dashboard');
    }

    public function testAdminIsRedirectedToAdminDashboard(): void
    {
        $admin = $this->seedWebUser(['email' => 'home-admin@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $this->withSession()->get('/')->assertRedirectTo('/admin/dashboard');
    }
}
