<?php

use App\Libraries\AttendanceCalendar;
use App\Models\AttendanceDayModel;
use App\Models\HolidayModel;
use App\Models\SiteVariableModel;
use App\Models\UserModel;
use CodeIgniter\Events\Events;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * Today board, monthly report, CSV export and the payroll lock
 * (`Admin\Attendance::today`, `Admin\AttendanceReport`).
 *
 * The cases that matter most:
 *  - an employee with **no** attendance row must still appear (that is the whole
 *    point of the board — an INNER JOIN would hide exactly who is missing);
 *  - `not_yet` before the start time vs `absent` after it;
 *  - the export must carry a UTF-8 BOM and decimal hours, and must never widen
 *    access beyond the on-screen filters;
 *  - locking a month must actually block card 01's correction path.
 *
 * @internal
 */
final class AdminAttendanceReportTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use WebAuthFixture;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    protected function tearDown(): void
    {
        Time::setTestNow();
        parent::tearDown();
        session()->destroy();
    }

    private function loginAsManager(string $email = 'boss@example.com'): object
    {
        $admin = $this->seedWebUser(['email' => $email, 'zone' => 'admin']);
        $this->loginAs($admin);

        return $admin;
    }

    /**
     * Vietnam local `HH:MM` on a date -> the stored UTC datetime.
     */
    private function utc(string $date, string $localTime): string
    {
        helper('attendance');

        return Time::parse($date . ' ' . $localTime . ':00', attendance_tz())->setTimezone('UTC')->format('Y-m-d H:i:s');
    }

    private function seedDay(int $userId, string $date, ?string $in, ?string $out): object
    {
        $model = model(AttendanceDayModel::class);
        $row   = $model->checkin($userId, '10.0.0.5', $date, (string) $this->utc($date, (string) $in));

        if ($out !== null) {
            $model->checkout((int) $row->id, '10.0.0.5', $this->utc($date, $out));
        }

        return $model->findDay((int) $row->id);
    }

    // ---------------------------------------------------------------- today board

    public function testPlainEmployeeCannotOpenTheBoard(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'plain@example.com', 'zone' => 'user']));

        $this->withSession()->get('/admin/attendance/today')->assertRedirectTo('/dashboard');
    }

    public function testBoardShowsAnEmployeeWithNoRowAtAll(): void
    {
        // 2026-07-22 is a Wednesday; 11:00 Vietnam is well past the 08:30 start.
        Time::setTestNow(Time::parse('2026-07-22 04:00:00', 'UTC'));

        $this->loginAsManager();
        $this->seedWebUser(['email' => 'ghost@example.com', 'name' => 'Ghost Employee']);

        $result = $this->withSession()->get('/admin/attendance/today');

        $result->assertStatus(200);
        // The absence of a row is exactly what a manager is looking for.
        $result->assertSee('Ghost Employee');
        $result->assertSee('Absent');
    }

    public function testBoardReadsNotYetBeforeTheStartTimeAndAbsentAfterIt(): void
    {
        $this->loginAsManager();
        $this->seedWebUser(['email' => 'sleepy@example.com', 'name' => 'Sleepy Employee']);

        $sleepy   = (int) model(UserModel::class)->findActiveByEmail('sleepy@example.com')->id;
        $absent   = (new AttendanceCalendar())->evaluate($sleepy, '2026-07-22', null);
        $calendar = new AttendanceCalendar();

        // 08:00 Vietnam — before the 08:30 start.
        Time::setTestNow(Time::parse('2026-07-22 01:00:00', 'UTC'));
        $this->assertSame(
            AttendanceCalendar::STATUS_NOT_YET,
            $calendar->withPendingArrival($sleepy, '2026-07-22', $absent)->status,
        );
        $this->withSession()->get('/admin/attendance/today')->assertSee('Not in yet');

        // 09:00 Vietnam — the start time has passed, so it really is an absence.
        Time::setTestNow(Time::parse('2026-07-22 02:00:00', 'UTC'));
        $this->assertSame(
            AttendanceCalendar::STATUS_ABSENT,
            (new AttendanceCalendar())->withPendingArrival($sleepy, '2026-07-22', $absent)->status,
        );
        $this->withSession()->get('/admin/attendance/today')->assertSee('Absent');
    }

    public function testBoardTileFilterNarrowsToOneStatus(): void
    {
        Time::setTestNow(Time::parse('2026-07-22 04:00:00', 'UTC'));

        $this->loginAsManager();
        $present = $this->seedWebUser(['email' => 'present@example.com', 'name' => 'Present Person']);
        $this->seedWebUser(['email' => 'absent@example.com', 'name' => 'Absent Person']);
        $this->seedDay((int) $present->id, '2026-07-22', '08:30', null);

        $result = $this->withSession()->get('/admin/attendance/today?status=' . AttendanceCalendar::STATUS_ABSENT);

        $result->assertStatus(200);
        $result->assertSee('Absent Person');
        $result->assertDontSee('Present Person');
    }

    // ------------------------------------------------------------ monthly report

    public function testReportRendersTotalsThatReconcileWithThePerDayGrid(): void
    {
        Time::setTestNow(Time::parse('2026-07-31 04:00:00', 'UTC'));

        $this->loginAsManager();
        $employee = $this->seedWebUser(['email' => 'worker@example.com', 'name' => 'Worker One']);
        $userId   = (int) $employee->id;

        // Two clean 8h days plus one late day.
        $this->seedDay($userId, '2026-07-20', '08:30', '18:00');
        $this->seedDay($userId, '2026-07-21', '08:30', '18:00');
        $this->seedDay($userId, '2026-07-22', '09:00', '18:00');

        $result = $this->withSession()->get('/admin/attendance/report?month=2026-07&user_id=' . $userId);

        $result->assertStatus(200);
        $result->assertSee('Worker One');

        // Cross-check against the library the page renders from.
        $calendar = new AttendanceCalendar();
        $summary  = $calendar->summarise($calendar->evaluateMonth([$userId], '2026-07')[$userId]);

        $this->assertSame(2, $summary->present);
        $this->assertSame(1, $summary->late);
        $this->assertSame(30, $summary->late_minutes);
        // 8h + 8h + 7h30 = 23h30.
        $this->assertSame((int) (23.5 * 3600), $summary->worked_seconds);
    }

    public function testWorkdayCountExcludesWeekendsAndHolidays(): void
    {
        Time::setTestNow(Time::parse('2026-07-31 04:00:00', 'UTC'));

        $employee = $this->seedWebUser(['email' => 'worker2@example.com']);
        model(HolidayModel::class)->insert(['holiday_date' => '2026-07-22', 'name' => 'Company day']);

        $calendar = new AttendanceCalendar();
        $summary  = $calendar->summarise($calendar->evaluateMonth([(int) $employee->id], '2026-07')[(int) $employee->id]);

        // July 2026 has 23 weekdays; one is a holiday -> 22.
        $this->assertSame(22, $summary->workdays);
    }

    public function testFutureWorkdaysAreNotCountedAsAbsence(): void
    {
        // Mid-month: the rest of July has not happened yet.
        Time::setTestNow(Time::parse('2026-07-10 04:00:00', 'UTC'));

        $employee = $this->seedWebUser(['email' => 'worker3@example.com']);
        $calendar = new AttendanceCalendar();
        $days     = $calendar->evaluateMonth([(int) $employee->id], '2026-07')[(int) $employee->id];
        $summary  = $calendar->summarise($days);

        // Weekdays up to and including the 10th: 1,2,3,6,7,8,9,10 = 8.
        $this->assertSame(8, $summary->absent);
        // ...while the month's total workday count is still the full 23.
        $this->assertSame(23, $summary->workdays);
    }

    public function testReportQueryCountDoesNotGrowWithTheRoster(): void
    {
        Time::setTestNow(Time::parse('2026-07-31 04:00:00', 'UTC'));

        $one = $this->seedWebUser(['email' => 'one@example.com']);

        $twenty = [(int) $one->id];
        for ($i = 2; $i <= 20; $i++) {
            $twenty[] = (int) $this->seedWebUser(['email' => "bulk{$i}@example.com"])->id;
        }

        $countQueries = static function (callable $work): int {
            $queries = 0;
            Events::on('DBQuery', static function () use (&$queries): void {
                $queries++;
            });
            $work();

            return $queries;
        };

        $forOne    = $countQueries(static fn () => (new AttendanceCalendar())->evaluateMonth([$twenty[0]], '2026-07'));
        $forTwenty = $countQueries(static fn () => (new AttendanceCalendar())->evaluateMonth($twenty, '2026-07'));

        // The bulk loader is the whole point: 20 employees must not cost 20x.
        $this->assertLessThanOrEqual($forOne + 1, $forTwenty, "1 employee: {$forOne} queries · 20 employees: {$forTwenty} — evaluateMonth is not bulk.");
    }

    public function testPerEmployeeDetailPageRendersAndReports404ForAnUnknownEmployee(): void
    {
        Time::setTestNow(Time::parse('2026-07-31 04:00:00', 'UTC'));

        $this->loginAsManager();
        $employee = $this->seedWebUser(['email' => 'detail@example.com', 'name' => 'Detail Person']);
        $this->seedDay((int) $employee->id, '2026-07-22', '09:00', '18:00');

        $result = $this->withSession()->get('/admin/attendance/report/user/' . $employee->id . '?month=2026-07');
        $result->assertStatus(200);
        $result->assertSee('Detail Person');
        $result->assertSee('2026-07-22');
    }

    public function testPerEmployeeDetailPageRejectsAnUnknownEmployee(): void
    {
        $this->loginAsManager();

        // A page route throws PageNotFoundException (CI4 renders it as a 404);
        // the feature client re-throws rather than returning a response.
        $this->expectException(PageNotFoundException::class);
        $this->withSession()->get('/admin/attendance/report/user/999999?month=2026-07');
    }

    // -------------------------------------------------------------------- export

    public function testExportCarriesABomDecimalHoursAndTheRightHeaders(): void
    {
        Time::setTestNow(Time::parse('2026-07-31 04:00:00', 'UTC'));

        $this->loginAsManager();
        $employee = $this->seedWebUser(['email' => 'export@example.com', 'name' => 'Nguyễn Văn A']);
        $this->seedDay((int) $employee->id, '2026-07-22', '08:30', '18:00');

        $result = $this->withSession()->get('/admin/attendance/report/export?month=2026-07&user_id=' . $employee->id);

        $result->assertStatus(200);
        $result->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('attachment; filename="attendance-2026-07.csv"', (string) $result->response()->getHeaderLine('Content-Disposition'));

        $body = (string) $result->response()->getBody();
        // Without the BOM, Excel on Windows mangles the Vietnamese name.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString('Nguyễn Văn A', $body);
        // Decimal hours, because spreadsheets cannot sum "8h 00m".
        $this->assertStringContainsString('8.00', $body);
        $this->assertStringContainsString('Worked hours', $body);
    }

    public function testDetailExportEmitsOneRowPerDay(): void
    {
        Time::setTestNow(Time::parse('2026-07-31 04:00:00', 'UTC'));

        $this->loginAsManager();
        $employee = $this->seedWebUser(['email' => 'detailexport@example.com', 'name' => 'Grid Person']);
        $this->seedDay((int) $employee->id, '2026-07-22', '08:30', '18:00');

        $result = $this->withSession()->get('/admin/attendance/report/export?detail=1&month=2026-07&user_id=' . $employee->id);

        $result->assertStatus(200);
        $body = (string) $result->response()->getBody();
        $this->assertStringContainsString('attachment; filename="attendance-2026-07-detail.csv"', (string) $result->response()->getHeaderLine('Content-Disposition'));
        // 31 days in July + the header row.
        $this->assertSame(32, count(array_filter(explode("\n", trim($body)))));
        $this->assertStringContainsString('2026-07-22', $body);
    }

    public function testExportRespectsTheEmployeeFilterAndIsPermissionGated(): void
    {
        Time::setTestNow(Time::parse('2026-07-31 04:00:00', 'UTC'));

        $wanted   = $this->seedWebUser(['email' => 'wanted@example.com', 'name' => 'Wanted Person']);
        $excluded = $this->seedWebUser(['email' => 'excluded@example.com', 'name' => 'Excluded Person']);

        $this->loginAsManager();
        $body = (string) $this->withSession()->get('/admin/attendance/report/export?month=2026-07&user_id=' . $wanted->id)->response()->getBody();

        $this->assertStringContainsString('Wanted Person', $body);
        $this->assertStringNotContainsString('Excluded Person', $body);

        // A plain employee gets no export at all.
        session()->destroy();
        $this->loginAs($this->seedWebUser(['email' => 'nosy@example.com', 'zone' => 'user']));
        $this->withSession()->get('/admin/attendance/report/export?month=2026-07')->assertRedirectTo('/dashboard');
    }

    // ---------------------------------------------------------------- payroll lock

    public function testLockingAMonthBlocksCard01sCorrectionPath(): void
    {
        Time::setTestNow(Time::parse('2026-07-31 04:00:00', 'UTC'));

        $this->loginAsManager();
        $employee = $this->seedWebUser(['email' => 'locked@example.com']);
        $day      = $this->seedDay((int) $employee->id, '2026-07-22', '09:00', '18:00');

        $this->withSession()->post('/admin/attendance/report/lock', $this->withCsrf(['month' => '2026-07']))->assertStatus(200);
        $this->assertSame('2026-07', model(SiteVariableModel::class)->getValue('attendance_locked_through'));

        // The correction endpoint from card 01 must now refuse.
        $this->withSession()->post('/admin/attendance/update', $this->withCsrf([
            'id'           => $day->id,
            'checkin_time' => '08:30',
        ]))->assertStatus(409);

        // Re-opening steps the marker back to the previous month.
        $this->withSession()->post('/admin/attendance/report/unlock', $this->withCsrf(['month' => '2026-07']))->assertStatus(200);
        $this->assertSame('2026-06', model(SiteVariableModel::class)->getValue('attendance_locked_through'));

        $this->withSession()->post('/admin/attendance/update', $this->withCsrf([
            'id'           => $day->id,
            'checkin_time' => '08:30',
        ]))->assertStatus(200);
    }

    public function testLockRequiresCsrfAndThePermission(): void
    {
        $this->loginAsManager();

        $noCsrf = $this->withSession()->post('/admin/attendance/report/lock', ['month' => '2026-07']);
        $this->assertNotSame(200, $noCsrf->response()->getStatusCode());
        $this->assertSame('', model(SiteVariableModel::class)->getValue('attendance_locked_through'));

        session()->destroy();
        $this->loginAs($this->seedWebUser(['email' => 'nolock@example.com', 'zone' => 'user']));
        $this->withSession()->post('/admin/attendance/report/lock', $this->withCsrf(['month' => '2026-07']));
        $this->assertSame('', model(SiteVariableModel::class)->getValue('attendance_locked_through'));
    }

    public function testAMalformedMonthFallsBackToTheCurrentOne(): void
    {
        Time::setTestNow(Time::parse('2026-07-22 04:00:00', 'UTC'));

        $this->loginAsManager();

        $this->withSession()->post('/admin/attendance/report/lock', $this->withCsrf(['month' => 'nonsense-13']))->assertStatus(200);

        $this->assertSame('2026-07', model(SiteVariableModel::class)->getValue('attendance_locked_through'));
    }

    // ------------------------------------------------------- employee self-view

    public function testEmployeeSelfViewShowsOnlyTheirOwnMonth(): void
    {
        Time::setTestNow(Time::parse('2026-07-31 04:00:00', 'UTC'));

        $me    = $this->seedWebUser(['email' => 'me@example.com', 'name' => 'My Own Self']);
        $other = $this->seedWebUser(['email' => 'other@example.com', 'name' => 'Someone Else']);
        $this->seedDay((int) $me->id, '2026-07-22', '08:30', '18:00');
        $this->seedDay((int) $other->id, '2026-07-22', '09:30', '18:00');

        $this->loginAs($me);

        $result = $this->withSession()->get('/attendance/report?month=2026-07');

        $result->assertStatus(200);
        $result->assertSee('My month');
        // No employee parameter exists on this route, so there is nothing to
        // tamper with — assert the other employee's name never appears.
        $result->assertDontSee('Someone Else');

        // ...and a user_id in the query string changes nothing.
        $tampered = $this->withSession()->get('/attendance/report?month=2026-07&user_id=' . $other->id);
        $tampered->assertDontSee('Someone Else');
    }

    public function testEmployeeSelfViewRequiresLogin(): void
    {
        $this->get('/attendance/report')->assertRedirectTo('/login');
    }
}
