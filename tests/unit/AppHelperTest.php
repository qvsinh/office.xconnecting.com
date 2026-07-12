<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class AppHelperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('app');
    }

    public function testGetHashidsRoundTripsEncodeDecode(): void
    {
        $hashids = get_hashids(32);
        $encoded = $hashids->encode(1, 1, 800);

        $this->assertSame(32, strlen($encoded));
        $this->assertSame([1, 1, 800], $hashids->decode($encoded));
    }
}
