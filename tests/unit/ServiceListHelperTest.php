<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class ServiceListHelperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('service_config');
    }

    public function testReturnsTheConfiguredCnrAndJprsServices(): void
    {
        // cnr.800.* and jprs.801/802/803.* are set in .env for this
        // project's test fixtures (see ServiceConfigHelperTest).
        $services = list_api_services();
        $ids      = array_column($services, 'id');

        $this->assertContains(800, $ids);
        $this->assertContains(801, $ids);
        $this->assertContains(802, $ids);
        $this->assertContains(803, $ids);
    }

    public function testLabelAndProviderShapeAreCorrectAndCarryNoSecrets(): void
    {
        $services = list_api_services();
        $byId     = [];
        foreach ($services as $service) {
            $byId[$service['id']] = $service;
        }

        $this->assertSame('cnr', $byId[800]['provider']);
        $this->assertSame('CNR 800 (cnr.800)', $byId[800]['label']);

        $this->assertSame('jprs', $byId[801]['provider']);
        $this->assertSame('JPRS 801 (jprs.801)', $byId[801]['label']);

        foreach ($services as $service) {
            $this->assertStringNotContainsString('password', strtolower($service['label']));
            $this->assertStringNotContainsString('pem', strtolower($service['label']));
        }
    }

    public function testResultIsSortedByIdAndDedupedById(): void
    {
        $ids = array_column(list_api_services(), 'id');
        $this->assertSame($ids, array_unique($ids));

        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids);
    }

    public function testSyntheticServiceIsPickedUpAndRemovedCleanly(): void
    {
        // list_api_services() scans $_ENV (see the helper's own docblock for
        // why a bare putenv() alone would not be visible here).
        $_ENV['cnr.9800.service_api_id'] = 'cnr.9800';

        $ids = array_column(list_api_services(), 'id');
        $this->assertContains(9800, $ids);

        unset($_ENV['cnr.9800.service_api_id']);

        $idsAfter = array_column(list_api_services(), 'id');
        $this->assertNotContains(9800, $idsAfter);
    }
}
