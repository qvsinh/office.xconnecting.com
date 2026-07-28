<?php

use App\Libraries\AttendanceCalendar;
use App\Models\HolidayModel;
use App\Models\UserModel;
use App\Models\WorkScheduleModel;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * `AttendanceCalendar` — the arithmetic IS the feature, so this covers the
 * table in card 01b §9 case by case against the company baseline (08:30–18:00,
 * 90-minute lunch from 12:00, Mon–Fri, grace 0 → 8h00 expected).
 *
 * Rows are hand-built and passed in, so no attendance fixtures are needed; the
 * DB is only here for the schedule + holiday lookups.
 *
 * Stored timestamps are UTC, so Vietnam 08:30 is `01:30:00` UTC.
 *
 * @internal
 */
final class AttendanceCalendarTest extends CIUnitTestCase
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
            'email'    => 'calendar@example.com',
            'password' => password_hash('secret-password', PASSWORD_DEFAULT),
            'zone'     => 'user',
            'status'   => 1,
        ], true);
    }

    /**
     * Vietnam local `HH:MM` on the given date -> the stored UTC datetime.
     */
    private function utc(string $date, string $localTime): string
    {
        return CodeIgniter\I18n\Time::parse($date . ' ' . $localTime . ':00', attendance_tz())
            ->setTimezone('UTC')
            ->format('Y-m-d H:i:s');
    }

    private function row(string $date, ?string $inLocal, ?string $outLocal): object
    {
        return (object) [
            'work_date'        => $date,
            'checkin_at'       => $inLocal === null ? null : $this->utc($date, $inLocal),
            'checkout_at'      => $outLocal === null ? null : $this->utc($date, $outLocal),
            'missing_checkout' => 0,
        ];
    }

    /**
     * 2026-07-22 is a Wednesday — an ordinary working day at the baseline.
     */
    private function evaluateWednesday(?string $inLocal, ?string $outLocal, ?object $request = null): object
    {
        $row = $inLocal === null && $outLocal === null ? null : $this->row('2026-07-22', $inLocal, $outLocal);

        return (new AttendanceCalendar())->evaluate($this->userId, '2026-07-22', $row, $request);
    }

    public function testTheSeededBaselineIsTheCompanySchedule(): void
    {
        $schedule = model(WorkScheduleModel::class)->defaultSchedule();

        $this->assertNotNull($schedule);
        $this->assertSame('08:30:00', $schedule->start_time);
        $this->assertSame('18:00:00', $schedule->end_time);
        $this->assertSame('12:00:00', $schedule->break_start);
        $this->assertSame(90, (int) $schedule->break_minutes);
        $this->assertSame('1,2,3,4,5', $schedule->workdays);
    }

    public function testFullDayIsPresentWithEightHoursNet(): void
    {
        $day = $this->evaluateWednesday('08:30', '18:00');

        $this->assertSame(AttendanceCalendar::STATUS_PRESENT, $day->status);
        $this->assertSame(0, $day->late_minutes);
        // 9h30 on site − 1h30 lunch = 8h00 exactly.
        $this->assertSame(8 * 3600, $day->worked_seconds);
        $this->assertSame(8 * 3600, $day->expected_seconds);
        $this->assertSame(0, $day->overtime_seconds);
    }

    public function testEarlyArrivalAndLateDepartureProduceOvertimeNotLateness(): void
    {
        $day = $this->evaluateWednesday('08:25', '18:05');

        $this->assertSame(AttendanceCalendar::STATUS_PRESENT, $day->status);
        $this->assertSame(0, $day->late_minutes);
        $this->assertSame(0, $day->early_leave_minutes);
        $this->assertSame(8 * 3600 + 600, $day->worked_seconds); // 8h10
        $this->assertSame(600, $day->overtime_seconds);
    }

    public function testOneMinuteAfterStartIsLate(): void
    {
        $day = $this->evaluateWednesday('08:31', '18:00');

        $this->assertSame(AttendanceCalendar::STATUS_LATE, $day->status);
        $this->assertSame(1, $day->late_minutes);
        $this->assertSame(8 * 3600 - 60, $day->worked_seconds);
    }

    public function testArrivingExactlyOnTimeIsNotLate(): void
    {
        $this->assertSame(0, $this->evaluateWednesday('08:30', '18:00')->late_minutes);
        // Seconds past the minute do not make someone late — a clock is read to
        // the minute.
        $row = (object) [
            'work_date'   => '2026-07-22',
            'checkin_at'  => '2026-07-22 01:30:45',
            'checkout_at' => '2026-07-22 11:00:00',
        ];
        $this->assertSame(0, (new AttendanceCalendar())->evaluate($this->userId, '2026-07-22', $row)->late_minutes);
    }

    public function testGraceDecidesTheFlagButNotTheReportedMinutes(): void
    {
        $schedule = model(WorkScheduleModel::class)->defaultSchedule();
        model(WorkScheduleModel::class)->update((int) $schedule->id, ['grace_minutes' => 10]);

        // Within tolerance: not flagged, but the 9 minutes are still reported —
        // raising the tolerance must not silently shrink the monthly totals.
        $tolerated = $this->evaluateWednesday('08:39', '18:00');
        $this->assertSame(AttendanceCalendar::STATUS_PRESENT, $tolerated->status);
        $this->assertSame(9, $tolerated->late_minutes);

        // Past tolerance: flagged, and counted from the official 08:30 start.
        $late = $this->evaluateWednesday('08:41', '18:00');
        $this->assertSame(AttendanceCalendar::STATUS_LATE, $late->status);
        $this->assertSame(11, $late->late_minutes);
    }

    public function testHalfDayEndingBeforeLunchIsNotDockedForTheBreak(): void
    {
        $day = $this->evaluateWednesday('08:30', '11:00');

        // 2h30 on site, no overlap with the 12:00–13:30 window.
        $this->assertSame(9000, $day->worked_seconds);
        $this->assertSame(0, $day->overtime_seconds);
        $this->assertSame(420, $day->early_leave_minutes); // 18:00 − 11:00
    }

    public function testPartialLunchOverlapIsDeductedProRata(): void
    {
        // 08:30 → 12:45 = 4h15 on site, of which 45 min falls inside lunch.
        $day = $this->evaluateWednesday('08:30', '12:45');

        $this->assertSame(3 * 3600 + 1800, $day->worked_seconds); // 3h30
    }

    public function testAfternoonOnlyDayHasNoBreakOverlap(): void
    {
        $day = $this->evaluateWednesday('13:30', '18:00');

        $this->assertSame(4 * 3600 + 1800, $day->worked_seconds); // 4h30
        $this->assertSame(AttendanceCalendar::STATUS_LATE, $day->status);
    }

    public function testMissingCheckoutIsFlaggedAndWorkedTimeIsUnknown(): void
    {
        $day = $this->evaluateWednesday('08:30', null);

        $this->assertSame(AttendanceCalendar::STATUS_MISSING_CHECKOUT, $day->status);
        $this->assertNull($day->worked_seconds);
        $this->assertSame(0, $day->overtime_seconds);
        // Still expected to work a full day — the hours are missing, not waived.
        $this->assertSame(8 * 3600, $day->expected_seconds);
    }

    public function testWorkdayWithNoRowIsAbsent(): void
    {
        $day = $this->evaluateWednesday(null, null);

        $this->assertSame(AttendanceCalendar::STATUS_ABSENT, $day->status);
        $this->assertNull($day->worked_seconds);
        $this->assertSame(8 * 3600, $day->expected_seconds);
    }

    public function testSaturdayIsANonWorkdayAndAnyHoursAreOvertime(): void
    {
        $calendar = new AttendanceCalendar();
        // 2026-07-25 is a Saturday.
        $empty = $calendar->evaluate($this->userId, '2026-07-25', null);
        $this->assertSame(AttendanceCalendar::STATUS_NON_WORKDAY, $empty->status);
        $this->assertSame(0, $empty->expected_seconds);

        $worked = $calendar->evaluate($this->userId, '2026-07-25', $this->row('2026-07-25', '09:00', '13:00'));
        $this->assertSame(AttendanceCalendar::STATUS_NON_WORKDAY, $worked->status);
        // 4h on site − 1h lunch overlap (12:00–13:00) = 3h, all of it overtime.
        $this->assertSame(3 * 3600, $worked->worked_seconds);
        $this->assertSame(3 * 3600, $worked->overtime_seconds);
        // Working a Saturday is never "late".
        $this->assertSame(0, $worked->late_minutes);
    }

    public function testHolidayBeatsBothNonWorkdayAndAbsent(): void
    {
        model(HolidayModel::class)->insert(['holiday_date' => '2026-09-02', 'name' => 'National Day']);

        $calendar = new AttendanceCalendar();
        // 2026-09-02 is a Wednesday — a workday but for the holiday.
        $day = $calendar->evaluate($this->userId, '2026-09-02', null);

        $this->assertSame(AttendanceCalendar::STATUS_HOLIDAY, $day->status);
        $this->assertSame('National Day', $day->holiday_name);
        $this->assertSame(0, $day->expected_seconds);

        // A holiday falling on a Saturday still reads as a holiday.
        model(HolidayModel::class)->insert(['holiday_date' => '2026-07-25', 'name' => 'Bridge day']);
        $this->assertSame(AttendanceCalendar::STATUS_HOLIDAY, (new AttendanceCalendar())->evaluate($this->userId, '2026-07-25', null)->status);
    }

    public function testApprovedFullDayLeaveReadsOnLeaveNotAbsent(): void
    {
        $leave = (object) ['type' => 'leave', 'day_portion' => 'full'];

        $day = $this->evaluateWednesday(null, null, $leave);

        $this->assertSame(AttendanceCalendar::STATUS_ON_LEAVE, $day->status);
        $this->assertSame(0, $day->expected_seconds);
    }

    public function testApprovedHalfDayLeaveHalvesTheExpectedHours(): void
    {
        $leave = (object) ['type' => 'leave', 'day_portion' => 'afternoon'];

        // Worked the morning, took the afternoon off.
        $day = $this->evaluateWednesday('08:30', '12:00', $leave);

        $this->assertSame(4 * 3600, $day->expected_seconds);
        $this->assertSame(3 * 3600 + 1800, $day->worked_seconds); // 08:30–12:00
        $this->assertSame(AttendanceCalendar::STATUS_PRESENT, $day->status);
    }

    public function testApprovedLateRequestExcusesTheLateness(): void
    {
        $day = $this->evaluateWednesday('09:30', '18:00', (object) ['type' => 'late']);

        $this->assertSame(AttendanceCalendar::STATUS_PRESENT, $day->status);
        $this->assertSame(0, $day->late_minutes);
        $this->assertTrue($day->late_excused);
    }

    public function testAnApprovedLateRequestOnAPunctualDayIsNotMarkedExcused(): void
    {
        $day = $this->evaluateWednesday('08:15', '18:00', (object) ['type' => 'late']);

        $this->assertSame(AttendanceCalendar::STATUS_PRESENT, $day->status);
        $this->assertFalse($day->late_excused);
    }

    public function testACorruptedPairNeverProducesNegativeTime(): void
    {
        $day = $this->evaluateWednesday('18:00', '08:30');

        $this->assertSame(0, $day->worked_seconds);
        $this->assertSame(0, $day->overtime_seconds);
    }

    public function testPerUserScheduleOverridesTheDefault(): void
    {
        $lateShift = (int) model(WorkScheduleModel::class)->insert([
            'name'          => 'Late shift (10:00–19:00)',
            'start_time'    => '10:00:00',
            'end_time'      => '19:00:00',
            'break_start'   => '13:00:00',
            'break_minutes' => 60,
            'workdays'      => '1,2,3,4,5',
        ], true);
        model(UserModel::class)->update($this->userId, ['work_schedule_id' => $lateShift]);

        $day = $this->evaluateWednesday('10:00', '19:00');

        $this->assertSame(AttendanceCalendar::STATUS_PRESENT, $day->status);
        $this->assertSame(8 * 3600, $day->expected_seconds); // 9h − 1h break
        $this->assertSame(8 * 3600, $day->worked_seconds);
        // 08:30 would be early, not late, on this shift.
        $this->assertSame(0, $this->evaluateWednesday('08:30', '19:00')->late_minutes);
    }

    public function testADeletedScheduleFallsBackToTheDefaultInsteadOfBlankingHistory(): void
    {
        model(UserModel::class)->update($this->userId, ['work_schedule_id' => 999999]);

        $day = $this->evaluateWednesday('08:30', '18:00');

        $this->assertSame(8 * 3600, $day->expected_seconds);
        $this->assertSame(AttendanceCalendar::STATUS_PRESENT, $day->status);
    }

    public function testEvaluateRangeCoversEveryDayAndStaysQueryBounded(): void
    {
        model(App\Models\AttendanceDayModel::class)->checkin($this->userId, '10.0.0.1', '2026-07-22', $this->utc('2026-07-22', '08:30'));
        model(HolidayModel::class)->insert(['holiday_date' => '2026-07-24', 'name' => 'Company day']);

        $calendar = new AttendanceCalendar();

        // Count real queries via the framework's own DBQuery event, so this
        // asserts the loaders are actually bulk rather than trusting the code.
        $queries = 0;
        Events::on('DBQuery', static function () use (&$queries): void {
            $queries++;
        });

        $range = $calendar->evaluateRange($this->userId, '2026-07-20', '2026-07-26');

        // Mon 20 → Sun 26 inclusive.
        $this->assertCount(7, $range);
        $this->assertSame(AttendanceCalendar::STATUS_MISSING_CHECKOUT, $range['2026-07-22']->status);
        $this->assertSame(AttendanceCalendar::STATUS_HOLIDAY, $range['2026-07-24']->status);
        $this->assertSame(AttendanceCalendar::STATUS_NON_WORKDAY, $range['2026-07-25']->status);
        $this->assertSame(AttendanceCalendar::STATUS_NON_WORKDAY, $range['2026-07-26']->status);
        $this->assertSame(AttendanceCalendar::STATUS_ABSENT, $range['2026-07-20']->status);

        // A week must not cost a query per day (the bulk loaders exist for this):
        // one attendance range query, one holiday range query, plus the schedule
        // lookups — well under one per day.
        $this->assertLessThan(7, $queries, "evaluateRange issued {$queries} queries for 7 days — the loaders are not bulk.");
    }
}
