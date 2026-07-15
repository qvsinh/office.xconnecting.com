<?php

use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Api\ApiKeyFixture;
use Tests\Support\Api\FakeCurlRequest;
use Tests\Support\Api\FakeCurlResponse;
use Tests\Support\Web\WebAuthFixture;

/**
 * Admin-zone API connection self-test (`Admin\ApiConnectionTest`), run
 * through real routing + filters (`authFilter`/`adminFilter`) + CSRF. The
 * loopback HTTP call is mocked via `service('curlrequest')`
 * (`FakeCurlRequest`) — no real network call is ever made.
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
        Services::resetSingle('curlrequest');
        session()->destroy();
    }

    public function testIndexRendersWithEveryUsersKeyList(): void
    {
        $admin = $this->seedWebUser(['email' => 'api-test-admin-index@example.com', 'zone' => 'global']);
        $owner = $this->seedWebUser(['email' => 'api-test-admin-owner@example.com', 'zone' => 'client']);
        $this->seedApiKey($owner->id, 0, self::JPRS_SERVICE_ID);

        $this->loginAs($admin);
        $result = $this->withSession()->get('/admin/api-test');

        $result->assertStatus(200);
    }

    public function testAdminCanRunAnotherUsersKeyAndReceivesTheProbeEnvelope(): void
    {
        $admin = $this->seedWebUser(['email' => 'api-test-admin-run@example.com', 'zone' => 'global']);
        $owner = $this->seedWebUser(['email' => 'api-test-admin-run-owner@example.com', 'zone' => 'client']);
        $key   = $this->seedApiKey($owner->id, 1, self::JPRS_SERVICE_ID, ['user_status' => 1]);

        $fake = new FakeCurlRequest();
        $fake->queue(new FakeCurlResponse(json_encode(['status' => 'success', 'data' => ['rows' => []]]), 200));
        Services::injectMock('curlrequest', $fake);

        $this->loginAs($admin);
        $result = $this->withSession()->post('/admin/api-test/run', $this->withCsrf(['key_id' => $key['id']]));

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertTrue($json['result']['ok']);
        $this->assertStringContainsString('/api/jprs/live/', $json['result']['endpoint']);
        $this->assertStringNotContainsString($key['token'], $result->getJSON());
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
