<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class ServiceConfigHelperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('service_config');
    }

    public function testKnownCnrIdReturnsConfig(): void
    {
        // cnr.800.* is set in .env for this project's test fixtures
        $cfg = get_cnr_service_config(800);

        $this->assertNotNull($cfg['service_api_id']);
        $this->assertNotNull($cfg['url']);
        $this->assertNotNull($cfg['name']);
        $this->assertNotNull($cfg['password']);
    }

    public function testUnknownCnrIdReturnsNullFilledArray(): void
    {
        $cfg = get_cnr_service_config(999999999);

        $this->assertNull($cfg['service_api_id']);
        $this->assertNull($cfg['url']);
        $this->assertNull($cfg['name']);
        $this->assertNull($cfg['password']);
    }

    public function testKnownJprsIdReturnsConfig(): void
    {
        // jprs.801.* is set in .env for this project's test fixtures
        $cfg = get_jprs_service_config(801);

        $this->assertNotNull($cfg['service_api_id']);
        $this->assertNotNull($cfg['url']);
        $this->assertNotNull($cfg['AgentLoginID']);
        $this->assertNotNull($cfg['AgentLoginPassword']);
    }

    public function testUnknownJprsIdReturnsNullFilledArray(): void
    {
        $cfg = get_jprs_service_config(999999999);

        $this->assertNull($cfg['service_api_id']);
        $this->assertNull($cfg['url']);
        $this->assertNull($cfg['AgentLoginID']);
    }

    public function testGetServiceConfigResolvesKnownCnrId(): void
    {
        $cfg = get_service_config(800);

        $this->assertSame('cnr.800', $cfg['service_api_id']);
        $this->assertNotNull($cfg['url']);
    }

    public function testGetServiceConfigResolvesKnownJprsId(): void
    {
        $cfg = get_service_config(801);

        $this->assertSame('jprs.801', $cfg['service_api_id']);
        $this->assertNotNull($cfg['url']);
    }

    public function testGetServiceConfigUnknownIdReturnsNullFilledArray(): void
    {
        $cfg = get_service_config(999999999);

        $this->assertNull($cfg['service_api_id']);
        $this->assertNull($cfg['url']);
    }
}
