<?php

use App\Models\ApiHistoryModel;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Api\ApiKeyFixture;
use Tests\Support\Console\FakeJprsClient;
use Tests\Support\Web\WebAuthFixture;

/**
 * Web developer console for JPRS (`Client\ConsoleJprs` / `Admin\ConsoleJprs`),
 * run through real routing + filters + CSRF. The upstream call is mocked via
 * `service('jprsClient')` (`FakeJprsClient`) — `parseJprsResponse()` runs for
 * real (pure text parsing) so the console's response envelope is exercised
 * end to end. Shared key-resolution/ownership/IDOR behavior is covered
 * jointly with `ConsoleCnrTest`; this suite focuses on the JPRS-specific
 * envelope shape and missing-config path.
 *
 * @internal
 */
final class ConsoleJprsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use WebAuthFixture;
    use ApiKeyFixture;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    private const JPRS_SERVICE_ID = 801;

    private FakeJprsClient $fakeClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeClient = new FakeJprsClient();
        Services::injectMock('jprsClient', $this->fakeClient);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Services::resetSingle('jprsClient');
        session()->destroy();
    }

    public function testClientRunHappyPathReturnsParsedEnvelopeAndDerivesIdentityFromKeyRow(): void
    {
        $user = $this->seedWebUser(['email' => 'console-jprs-a@example.com', 'zone' => 'client']);
        $key  = $this->seedApiKey($user->id, 0, self::JPRS_SERVICE_ID);

        $this->loginAs($user);
        $result = $this->withSession()->post('/console/jprs/run', $this->withCsrf([
            'key_id'  => $key['id'],
            'command' => 'StatusDomain',
            'params'  => ['DomainName' => 'example.jp'],
        ]));

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertSame('jprs.801', $json['api_service_id']);
        $this->assertSame('200', $json['data']['code']);
        $this->assertSame('Command completed successfully', $json['data']['description']);

        $this->assertCount(1, $this->fakeClient->calls);
        $call = $this->fakeClient->calls[0];
        $this->assertSame(self::JPRS_SERVICE_ID, $call['service_id']);
        $this->assertSame('TESTING', $call['env']); // key's user_status defaults to TESTING
        $this->assertSame((int) $user->id, $call['meta']['user_id']);
    }

    public function testMissingConfigMapsTo400WithoutLeakingCredentials(): void
    {
        $user = $this->seedWebUser(['email' => 'console-jprs-b@example.com', 'zone' => 'client']);
        $key  = $this->seedApiKey($user->id, 0, self::JPRS_SERVICE_ID);
        $this->fakeClient->setResponse(false);

        $this->loginAs($user);
        $result = $this->withSession()->post('/console/jprs/run', $this->withCsrf([
            'key_id'  => $key['id'],
            'command' => 'StatusDomain',
        ]));

        $result->assertStatus(400);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('error', $json['status']);
        $this->assertArrayNotHasKey('data', $json);
    }

    public function testMissingCommandReturns400WithoutCallingUpstream(): void
    {
        $user = $this->seedWebUser(['email' => 'console-jprs-c@example.com', 'zone' => 'client']);
        $key  = $this->seedApiKey($user->id, 0, self::JPRS_SERVICE_ID);

        $this->loginAs($user);
        $result = $this->withSession()->post('/console/jprs/run', $this->withCsrf([
            'key_id'  => $key['id'],
            'command' => '',
        ]));

        $result->assertStatus(400);
        $this->assertCount(0, $this->fakeClient->calls);
    }

    public function testDisabledKeyIsRejectedWithoutCallingUpstream(): void
    {
        $user = $this->seedWebUser(['email' => 'console-jprs-d@example.com', 'zone' => 'client']);
        $key  = $this->seedApiKey($user->id, 0, self::JPRS_SERVICE_ID, ['is_enable' => 0]);

        $this->loginAs($user);
        $result = $this->withSession()->post('/console/jprs/run', $this->withCsrf([
            'key_id'  => $key['id'],
            'command' => 'StatusDomain',
        ]));

        $result->assertStatus(400);
        $this->assertCount(0, $this->fakeClient->calls);
    }

    public function testClientCannotRunAnotherUsersKeyAndNoUpstreamCallIsMade(): void
    {
        $owner    = $this->seedWebUser(['email' => 'console-jprs-owner@example.com', 'zone' => 'client']);
        $intruder = $this->seedWebUser(['email' => 'console-jprs-intruder@example.com', 'zone' => 'client']);
        $key      = $this->seedApiKey($owner->id, 0, self::JPRS_SERVICE_ID);

        $this->loginAs($intruder);
        $result = $this->withSession()->post('/console/jprs/run', $this->withCsrf([
            'key_id'  => $key['id'],
            'command' => 'StatusDomain',
        ]));

        $result->assertStatus(404);
        $this->assertCount(0, $this->fakeClient->calls);
    }

    public function testCommandHistoryReturnsScopedRowsForTheKeysOwnerAndService(): void
    {
        $user = $this->seedWebUser(['email' => 'console-jprs-e@example.com', 'zone' => 'client']);
        $key  = $this->seedApiKey($user->id, 0, self::JPRS_SERVICE_ID);

        model(ApiHistoryModel::class)->insert([
            'env' => 'TESTING', 'service_name' => 'jprs', 'service_id' => self::JPRS_SERVICE_ID, 'user_id' => $user->id,
            'command' => 'StatusDomain', 'domain' => null, 'params' => '{}', 'response' => '{"code":"200"}', 'status' => 1,
        ]);

        $this->loginAs($user);
        $result = $this->withSession()->post('/console/jprs/run', $this->withCsrf([
            'key_id'  => $key['id'],
            'command' => 'History',
        ]));

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertCount(1, $json['data']['rows']);
        $this->assertCount(0, $this->fakeClient->calls);
    }

    public function testAdminCanRunAnotherUsersKeyAndIdentityIsStillDerivedFromTheKeyRow(): void
    {
        $owner = $this->seedWebUser(['email' => 'console-jprs-owner2@example.com', 'zone' => 'client']);
        $admin = $this->seedWebUser(['email' => 'console-jprs-admin@example.com', 'zone' => 'global']);
        $key   = $this->seedApiKey($owner->id, 1, self::JPRS_SERVICE_ID, ['user_status' => 1]);

        $this->loginAs($admin);
        $result = $this->withSession()->post('/admin/console/jprs/run', $this->withCsrf([
            'key_id'  => $key['id'],
            'command' => 'StatusDomain',
        ]));

        $result->assertStatus(200);
        $this->assertSame((int) $owner->id, $this->fakeClient->calls[0]['meta']['user_id']);
        $this->assertSame('LIVE', $this->fakeClient->calls[0]['env']);
    }
}
