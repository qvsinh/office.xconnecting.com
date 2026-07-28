<?php

use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The pure half of `attendance_helper.php`: IP/CIDR matching and the
 * UTC-storage / Vietnam-business-day split. No DB and no request state, so it
 * lives in tests/unit — the settings-driven half (`office_ip_allowed()`,
 * `attendance_period_locked()`) is covered by AttendanceHelperTest.
 *
 * The timezone cases are the ones that matter most: a check-in at 17:30 UTC
 * already belongs to the *next* Vietnam day, and deriving `work_date` from the
 * UTC clock would file it under the wrong date every evening.
 *
 * @internal
 */
final class AttendanceIpMatchTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('attendance');
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        parent::tearDown();
    }

    public function testExactIpv4MatchesRegardlessOfLeadingZeros(): void
    {
        $this->assertTrue(attendance_ip_matches('10.0.0.1', '10.0.0.1'));
        $this->assertFalse(attendance_ip_matches('10.0.0.1', '10.0.0.2'));
    }

    public function testIpv4CidrMatchesInsideRangeAndRejectsOutside(): void
    {
        $this->assertTrue(attendance_ip_matches('10.0.0.0/24', '10.0.0.1'));
        $this->assertTrue(attendance_ip_matches('10.0.0.0/24', '10.0.0.255'));
        $this->assertFalse(attendance_ip_matches('10.0.0.0/24', '10.0.1.1'));
    }

    public function testCidrWithNonByteAlignedPrefix(): void
    {
        // 203.0.113.0/28 covers .0-.15 only.
        $this->assertTrue(attendance_ip_matches('203.0.113.10', '203.0.113.10'));
        $this->assertTrue(attendance_ip_matches('203.0.113.0/28', '203.0.113.15'));
        $this->assertFalse(attendance_ip_matches('203.0.113.0/28', '203.0.113.16'));
    }

    public function testSlashZeroMatchesEverythingOfTheSameFamily(): void
    {
        $this->assertTrue(attendance_ip_matches('0.0.0.0/0', '198.51.100.7'));
        $this->assertFalse(attendance_ip_matches('0.0.0.0/0', '2001:db8::1'));
    }

    public function testIpv6ExactAndCidr(): void
    {
        $this->assertTrue(attendance_ip_matches('2001:db8::1', '2001:db8::1'));
        $this->assertTrue(attendance_ip_matches('2001:db8::/32', '2001:db8:1234::5'));
        $this->assertFalse(attendance_ip_matches('2001:db8::/32', '2001:db9::5'));
    }

    public function testIpv4RuleNeverMatchesIpv6AddressOrViceVersa(): void
    {
        $this->assertFalse(attendance_ip_matches('10.0.0.0/8', '2001:db8::1'));
        $this->assertFalse(attendance_ip_matches('2001:db8::/32', '10.0.0.1'));
    }

    public function testMalformedRuleOrAddressReturnsFalseInsteadOfThrowing(): void
    {
        $this->assertFalse(attendance_ip_matches('not-an-ip', '10.0.0.1'));
        $this->assertFalse(attendance_ip_matches('10.0.0.0/abc', '10.0.0.1'));
        $this->assertFalse(attendance_ip_matches('10.0.0.0/99', '10.0.0.1'));
        $this->assertFalse(attendance_ip_matches('10.0.0.0/-1', '10.0.0.1'));
        $this->assertFalse(attendance_ip_matches('10.0.0.0/24', 'nonsense'));
    }

    public function testAttendanceTodayUsesVietnamCalendarNotUtc(): void
    {
        // 17:30 UTC on the 21st is 00:30 on the 22nd in Vietnam (UTC+7).
        Time::setTestNow(Time::parse('2026-07-21 17:30:00', 'UTC'));

        $this->assertSame('2026-07-22', attendance_today());

        // ...and 16:59 UTC is still the 21st locally (23:59).
        Time::setTestNow(Time::parse('2026-07-21 16:59:00', 'UTC'));

        $this->assertSame('2026-07-21', attendance_today());
    }

    public function testAttendanceLocalConvertsStoredUtcToVietnamTime(): void
    {
        $local = attendance_local('2026-07-22 01:30:00');

        $this->assertNotNull($local);
        $this->assertSame('08:30', $local->format('H:i'));
        $this->assertSame('2026-07-22', $local->toDateString());
    }

    public function testAttendanceLocalPassesNullAndBlankThrough(): void
    {
        $this->assertNull(attendance_local(null));
        $this->assertNull(attendance_local('   '));
    }
}
