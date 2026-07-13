<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class AuthHelperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('auth');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        session()->destroy();
    }

    public function testIsAuthenticatedIsFalseWithoutSession(): void
    {
        $this->assertFalse(is_authenticated());
    }

    public function testIsAuthenticatedIsTrueWithSession(): void
    {
        session()->set('userAuthenticated', (object) ['zone' => 'client']);

        $this->assertTrue(is_authenticated());
    }

    public function testIsAdminTrueForGlobalZone(): void
    {
        session()->set('userAuthenticated', (object) ['zone' => 'global']);

        $this->assertTrue(is_admin());
        $this->assertFalse(is_client());
    }

    public function testIsClientTrueForClientZone(): void
    {
        session()->set('userAuthenticated', (object) ['zone' => 'client']);

        $this->assertTrue(is_client());
        $this->assertFalse(is_admin());
    }

    public function testIsAdminAndIsClientAreFalseWithoutSession(): void
    {
        $this->assertFalse(is_admin());
        $this->assertFalse(is_client());
    }
}
