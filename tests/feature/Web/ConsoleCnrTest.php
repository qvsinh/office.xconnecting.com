<?php

use App\Models\ApiHistoryModel;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Api\ApiKeyFixture;
use Tests\Support\Console\FakeCnrClient;
use Tests\Support\Web\WebAuthFixture;

/**
 * Web developer console for CNR (`Client\ConsoleCnr` / `Admin\ConsoleCnr`),
 * run through real routing + filters (`authFilter`/`adminFilter`) + CSRF.
 * The upstream call is mocked via `service('cnrClient')` (`FakeCnrClient`) —
 * these tests are transport/ownership/identity-derivation, not the shared
 * client's own upstream/logging behavior (covered by CnrClientTest).
 *
 * @internal
 */
final class ConsoleCnrTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use WebAuthFixture;
    use ApiKeyFixture;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    private const CNR_SERVICE_ID = 800;

    private FakeCnrClient $fakeClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeClient = new FakeCnrClient();
        Services::injectMock('cnrClient', $this->fakeClient);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Services::resetSingle('cnrClient');
        session()->destroy();
    }

    public function testClientRunHappyPathReturnsProxyEnvelopeAndDerivesIdentityFromKeyRow(): void
    {
        $user = $this->seedWebUser(['email' => 'console-cnr-a@example.com', 'zone' => 'client']);
        $key  = $this->seedApiKey($user->id, 1, self::CNR_SERVICE_ID, ['user_status' => 1]);

        $this->loginAs($user);
        $result = $this->withSession()->post('/console/cnr/run', $this->withCsrf([
            'key_id'  => $key['id'],
            'command' => 'StatusAccount',
            'params'  => ['domain' => 'example.com'],
        ]));

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertSame('cnr.800', $json['api_service_id']);
        $this->assertSame('200', $json['data']['code']);
        $this->assertArrayHasKey('csrf_hash', $json);

        $this->assertCount(1, $this->fakeClient->calls);
        $call = $this->fakeClient->calls[0];
        $this->assertSame(self::CNR_SERVICE_ID, $call['service_id']);
        $this->assertSame('LIVE', $call['env']); // derived from the key's user_status, not request input
        $this->assertSame((int) $user->id, $call['meta']['user_id']);
        $this->assertSame(['domain' => 'example.com'], $call['params']);
    }

    public function testMissingCommandReturns400WithoutCallingUpstream(): void
    {
        $user = $this->seedWebUser(['email' => 'console-cnr-b@example.com', 'zone' => 'client']);
        $key  = $this->seedApiKey($user->id, 0, self::CNR_SERVICE_ID);

        $this->loginAs($user);
        $result = $this->withSession()->post('/console/cnr/run', $this->withCsrf([
            'key_id'  => $key['id'],
            'command' => '',
        ]));

        $result->assertStatus(400);
        $this->assertSame('error', json_decode($result->getJSON(), true)['status']);
        $this->assertCount(0, $this->fakeClient->calls);
    }

    public function testDisabledKeyIsRejectedWithoutCallingUpstream(): void
    {
        $user = $this->seedWebUser(['email' => 'console-cnr-c@example.com', 'zone' => 'client']);
        $key  = $this->seedApiKey($user->id, 0, self::CNR_SERVICE_ID, ['is_enable' => 0]);

        $this->loginAs($user);
        $result = $this->withSession()->post('/console/cnr/run', $this->withCsrf([
            'key_id'  => $key['id'],
            'command' => 'StatusAccount',
        ]));

        $result->assertStatus(400);
        $this->assertCount(0, $this->fakeClient->calls);
    }

    public function testClientCannotRunAnotherUsersKeyAndNoUpstreamCallIsMade(): void
    {
        $owner  = $this->seedWebUser(['email' => 'console-cnr-owner@example.com', 'zone' => 'client']);
        $intruder = $this->seedWebUser(['email' => 'console-cnr-intruder@example.com', 'zone' => 'client']);
        $key    = $this->seedApiKey($owner->id, 0, self::CNR_SERVICE_ID);

        $this->loginAs($intruder);
        $result = $this->withSession()->post('/console/cnr/run', $this->withCsrf([
            'key_id'  => $key['id'],
            'command' => 'StatusAccount',
        ]));

        $result->assertStatus(404);
        $this->assertCount(0, $this->fakeClient->calls);
    }

    public function testClientCannotRunAJprsKeyThroughTheCnrConsole(): void
    {
        $user = $this->seedWebUser(['email' => 'console-cnr-crossprovider@example.com', 'zone' => 'client']);
        $key  = $this->seedApiKey($user->id, 0, 801); // jprs.801 in .env, not a cnr service

        $this->loginAs($user);
        $result = $this->withSession()->post('/console/cnr/run', $this->withCsrf([
            'key_id'  => $key['id'],
            'command' => 'StatusAccount',
        ]));

        $result->assertStatus(404);
        $this->assertCount(0, $this->fakeClient->calls);
    }

    public function testDomainCommandRestrictionReturns403WithoutCallingUpstream(): void
    {
        $user = $this->seedWebUser(['email' => 'console-cnr-d@example.com', 'zone' => 'client']);
        $key  = $this->seedApiKey($user->id, 0, self::CNR_SERVICE_ID);

        $this->loginAs($user);
        $result = $this->withSession()->post('/console/cnr/run', $this->withCsrf([
            'key_id'  => $key['id'],
            'command' => 'ModifyDomain',
            'params'  => ['domain' => 'dnp.sg'],
        ]));

        $result->assertStatus(403);
        $this->assertCount(0, $this->fakeClient->calls);
    }

    public function testCommandHistoryReturnsScopedRowsForTheKeysOwnerAndService(): void
    {
        $user = $this->seedWebUser(['email' => 'console-cnr-e@example.com', 'zone' => 'client']);
        $key  = $this->seedApiKey($user->id, 0, self::CNR_SERVICE_ID);

        model(ApiHistoryModel::class)->insert([
            'env' => 'TESTING', 'service_name' => 'cnr', 'service_id' => self::CNR_SERVICE_ID, 'user_id' => $user->id,
            'command' => 'StatusAccount', 'domain' => null, 'params' => '{}', 'response' => '{"code":200}', 'status' => 1,
        ]);
        // Different service_id must not leak in.
        model(ApiHistoryModel::class)->insert([
            'env' => 'TESTING', 'service_name' => 'cnr', 'service_id' => 900, 'user_id' => $user->id,
            'command' => 'StatusAccount', 'domain' => null, 'params' => '{}', 'response' => '{"code":200}', 'status' => 1,
        ]);

        $this->loginAs($user);
        $result = $this->withSession()->post('/console/cnr/run', $this->withCsrf([
            'key_id'  => $key['id'],
            'command' => 'History',
        ]));

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertCount(1, $json['data']['rows']);
        $this->assertCount(0, $this->fakeClient->calls); // History never touches the upstream client
    }

    public function testMutationWithoutCsrfTokenIsRejected(): void
    {
        $user = $this->seedWebUser(['email' => 'console-cnr-f@example.com', 'zone' => 'client']);
        $key  = $this->seedApiKey($user->id, 0, self::CNR_SERVICE_ID);

        $this->loginAs($user);
        $result = $this->withSession()->post('/console/cnr/run', ['key_id' => $key['id'], 'command' => 'StatusAccount']);

        $result->assertRedirect();
        $this->assertCount(0, $this->fakeClient->calls);
    }

    public function testAdminCanRunAnotherUsersKeyAndIdentityIsStillDerivedFromTheKeyRow(): void
    {
        $owner = $this->seedWebUser(['email' => 'console-cnr-owner2@example.com', 'zone' => 'client']);
        $admin = $this->seedWebUser(['email' => 'console-cnr-admin@example.com', 'zone' => 'global']);
        $key   = $this->seedApiKey($owner->id, 1, self::CNR_SERVICE_ID, ['user_status' => 1]);

        $this->loginAs($admin);
        $result = $this->withSession()->post('/admin/console/cnr/run', $this->withCsrf([
            'key_id'  => $key['id'],
            'command' => 'StatusAccount',
        ]));

        $result->assertStatus(200);
        $this->assertSame((int) $owner->id, $this->fakeClient->calls[0]['meta']['user_id']);
        $this->assertSame('LIVE', $this->fakeClient->calls[0]['env']);
    }

    public function testGuestIsRedirectedToLoginFromConsoleRun(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $result = $this->post('/console/cnr/run', $this->withCsrf(['key_id' => 1, 'command' => 'StatusAccount']));

        $result->assertRedirectTo('/login');
        $this->assertCount(0, $this->fakeClient->calls);
    }
}
