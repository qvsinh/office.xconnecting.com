<?php

use App\Models\ApiAccessKeyModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Api\ApiKeyFixture;
use Tests\Support\Web\WebAuthFixture;

/**
 * Admin-zone API connection self-test (`Admin\ApiConnectionTest`), run
 * through real routing + filters (`authFilter`/`adminFilter`). The actual
 * probe now runs as a `fetch()` from the caller's own browser against the
 * public `/api/{provider}/{env}/` proxy — there is no server-side `run()`
 * step left to test here, only that `index()` renders with the right key
 * scope and the zone is gated correctly.
 *
 * @internal
 */
final class AdminApiConnectionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use WebAuthFixture;
    use ApiKeyFixture;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    private const JPRS_SERVICE_ID = 801;

    protected function tearDown(): void
    {
        parent::tearDown();
        session()->destroy();
    }

    public function testIndexRendersWithEveryUsersKeyList(): void
    {
        $admin = $this->seedWebUser(['email' => 'api-test-admin-index@example.com', 'zone' => 'global']);
        $owner = $this->seedWebUser(['email' => 'api-test-admin-owner@example.com', 'zone' => 'client']);
        $key   = $this->seedApiKey($owner->id, 0, self::JPRS_SERVICE_ID);

        $this->loginAs($admin);
        $result = $this->withSession()->get('/admin/api-test');

        $result->assertStatus(200);

        // Admin zone lists every user's keys (unlike the client zone).
        $keys = model(ApiAccessKeyModel::class)->listForConnectionTest(null);
        $this->assertContains($key['token'], array_column($keys, 'access_key'));
    }

    public function testNonAdminSessionIsRedirectedToDashboard(): void
    {
        $client = $this->seedWebUser(['email' => 'api-test-admin-gate@example.com', 'zone' => 'client']);

        $this->loginAs($client);
        $result = $this->withSession()->get('/admin/api-test');

        $result->assertRedirectTo('/dashboard');
    }

    public function testGuestIsRedirectedToLogin(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);
        $this->get('/admin/api-test')->assertRedirectTo('/login');
    }
}
