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

    public function testCurrentZoneIsClientWithoutSession(): void
    {
        $this->assertSame('client', current_zone());
        $this->assertFalse(is_working_admin());
    }

    public function testCurrentZoneIsGlobalForAuthenticatedAdminNotActingAs(): void
    {
        session()->set('userAuthenticated', (object) ['zone' => 'global']);

        $this->assertSame('global', current_zone());
        $this->assertTrue(is_working_admin());
    }

    public function testCurrentZoneIsClientWhenAdminActsAsClient(): void
    {
        session()->set('userAuthenticated', (object) ['zone' => 'global']);
        session()->set('userWorking', (object) ['zone' => 'client']);

        $this->assertSame('client', current_zone());
        $this->assertFalse(is_working_admin());
        // Authorization is untouched: is_admin() still reflects userAuthenticated.
        $this->assertTrue(is_admin());
    }

    public function testCurrentZoneIsClientForAuthenticatedClient(): void
    {
        session()->set('userAuthenticated', (object) ['zone' => 'client']);

        $this->assertSame('client', current_zone());
        $this->assertFalse(is_working_admin());
    }
}
