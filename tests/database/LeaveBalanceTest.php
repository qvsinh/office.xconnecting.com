<?php

use App\Libraries\AttendanceCalendar;
use App\Libraries\LeaveBalance;
use App\Models\HolidayModel;
use App\Models\LeaveRequestModel;
use App\Models\SiteVariableModel;
use App\Models\UserModel;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * `LeaveBalance` — how many days a leave request actually costs, and how many an
 * employee has left.
 *
 * The rule under test: **only scheduled workdays count.** A Friday-to-Monday
 * range costs 2 days, not 4, and a public holiday inside a range costs nothing.
 * Getting this wrong silently overcharges every employee who takes leave across a
 * weekend, which is why each case is spelled out.
 *
 * @internal
 */
final class LeaveBalanceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        helper('attendance');

        $this->userId = (int) model(UserModel::class)->insert([
            'name'     => 'Employee',
            'email'    => 'balance@example.com',
            'password' => password_hash('secret-password', PASSWORD_DEFAULT),
            'zone'     => 'user',
            'status'   => 1,
        ], true);
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function approvedLeave(array $overrides = []): object
    {
        $model = model(LeaveRequestModel::class);
        $id    = (int) $model->insert(array_merge([
            'user_id'     => $this->userId,
            'type'        => LeaveRequestModel::TYPE_LEAVE,
            'leave_kind'  => 'annual',
            'start_date'  => '2026-07-22',
            'end_date'    => '2026-07-22',
            'day_portion' => 'full',
            'reason'      => 'Family',
            'status'      => LeaveRequestModel::STATUS_APPROVED,
        ], $overrides), true);

        return $model->findRequest($id);
    }

    public function testASingleWorkdayCostsOneDay(): void
    {
        // 2026-07-22 is a Wednesday.
        $this->assertSame(1.0, (new LeaveBalance())->requestDays($this->approvedLeave()));
    }

    public function testAMondayToFridayRangeCostsFiveDays(): void
    {
        $request = $this->approvedLeave(['start_date' => '2026-07-20', 'end_date' => '2026-07-24']);

        $this->assertSame(5.0, (new LeaveBalance())->requestDays($request));
    }

    public function testAFridayToMondayRangeCostsTwoDaysNotFour(): void
    {
        // 2026-07-24 Fri → 2026-07-27 Mon: the weekend is not leave.
        $request = $this->approvedLeave(['start_date' => '2026-07-24', 'end_date' => '2026-07-27']);

        $this->assertSame(2.0, (new LeaveBalance())->requestDays($request));
    }

    public function testAHolidayInsideTheRangeIsNotCharged(): void
    {
        model(HolidayModel::class)->insert(['holiday_date' => '2026-07-22', 'name' => 'Company day']);

        $request = $this->approvedLeave(['start_date' => '2026-07-20', 'end_date' => '2026-07-24']);

        $this->assertSame(4.0, (new LeaveBalance())->requestDays($request));
    }

    public function testAHalfDayCostsPointFive(): void
    {
        $request = $this->approvedLeave(['day_portion' => 'afternoon']);

        $this->assertSame(0.5, (new LeaveBalance())->requestDays($request));
    }

    public function testALateArrivalRequestCostsNothing(): void
    {
        $request = $this->approvedLeave([
            'type'          => LeaveRequestModel::TYPE_LATE,
            'leave_kind'    => null,
            'expected_time' => '09:30:00',
        ]);

        $this->assertSame(0.0, (new LeaveBalance())->requestDays($request));
    }

    public function testAWeekendOnlyRangeCostsNothing(): void
    {
        // 2026-07-25 Sat → 2026-07-26 Sun.
        $request = $this->approvedLeave(['start_date' => '2026-07-25', 'end_date' => '2026-07-26']);

        $this->assertSame(0.0, (new LeaveBalance())->requestDays($request));
    }

    public function testQuotaDefaultsToTheSeededTwelveDays(): void
    {
        $this->assertSame(12.0, (new LeaveBalance())->quota($this->userId));
    }

    public function testTheSiteSettingChangesTheQuota(): void
    {
        model(SiteVariableModel::class)->setValue('leave_annual_days', '15');

        $this->assertSame(15.0, (new LeaveBalance())->quota($this->userId));
    }

    public function testAPerEmployeeOverrideBeatsTheSiteSetting(): void
    {
        model(SiteVariableModel::class)->setValue('leave_annual_days', '15');
        model(UserModel::class)->update($this->userId, ['annual_leave_days' => 20]);

        $this->assertSame(20.0, (new LeaveBalance())->quota($this->userId));
    }

    public function testOnlyApprovedAnnualLeaveDrawsDownTheBalance(): void
    {
        Time::setTestNow(Time::parse('2026-07-31 04:00:00', 'UTC'));

        // 3 approved annual days...
        $this->approvedLeave(['start_date' => '2026-07-20', 'end_date' => '2026-07-22']);
        // ...plus things that must NOT count.
        $this->approvedLeave(['start_date' => '2026-07-23', 'leave_kind' => 'unpaid']);
        $this->approvedLeave(['start_date' => '2026-07-24', 'leave_kind' => 'sick']);
        $this->approvedLeave(['start_date' => '2026-07-27', 'status' => LeaveRequestModel::STATUS_PENDING]);
        $this->approvedLeave(['start_date' => '2026-07-28', 'status' => LeaveRequestModel::STATUS_REJECTED]);
        $this->approvedLeave(['start_date' => '2026-07-29', 'status' => LeaveRequestModel::STATUS_CANCELLED]);

        $summary = (new LeaveBalance())->summary($this->userId);

        $this->assertSame(3.0, $summary->used);
        $this->assertSame(9.0, $summary->remaining);
    }

    public function testARangeStraddlingNewYearIsSplitBetweenTheTwoYears(): void
    {
        // 2026-12-30 Wed → 2027-01-02 Sat: 30th, 31st, 1st are weekdays.
        $this->approvedLeave(['start_date' => '2026-12-30', 'end_date' => '2027-01-02']);

        $balance = new LeaveBalance();

        // 2026 is charged for the 30th and 31st; 2027 for the 1st only.
        $this->assertSame(2.0, $balance->used($this->userId, 2026));
        $this->assertSame(1.0, $balance->used($this->userId, 2027));
    }

    public function testOverdrawingIsReportedNotPrevented(): void
    {
        Time::setTestNow(Time::parse('2026-07-31 04:00:00', 'UTC'));
        model(UserModel::class)->update($this->userId, ['annual_leave_days' => 1]);

        $this->approvedLeave(['start_date' => '2026-07-20', 'end_date' => '2026-07-24']);

        $summary = (new LeaveBalance())->summary($this->userId);

        // The library reports the overdraw as a negative remainder; refusing the
        // approval is a management decision, not a data rule.
        $this->assertSame(5.0, $summary->used);
        $this->assertSame(-4.0, $summary->remaining);
    }

    public function testFormatDaysReadsNaturally(): void
    {
        $this->assertSame('1 day', LeaveBalance::formatDays(1.0));
        $this->assertSame('0.5 days', LeaveBalance::formatDays(0.5));
        $this->assertSame('8.5 days', LeaveBalance::formatDays(8.5));
        $this->assertSame('12 days', LeaveBalance::formatDays(12.0));
        $this->assertSame('0 days', LeaveBalance::formatDays(0.0));
    }

    // ------------------------------------------ integration with the attendance sheet

    public function testApprovedFullDayLeaveMakesTheDayReadOnLeaveNotAbsent(): void
    {
        $this->approvedLeave();

        $day = (new AttendanceCalendar())->evaluateRange($this->userId, '2026-07-22', '2026-07-22')['2026-07-22'];

        $this->assertSame(AttendanceCalendar::STATUS_ON_LEAVE, $day->status);
        $this->assertSame(0, $day->expected_seconds);
    }

    public function testAPendingOrCancelledRequestChangesNothingOnTheSheet(): void
    {
        $this->approvedLeave(['status' => LeaveRequestModel::STATUS_PENDING]);

        $day = (new AttendanceCalendar())->evaluateRange($this->userId, '2026-07-22', '2026-07-22')['2026-07-22'];

        // Unapproved means unapproved — the employee is still absent.
        $this->assertSame(AttendanceCalendar::STATUS_ABSENT, $day->status);
        $this->assertSame(8 * 3600, $day->expected_seconds);
    }

    public function testApprovedLeaveSpanningARangeCoversEveryDayInIt(): void
    {
        $this->approvedLeave(['start_date' => '2026-07-20', 'end_date' => '2026-07-24']);

        $days = (new AttendanceCalendar())->evaluateRange($this->userId, '2026-07-20', '2026-07-26');

        foreach (['2026-07-20', '2026-07-21', '2026-07-22', '2026-07-23', '2026-07-24'] as $date) {
            $this->assertSame(AttendanceCalendar::STATUS_ON_LEAVE, $days[$date]->status, "{$date} should read on_leave");
        }

        // The weekend after it is still just a weekend.
        $this->assertSame(AttendanceCalendar::STATUS_NON_WORKDAY, $days['2026-07-25']->status);
    }

    public function testApprovedLateRequestExcusesTheLatenessOnTheSheet(): void
    {
        $this->approvedLeave([
            'type'          => LeaveRequestModel::TYPE_LATE,
            'leave_kind'    => null,
            'expected_time' => '09:30:00',
        ]);

        model(App\Models\AttendanceDayModel::class)->checkin(
            $this->userId,
            '10.0.0.1',
            '2026-07-22',
            // 09:30 Vietnam = 02:30 UTC.
            '2026-07-22 02:30:00',
        );
        $row = model(App\Models\AttendanceDayModel::class)->todayFor($this->userId) ?? model(App\Models\AttendanceDayModel::class)->dayFor($this->userId, '2026-07-22');
        model(App\Models\AttendanceDayModel::class)->checkout((int) $row->id, '10.0.0.1', '2026-07-22 11:00:00');

        $day = (new AttendanceCalendar())->evaluateRange($this->userId, '2026-07-22', '2026-07-22')['2026-07-22'];

        $this->assertSame(AttendanceCalendar::STATUS_PRESENT, $day->status);
        $this->assertSame(0, $day->late_minutes);
        $this->assertTrue($day->late_excused);
    }

    public function testApprovedHalfDayLeaveHalvesTheExpectedHours(): void
    {
        $this->approvedLeave(['day_portion' => 'morning']);

        $day = (new AttendanceCalendar())->evaluateRange($this->userId, '2026-07-22', '2026-07-22')['2026-07-22'];

        $this->assertSame(4 * 3600, $day->expected_seconds);
    }

    public function testALeaveRequestBeatsALateRequestOnTheSameDay(): void
    {
        $this->approvedLeave(['type' => LeaveRequestModel::TYPE_LATE, 'leave_kind' => null, 'expected_time' => '09:30:00']);
        $this->approvedLeave();

        // The sheet reports being off, not arriving late — asserted on the
        // rendered outcome rather than on an intermediate lookup.
        $day = (new AttendanceCalendar())->evaluateRange($this->userId, '2026-07-22', '2026-07-22')['2026-07-22'];

        $this->assertSame(AttendanceCalendar::STATUS_ON_LEAVE, $day->status);
        $this->assertSame(0, $day->expected_seconds);
    }
}
