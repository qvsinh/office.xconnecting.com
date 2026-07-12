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
 * JPRS (.jp) proxy: command validation, GET+POST param parity, upstream
 * success/error mapping, missing-config handling, api_history logging, and
 * the `command=History` shortcut. Upstream is mocked via a fake
 * `curlrequest` service — no real network/client-cert call is made.
 *
 * Uses a synthetic `jprs.9801.*` service config (env vars set/unset per
 * test) pointing at the placeholder cert fixtures under
 * `writable/app_files/jprs.certs/test/`, so `buildCombinedPem()` finds real
 * (dummy) files to read without touching the real `.env`-configured
 * jprs.80x services.
 *
 * @internal
 */
final class JprsApiProxyTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ApiKeyFixture;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    private const TEST_SERVICE_ID = 9801;

    protected function setUp(): void
    {
        parent::setUp();

        putenv('jprs.' . self::TEST_SERVICE_ID . '.service_api_id=jprs.' . self::TEST_SERVICE_ID);
        putenv('jprs.' . self::TEST_SERVICE_ID . '.url=https://jprs.example.test/gate');
        putenv('jprs.' . self::TEST_SERVICE_ID . '.AgentLoginID=TESTAGENT');
        putenv('jprs.' . self::TEST_SERVICE_ID . '.AgentLoginPassword=TESTPASS');
        putenv('jprs.' . self::TEST_SERVICE_ID . '.pem_cert_path=test/dummy.crt');
        putenv('jprs.' . self::TEST_SERVICE_ID . '.pem_key_path=test/dummy.key');
        putenv('jprs.' . self::TEST_SERVICE_ID . '.pem_key_pass=');
        putenv('jprs.' . self::TEST_SERVICE_ID . '.ssl_verify=no');
    }

    protected function tearDown(): void
    {
        foreach (['service_api_id', 'url', 'AgentLoginID', 'AgentLoginPassword', 'pem_cert_path', 'pem_key_path', 'pem_key_pass', 'ssl_verify'] as $key) {
            putenv('jprs.' . self::TEST_SERVICE_ID . '.' . $key);
        }

        Services::resetSingle('curlrequest');
        parent::tearDown();
    }

    public function testMissingCommandReturns400(): void
    {
        $key = $this->seedApiKey(1, 0, self::TEST_SERVICE_ID);

        $result = $this->withHeaders(['Authorization' => 'Bearer ' . $key['token']])
            ->get('/api/jprs/testing');

        $result->assertStatus(400);
        $result->assertJSONFragment(['status' => 'error']);
    }

    public function testMissingConfigReturns400(): void
    {
        // An unconfigured service id -> AgentLoginID/AgentLoginPassword/url all empty.
        $key = $this->seedApiKey(1, 0, 8801);

        $result = $this->withHeaders(['Authorization' => 'Bearer ' . $key['token']])
            ->get('/api/jprs/testing?Command=StatusDomain');

        $result->assertStatus(400);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('error', $json['status']);
        $this->assertStringContainsString('Configuration', $json['message']);
    }

    public function testUpstreamSuccessMapsToStatusSuccess(): void
    {
        $key = $this->seedApiKey(1, 0, self::TEST_SERVICE_ID);
        $fake = new FakeCurlRequest();
        $fake->queue(new FakeCurlResponse("DomainName:example.jp\n200 Command completed successfully\n"));
        Services::injectMock('curlrequest', $fake);

        $result = $this->withHeaders(['Authorization' => 'Bearer ' . $key['token']])
            ->get('/api/jprs/testing?Command=StatusDomain&DomainName=example.jp');

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertSame('jprs.' . self::TEST_SERVICE_ID, $json['api_service_id']);
        $this->assertSame('200', $json['data']['code']);
        $this->assertSame('example.jp', $json['data']['data']['DomainName']);
    }

    public function testUpstreamErrorMapsToStatusError(): void
    {
        $key = $this->seedApiKey(1, 0, self::TEST_SERVICE_ID);
        $fake = new FakeCurlRequest();
        $fake->queue(new FakeCurlResponse("500 Internal Server Error\n"));
        Services::injectMock('curlrequest', $fake);

        $result = $this->withHeaders(['Authorization' => 'Bearer ' . $key['token']])
            ->get('/api/jprs/testing?Command=StatusDomain');

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('error', $json['status']);
        $this->assertSame('500', $json['data']['code']);
        $this->assertSame('Internal Server Error', $json['message']);
    }

    public function testGetAndPostProduceTheSameResult(): void
    {
        $key = $this->seedApiKey(1, 0, self::TEST_SERVICE_ID);
        $fake = new FakeCurlRequest();
        $fake->queue(new FakeCurlResponse("DomainName:example.jp\n200 Command completed successfully\n"));
        $fake->queue(new FakeCurlResponse("DomainName:example.jp\n200 Command completed successfully\n"));
        Services::injectMock('curlrequest', $fake);

        $getResult = $this->withHeaders(['Authorization' => 'Bearer ' . $key['token']])
            ->get('/api/jprs/testing?Command=StatusDomain&DomainName=example.jp');

        $postResult = $this->withHeaders(['Authorization' => 'Bearer ' . $key['token']])
            ->post('/api/jprs/testing', ['Command' => 'StatusDomain', 'DomainName' => 'example.jp']);

        $getResult->assertStatus(200);
        $postResult->assertStatus(200);
        $this->assertSame(
            json_decode($getResult->getJSON(), true),
            json_decode($postResult->getJSON(), true),
        );
        $this->assertCount(2, $fake->calls, 'both a GET and a POST upstream call should have been made');
    }

    public function testLoggingOnInsertsHistoryRowWithStrippedFields(): void
    {
        $key = $this->seedApiKey(1, 0, self::TEST_SERVICE_ID, ['enable_log' => 1]);
        $fake = new FakeCurlRequest();
        $fake->queue(new FakeCurlResponse("DomainName:example.jp\n200 Command completed successfully\n"));
        Services::injectMock('curlrequest', $fake);

        $this->withHeaders(['Authorization' => 'Bearer ' . $key['token']])
            ->get('/api/jprs/testing?Command=StatusDomain&DomainName=example.jp');

        $row = model(ApiHistoryModel::class)
            ->where('service_name', 'jprs')
            ->where('service_id', self::TEST_SERVICE_ID)
            ->where('command', 'StatusDomain')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('example.jp', $row['domain']);
        $this->assertSame(1, (int) $row['status']);
        $this->assertStringNotContainsString('TESTAGENT', $row['params']);
        $this->assertStringNotContainsString('TESTPASS', $row['params']);
        $this->assertStringNotContainsString('AgentLoginID', $row['params']);
        $this->assertStringNotContainsString('OperationPassword', $row['params']);
    }

    public function testLoggingOffInsertsNoHistoryRow(): void
    {
        $key = $this->seedApiKey(1, 0, self::TEST_SERVICE_ID, ['enable_log' => 0]);
        $fake = new FakeCurlRequest();
        $fake->queue(new FakeCurlResponse("200 OK\n"));
        Services::injectMock('curlrequest', $fake);

        $this->withHeaders(['Authorization' => 'Bearer ' . $key['token']])
            ->get('/api/jprs/testing?Command=StatusDomain');

        $count = model(ApiHistoryModel::class)->where('service_name', 'jprs')->countAllResults();
        $this->assertSame(0, $count);
    }

    public function testCommandHistoryReturnsPaginatedEnvelopeScopedToService(): void
    {
        $key = $this->seedApiKey(1, 0, self::TEST_SERVICE_ID);

        model(ApiHistoryModel::class)->insert([
            'env' => 'TESTING', 'service_name' => 'jprs', 'service_id' => self::TEST_SERVICE_ID, 'user_id' => 1,
            'command' => 'StatusDomain', 'domain' => 'example.jp', 'params' => '{}', 'response' => '200 OK',
            'status' => 1,
        ]);
        model(ApiHistoryModel::class)->insert([
            'env' => 'TESTING', 'service_name' => 'jprs', 'service_id' => 9802, 'user_id' => 1,
            'command' => 'StatusDomain', 'domain' => 'other.jp', 'params' => '{}', 'response' => '200 OK',
            'status' => 1,
        ]);

        $result = $this->withHeaders(['Authorization' => 'Bearer ' . $key['token']])
            ->get('/api/jprs/testing?Command=History');

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertCount(1, $json['data']['rows']);
        $this->assertSame('example.jp', $json['data']['rows'][0]['domain']);
    }
}
