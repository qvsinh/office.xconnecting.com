<?php

use App\Models\ApiHistoryModel;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Api\ApiKeyFixture;
use Tests\Support\Api\FakeCurlRequest;
use Tests\Support\Api\FakeCurlResponse;

/**
 * CNR (RRP) proxy: command validation, upstream success/error/exception
 * mapping, api_history logging (on/off + sensitive-field stripping), and
 * the `command=History` shortcut. Upstream is mocked via a fake
 * `curlrequest` service — no real HTTP call is ever made.
 *
 * @internal
 */
final class CnrApiProxyTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ApiKeyFixture;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    protected function tearDown(): void
    {
        parent::tearDown();
        Services::resetSingle('curlrequest');
    }

    public function testMissingCommandReturns400(): void
    {
        $key = $this->seedApiKey(1, 0, 800);

        $result = $this->withHeaders(['Authorization' => 'Bearer ' . $key['token']])
            ->get('/api/cnr/testing');

        $result->assertStatus(400);
        $result->assertJSONFragment(['status' => 'error']);
    }

    public function testUpstreamSuccessMapsToStatusSuccess(): void
    {
        $key = $this->seedApiKey(1, 0, 800);
        $fake = new FakeCurlRequest();
        $fake->queue(new FakeCurlResponse("code=200\ndescription=Command completed successfully\n"));
        Services::injectMock('curlrequest', $fake);

        $result = $this->withHeaders(['Authorization' => 'Bearer ' . $key['token']])
            ->get('/api/cnr/testing?command=StatusAccount');

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertSame('cnr.800', $json['api_service_id']);
        $this->assertSame('200', $json['data']['code']);
    }

    public function testUpstreamErrorMapsToStatusError(): void
    {
        $key = $this->seedApiKey(1, 0, 800);
        $fake = new FakeCurlRequest();
        $fake->queue(new FakeCurlResponse("code=505\ndescription=Object does not exist\n"));
        Services::injectMock('curlrequest', $fake);

        $result = $this->withHeaders(['Authorization' => 'Bearer ' . $key['token']])
            ->get('/api/cnr/testing?command=StatusAccount');

        // Legacy/RRP contract: non-2xx still returns HTTP 200 with an error envelope.
        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('error', $json['status']);
        $this->assertSame('Object does not exist', $json['message']);
    }

    public function testUpstreamExceptionMapsTo500WithoutLeakingTrace(): void
    {
        $key = $this->seedApiKey(1, 0, 800);
        $fake = new FakeCurlRequest();
        $fake->queue(new RuntimeException('Connection timed out'));
        Services::injectMock('curlrequest', $fake);

        $result = $this->withHeaders(['Authorization' => 'Bearer ' . $key['token']])
            ->get('/api/cnr/testing?command=StatusAccount');

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('error', $json['status']);
        $this->assertSame(500, $json['data']['code']);
        $this->assertStringNotContainsString('.php', $json['message'] ?? '');
        $this->assertStringNotContainsString('#0', $json['message'] ?? '');
    }

    public function testLoggingOnInsertsHistoryRowWithStrippedFields(): void
    {
        $key = $this->seedApiKey(1, 0, 800, ['enable_log' => 1]);
        $fake = new FakeCurlRequest();
        $fake->queue(new FakeCurlResponse("code=200\ndescription=OK\n"));
        Services::injectMock('curlrequest', $fake);

        $this->withHeaders(['Authorization' => 'Bearer ' . $key['token']])
            ->get('/api/cnr/testing?command=StatusDomain&domain=example.com&AgentLoginID=leaked&OperationPassword=leaked');

        $row = model(ApiHistoryModel::class)
            ->where('service_name', 'cnr')
            ->where('service_id', 800)
            ->where('command', 'StatusDomain')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('cnr', $row['service_name']);
        $this->assertSame(800, (int) $row['service_id']);
        $this->assertSame(1, (int) $row['status']);
        $this->assertSame('example.com', $row['domain']);
        $this->assertStringNotContainsString('leaked', $row['params']);
        $this->assertStringNotContainsString('AgentLoginID', $row['params']);
        $this->assertStringNotContainsString('OperationPassword', $row['params']);
    }

    public function testLoggingOffInsertsNoHistoryRow(): void
    {
        $key = $this->seedApiKey(1, 0, 800, ['enable_log' => 0]);
        $fake = new FakeCurlRequest();
        $fake->queue(new FakeCurlResponse("code=200\ndescription=OK\n"));
        Services::injectMock('curlrequest', $fake);

        $this->withHeaders(['Authorization' => 'Bearer ' . $key['token']])
            ->get('/api/cnr/testing?command=StatusAccount');

        $count = model(ApiHistoryModel::class)->where('service_name', 'cnr')->countAllResults();
        $this->assertSame(0, $count);
    }

    public function testCommandHistoryReturnsPaginatedEnvelopeScopedToService(): void
    {
        $key = $this->seedApiKey(1, 0, 800);

        model(ApiHistoryModel::class)->insert([
            'env' => 'TESTING', 'service_name' => 'cnr', 'service_id' => 800, 'user_id' => 1,
            'command' => 'StatusAccount', 'domain' => null, 'params' => '{}', 'response' => '{"code":200}',
            'status' => 1,
        ]);
        // A different service_id must not leak into the caller's scoped history.
        model(ApiHistoryModel::class)->insert([
            'env' => 'TESTING', 'service_name' => 'cnr', 'service_id' => 900, 'user_id' => 1,
            'command' => 'StatusAccount', 'domain' => null, 'params' => '{}', 'response' => '{"code":200}',
            'status' => 1,
        ]);

        $result = $this->withHeaders(['Authorization' => 'Bearer ' . $key['token']])
            ->get('/api/cnr/testing?command=History');

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertCount(1, $json['data']['rows']);
        $this->assertSame('StatusAccount', $json['data']['rows'][0]['command']);
    }

    public function testCommandHistoryExcludesSoftDeletedRows(): void
    {
        $key   = $this->seedApiKey(1, 0, 800);
        $model = model(ApiHistoryModel::class);

        $model->insert([
            'env' => 'TESTING', 'service_name' => 'cnr', 'service_id' => 800, 'user_id' => 1,
            'command' => 'StatusAccount', 'domain' => null, 'params' => '{}', 'response' => '{"code":200}',
            'status' => 1,
        ]);
        // A soft-deleted row (same scope) must not surface in History.
        $deletedId = $model->insert([
            'env' => 'TESTING', 'service_name' => 'cnr', 'service_id' => 800, 'user_id' => 1,
            'command' => 'QueryDomain', 'domain' => null, 'params' => '{}', 'response' => '{"code":200}',
            'status' => 1,
        ], true);
        $model->delete($deletedId); // soft-delete → sets deleted_at

        $result = $this->withHeaders(['Authorization' => 'Bearer ' . $key['token']])
            ->get('/api/cnr/testing?command=History');

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertCount(1, $json['data']['rows']);
        $this->assertSame('StatusAccount', $json['data']['rows'][0]['command']);
    }

    public function testDomainCommandRestrictionReturns403(): void
    {
        $key = $this->seedApiKey(1, 0, 800);

        $result = $this->withHeaders(['Authorization' => 'Bearer ' . $key['token']])
            ->get('/api/cnr/testing?command=ModifyDomain&domain=dnp.sg');

        $result->assertStatus(403);
        $result->assertJSONFragment(['status' => 'error']);
    }
}
