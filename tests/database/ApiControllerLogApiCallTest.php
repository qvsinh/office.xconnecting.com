<?php

use App\Models\ApiHistoryModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Api\TestApiController;

/**
 * `ApiController::logApiCall()` gating logic, exercised directly on a
 * minimal concrete subclass (see `Tests\Support\Api\TestApiController`) so
 * it can be tested without the full HTTP/auth request lifecycle. Requires a
 * real DB since `logApiCall` writes to `api_history`.
 *
 * @internal
 */
final class ApiControllerLogApiCallTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    private function controller(int $enableLog = 1): TestApiController
    {
        $controller = new TestApiController();
        $controller->setUser(1, 'TESTING');
        $controller->setServiceId(800);
        $controller->setKeyEnableLog($enableLog);

        return $controller;
    }

    public function testReturnsEarlyWithoutInsertingWhenLoggingDisabled(): void
    {
        $this->controller(0)->callLogApiCall([
            'service_name' => 'cnr',
            'command'      => 'StatusAccount',
            'params'       => ['domain' => 'example.com'],
        ]);

        $this->assertSame(0, model(ApiHistoryModel::class)->countAllResults());
    }

    public function testInsertsRowWhenLoggingEnabled(): void
    {
        $this->controller(1)->callLogApiCall([
            'service_name' => 'cnr',
            'command'      => 'StatusAccount',
            'params'       => ['domain' => 'example.com'],
        ]);

        $this->assertSame(1, model(ApiHistoryModel::class)->countAllResults());
    }

    public function testSkipsListingCommandWhenLimitParamPresent(): void
    {
        $this->controller(1)->callLogApiCall([
            'service_name' => 'cnr',
            'command'      => 'QueryDomainList',
            'params'       => ['limit' => 50],
        ]);

        $this->assertSame(0, model(ApiHistoryModel::class)->countAllResults());
    }

    public function testLogsListingCommandWhenLimitParamAbsent(): void
    {
        $this->controller(1)->callLogApiCall([
            'service_name' => 'cnr',
            'command'      => 'QueryDomainList',
            'params'       => ['domain' => 'example.com'],
        ]);

        $this->assertSame(1, model(ApiHistoryModel::class)->countAllResults());
    }

    public function testStripsSensitiveFieldsFromStoredParams(): void
    {
        $this->controller(1)->callLogApiCall([
            'service_name' => 'cnr',
            'command'      => 'ModifyDomain',
            'params'       => [
                'domain'             => 'example.com',
                'AgentLoginID'       => 'secret-agent',
                'OperationPassword'  => 'secret-op-pass',
                'AgentLoginPassword' => 'secret-login-pass',
            ],
        ]);

        $row = model(ApiHistoryModel::class)->first();
        $this->assertNotNull($row);
        $this->assertStringContainsString('example.com', $row['params']);
        $this->assertStringNotContainsString('secret-agent', $row['params']);
        $this->assertStringNotContainsString('secret-op-pass', $row['params']);
        $this->assertStringNotContainsString('secret-login-pass', $row['params']);
        $this->assertStringNotContainsString('AgentLoginID', $row['params']);
        $this->assertStringNotContainsString('OperationPassword', $row['params']);
        $this->assertStringNotContainsString('AgentLoginPassword', $row['params']);
    }

    public function testDerivesDomainFallbackFromDnszoneParamWhenDomainMissing(): void
    {
        $this->controller(1)->callLogApiCall([
            'service_name' => 'cnr',
            'command'      => 'QueryDNSZoneList',
            'params'       => ['dnszone' => 'example.com'],
        ]);

        $row = model(ApiHistoryModel::class)->first();
        $this->assertSame('example.com', $row['domain']);
    }

    public function testExplicitDomainTakesPrecedenceOverDnszone(): void
    {
        $this->controller(1)->callLogApiCall([
            'service_name' => 'cnr',
            'command'      => 'ModifyDomain',
            'domain'       => 'explicit.com',
            'params'       => ['dnszone' => 'other.com'],
        ]);

        $row = model(ApiHistoryModel::class)->first();
        $this->assertSame('explicit.com', $row['domain']);
    }
}
