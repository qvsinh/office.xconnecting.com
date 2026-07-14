<?php

use App\Models\ApiHistoryModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * `ApiHistoryModel::record()`'s gating logic (`enable_log` gate, listing-
 * command skip, sensitive-field strip, domain fallback) — moved here from
 * `ApiController::logApiCall()` so both `CnrClient` and `JprsClient` share
 * one root instead of duplicating the rules per caller.
 *
 * @internal
 */
final class ApiControllerLogApiCallTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    /**
     * @param array<string, mixed> $row
     */
    private function record(array $row, bool $enableLog): void
    {
        model(ApiHistoryModel::class)->record($row + ['user_id' => 1, 'service_id' => 800], $enableLog);
    }

    public function testReturnsEarlyWithoutInsertingWhenLoggingDisabled(): void
    {
        $this->record([
            'service_name' => 'cnr',
            'command'      => 'StatusAccount',
            'params'       => ['domain' => 'example.com'],
        ], false);

        $this->assertSame(0, model(ApiHistoryModel::class)->countAllResults());
    }

    public function testInsertsRowWhenLoggingEnabled(): void
    {
        $this->record([
            'service_name' => 'cnr',
            'command'      => 'StatusAccount',
            'params'       => ['domain' => 'example.com'],
        ], true);

        $this->assertSame(1, model(ApiHistoryModel::class)->countAllResults());
    }

    public function testSkipsListingCommandWhenLimitParamPresent(): void
    {
        $this->record([
            'service_name' => 'cnr',
            'command'      => 'QueryDomainList',
            'params'       => ['limit' => 50],
        ], true);

        $this->assertSame(0, model(ApiHistoryModel::class)->countAllResults());
    }

    public function testLogsListingCommandWhenLimitParamAbsent(): void
    {
        $this->record([
            'service_name' => 'cnr',
            'command'      => 'QueryDomainList',
            'params'       => ['domain' => 'example.com'],
        ], true);

        $this->assertSame(1, model(ApiHistoryModel::class)->countAllResults());
    }

    public function testStripsSensitiveFieldsFromStoredParams(): void
    {
        $this->record([
            'service_name' => 'cnr',
            'command'      => 'ModifyDomain',
            'params'       => [
                'domain'             => 'example.com',
                'AgentLoginID'       => 'secret-agent',
                'OperationPassword'  => 'secret-op-pass',
                'AgentLoginPassword' => 'secret-login-pass',
            ],
        ], true);

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
        $this->record([
            'service_name' => 'cnr',
            'command'      => 'QueryDNSZoneList',
            'params'       => ['dnszone' => 'example.com'],
        ], true);

        $row = model(ApiHistoryModel::class)->first();
        $this->assertSame('example.com', $row['domain']);
    }

    public function testExplicitDomainTakesPrecedenceOverDnszone(): void
    {
        $this->record([
            'service_name' => 'cnr',
            'command'      => 'ModifyDomain',
            'domain'       => 'explicit.com',
            'params'       => ['dnszone' => 'other.com'],
        ], true);

        $row = model(ApiHistoryModel::class)->first();
        $this->assertSame('explicit.com', $row['domain']);
    }
}
