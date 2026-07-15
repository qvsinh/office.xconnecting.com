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
 * Client-zone API connection self-test (`Client\ApiConnectionTest`), run
 * through real routing + filters (`authFilter`) + CSRF. The loopback HTTP
 * call is mocked via `service('curlrequest')` (`FakeCurlRequest`) — no real
 * network call is ever made.
 *
 * @internal
 */
final class ClientApiConnectionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use WebAuthFixture;
    use ApiKeyFixture;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    private const CNR_SERVICE_ID = 800;

    protected function tearDown(): void
    {
        parent::tearDown();
        Services::resetSingle('curlrequest');
        session()->destroy();
    }

    public function testIndexRendersWithOwnKeyList(): void
    {
        $user = $this->seedWebUser(['email' => 'api-test-client-index@example.com', 'zone' => 'client']);
        $this->seedApiKey($user->id, 0, self::CNR_SERVICE_ID);

        $this->loginAs($user);
        $result = $this->withSession()->get('/api-test');

        $result->assertStatus(200);
    }

    public function testRunReturnsTheProbeEnvelopeAndNeverLeaksTheToken(): void
    {
        $user = $this->seedWebUser(['email' => 'api-test-client-run@example.com', 'zone' => 'client']);
        $key  = $this->seedApiKey($user->id, 0, self::CNR_SERVICE_ID);

        $fake = new FakeCurlRequest();
        $fake->queue(new FakeCurlResponse(json_encode(['status' => 'success', 'data' => ['rows' => []]]), 200));
        Services::injectMock('curlrequest', $fake);

        $this->loginAs($user);
        $result = $this->withSession()->post('/api-test/run', $this->withCsrf(['key_id' => $key['id']]));

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertTrue($json['result']['ok']);
        $this->assertSame(200, $json['result']['http_status']);
        $this->assertArrayHasKey('csrf_hash', $json);

        $this->assertStringNotContainsString($key['token'], $result->getJSON());
        $this->assertSame(1, count($fake->calls));
        $this->assertSame('Bearer ' . $key['token'], $fake->calls[0]['options']['headers']['Authorization']);
    }

    public function testRunReportsA401ForADisabledKeyWithoutTreatingItAsNotFound(): void
    {
        $user = $this->seedWebUser(['email' => 'api-test-client-disabled@example.com', 'zone' => 'client']);
        $key  = $this->seedApiKey($user->id, 0, self::CNR_SERVICE_ID, ['is_enable' => 0]);

        $fake = new FakeCurlRequest();
        $fake->queue(new FakeCurlResponse(json_encode(['status' => 'error', 'message' => 'Unauthorized']), 401));
        Services::injectMock('curlrequest', $fake);

        $this->loginAs($user);
        $result = $this->withSession()->post('/api-test/run', $this->withCsrf(['key_id' => $key['id']]));

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('success', $json['status']); // the self-test itself ran fine
        $this->assertFalse($json['result']['ok']);
        $this->assertSame(401, $json['result']['http_status']);
    }

    public function testClientCannotRunAnotherUsersKey(): void
    {
        $owner    = $this->seedWebUser(['email' => 'api-test-owner@example.com', 'zone' => 'client']);
        $intruder = $this->seedWebUser(['email' => 'api-test-intruder@example.com', 'zone' => 'client']);
        $key      = $this->seedApiKey($owner->id, 0, self::CNR_SERVICE_ID);

        $this->loginAs($intruder);
        $result = $this->withSession()->post('/api-test/run', $this->withCsrf(['key_id' => $key['id']]));

        $result->assertStatus(404);
    }

    public function testMissingKeyIdReturns400(): void
    {
        $user = $this->seedWebUser(['email' => 'api-test-client-missing@example.com', 'zone' => 'client']);

        $this->loginAs($user);
        $result = $this->withSession()->post('/api-test/run', $this->withCsrf([]));

        $result->assertStatus(400);
    }

    public function testGuestIsRedirectedToLogin(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);
        $this->get('/api-test')->assertRedirectTo('/login');

        $this->withSession(['basicAuthAuthenticated' => 1]);
        $this->post('/api-test/run', $this->withCsrf(['key_id' => 1]))->assertRedirectTo('/login');
    }
}
