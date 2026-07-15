<?php

use App\Libraries\ApiConnectionTester;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Api\FakeCurlRequest;
use Tests\Support\Api\FakeCurlResponse;

/**
 * `ApiConnectionTester` builds the loopback probe URL and interprets the
 * result — no real network call is ever made (a `FakeCurlRequest` stands
 * in for `service('curlrequest')`, injected via the constructor).
 *
 * @internal
 */
final class ApiConnectionTesterTest extends CIUnitTestCase
{
    private function key(string $accessKey = 'super-secret-token'): object
    {
        return (object) ['access_key' => $accessKey];
    }

    public function testBuildEndpointForCnrLive(): void
    {
        $tester = new ApiConnectionTester();

        $this->assertSame(
            rtrim(config('App')->baseURL, '/') . '/api/cnr/live/',
            $tester->buildEndpoint('cnr', 'LIVE'),
        );
    }

    public function testBuildEndpointForJprsTesting(): void
    {
        $tester = new ApiConnectionTester();

        $this->assertSame(
            rtrim(config('App')->baseURL, '/') . '/api/jprs/testing/',
            $tester->buildEndpoint('jprs', 'TESTING'),
        );
    }

    public function testSuccessfulProbeIsOkAndNeverLeaksTheToken(): void
    {
        $fake = new FakeCurlRequest();
        $fake->queue(new FakeCurlResponse(json_encode(['status' => 'success', 'data' => ['rows' => []]]), 200));

        $tester = new ApiConnectionTester($fake);
        $result = $tester->probe($this->key(), 'cnr', 'LIVE');

        $this->assertTrue($result['ok']);
        $this->assertSame(200, $result['http_status']);
        $this->assertGreaterThanOrEqual(0, $result['latency_ms']);
        $this->assertSame('success', $result['response']['status']);
        $this->assertNull($result['error']);

        $encoded = json_encode($result);
        $this->assertStringNotContainsString('super-secret-token', $encoded);

        // The header carrying the token, not the URL, is what the fake recorded.
        $call = $fake->calls[0];
        $this->assertStringNotContainsString('super-secret-token', $call['url']);
        $this->assertSame('Bearer super-secret-token', $call['options']['headers']['Authorization']);
    }

    public function testUnauthorizedResponseIsNotOk(): void
    {
        $fake = new FakeCurlRequest();
        $fake->queue(new FakeCurlResponse(json_encode(['status' => 'error', 'message' => 'Unauthorized']), 401));

        $tester = new ApiConnectionTester($fake);
        $result = $tester->probe($this->key(), 'cnr', 'TESTING');

        $this->assertFalse($result['ok']);
        $this->assertSame(401, $result['http_status']);
        $this->assertSame('error', $result['response']['status']);
    }

    public function testTransportExceptionIsCaughtAndReportedWithoutLeakingTheToken(): void
    {
        $fake = new FakeCurlRequest();
        $fake->queue(new RuntimeException('cURL error: could not resolve host super-secret-token-host'));

        $tester = new ApiConnectionTester($fake);
        $result = $tester->probe($this->key(), 'jprs', 'LIVE');

        $this->assertFalse($result['ok']);
        $this->assertSame(0, $result['http_status']);
        $this->assertNull($result['response']);
        $this->assertNotNull($result['error']);
        $this->assertStringNotContainsString('super-secret-token', $result['error']);
    }
}
