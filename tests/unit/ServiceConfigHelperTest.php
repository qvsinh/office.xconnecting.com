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
    }

    public function testUnknownIdReturnsNullFilledArray(): void
    {
        $cfg = get_service_config(999999999);

        $this->assertNull($cfg['service_api_id']);
        $this->assertNull($cfg['url']);
    }
}
