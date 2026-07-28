<?php

use App\Models\SiteVariableModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * The settings-driven half of `attendance_helper.php` — the office-IP gate and
 * the payroll-close lock both read `site_variable`, so this needs the real DB
 * (the pure IP/CIDR maths lives in tests/unit/AttendanceIpMatchTest).
 *
 * @internal
 */
final class AttendanceHelperTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    protected function setUp(): void
    {
        parent::setUp();
        helper(['attendance', 'setting']);
    }

    /**
     * The three attendance variables are seeded by migration, so tests update
     * the existing row rather than inserting a duplicate (UNIQUE(name)).
     */
    private function setVariable(string $name, string $value): void
    {
        $model    = model(SiteVariableModel::class);
        $existing = $model->where('name', $name)->first();

        if ($existing === null) {
            $model->insert(['name' => $name, 'value' => $value]);

            return;
        }

        $model->update((int) $existing->id, ['value' => $value]);
    }

    public function testMigrationSeedsTheThreeAttendanceVariablesWithSafeDefaults(): void
    {
        $this->assertSame('', get_site_variable('attendance_office_ips'));
        $this->assertSame('0', get_site_variable('attendance_ip_enforce'));
        $this->assertSame('', get_site_variable('attendance_locked_through'));
    }

    public function testEnforcementOffAllowsAnyAddressEvenWithAnAllowlist(): void
    {
        $this->setVariable('attendance_office_ips', '203.0.113.10');
        $this->setVariable('attendance_ip_enforce', '0');

        $this->assertFalse(attendance_ip_enforced());
        $this->assertTrue(office_ip_allowed('198.51.100.7'));
    }

    public function testEnforcementOnWithEmptyAllowlistStillAllows(): void
    {
        // Nothing to match against: locking the whole company out of check-in
        // is worse than not enforcing yet. The UI warns about this state.
        $this->setVariable('attendance_ip_enforce', '1');
        $this->setVariable('attendance_office_ips', '   ');

        $this->assertTrue(office_ip_allowed('198.51.100.7'));
    }

    public function testEnforcementOnMatchesExactAddressAndCidrAndBlocksTheRest(): void
    {
        $this->setVariable('attendance_ip_enforce', '1');
        $this->setVariable('attendance_office_ips', '203.0.113.10, 10.0.0.0/24 , 2001:db8::/32');

        $this->assertTrue(office_ip_allowed('203.0.113.10'));
        $this->assertTrue(office_ip_allowed('10.0.0.77'));
        $this->assertTrue(office_ip_allowed('2001:db8:1::9'));

        $this->assertFalse(office_ip_allowed('203.0.113.11'));
        $this->assertFalse(office_ip_allowed('10.0.1.1'));
        $this->assertFalse(office_ip_allowed('198.51.100.7'));
    }

    public function testMalformedEntriesInTheAllowlistDoNotBreakTheGate(): void
    {
        $this->setVariable('attendance_ip_enforce', '1');
        $this->setVariable('attendance_office_ips', 'oops, , 10.0.0.0/notanumber, 10.0.0.5');

        $this->assertTrue(office_ip_allowed('10.0.0.5'));
        $this->assertFalse(office_ip_allowed('10.0.0.6'));
    }

    public function testPeriodLockIsOpenUntilAMonthIsSetThenClosesThatMonthAndEarlier(): void
    {
        $this->assertSame('', attendance_locked_through());
        $this->assertFalse(attendance_period_locked('2020-01-01'));

        $this->setVariable('attendance_locked_through', '2026-06');

        $this->assertTrue(attendance_period_locked('2026-06-30'));
        $this->assertTrue(attendance_period_locked('2026-05-01'));
        $this->assertFalse(attendance_period_locked('2026-07-01'));
    }
}
