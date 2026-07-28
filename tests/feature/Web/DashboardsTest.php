<?php

use App\Libraries\AttendanceCalendar;
use App\Models\AttendanceDayModel;
use App\Models\LeaveRequestModel;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * `Admin\Dashboard` / `Client\Dashboard`.
 *
 * Both used to summarise workspaces/projects/tasks; that module was removed, so
 * they now summarise attendance. The property worth pinning down is that they
 * read the *same* numbers as the attendance pages — a dashboard that quietly
 * disagrees with the report it links to is worse than no dashboard.
 *
 * @internal
 */
final class DashboardsTest extends CIUnitTestCase
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

    /**
     * Vietnam local `HH:MM` on a date -> the stored UTC datetime.
     */
    private function utc(string $date, string $localTime): string
    {
        helper('attendance');

        return Time::parse($date . ' ' . $localTime . ':00', attendance_tz())->setTimezone('UTC')->format('Y-m-d H:i:s');
    }

    // ------------------------------------------------------------------- gating

    public function testAdminDashboardBlocksANonAdmin(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'plain@example.com', 'zone' => 'user']));

        $this->withSession()->get('/admin/dashboard')->assertRedirectTo('/dashboard');
    }

    public function testAdminDashboardRedirectsGuestToLogin(): void
    {
        $this->get('/admin/dashboard')->assertRedirectTo('/login');
    }

    public function testClientDashboardRedirectsGuestToLogin(): void
    {
        $this->get('/dashboard')->assertRedirectTo('/login');
    }

    // -------------------------------------------------------- admin dashboard

    public function testAdminDashboardCountsMatchTheTodayBoard(): void
    {
        // 11:00 Vietnam on a Wednesday — past the 08:30 start, so the day counts.
        Time::setTestNow(Time::parse('2026-07-22 04:00:00', 'UTC'));

        $late   = $this->seedWebUser(['email' => 'late@example.com', 'name' => 'Late Person']);
        $absent = $this->seedWebUser(['email' => 'absent@example.com', 'name' => 'Absent Person']);
        $admin  = $this->seedWebUser(['email' => 'boss@example.com', 'zone' => 'admin', 'name' => 'The Boss']);
        $model  = model(AttendanceDayModel::class);

        $row = $model->checkin((int) $late->id, '10.0.0.1', '2026-07-22', $this->utc('2026-07-22', '09:30'));
        $model->checkout((int) $row->id, '10.0.0.1', $this->utc('2026-07-22', '18:00'));

        $this->loginAs($admin);
        $dashboard = $this->withSession()->get('/admin/dashboard');
        $dashboard->assertStatus(200);

        // Cross-check against the evaluation the today board performs.
        $calendar = new AttendanceCalendar();
        $ids      = [(int) $late->id, (int) $absent->id, (int) $admin->id];
        $spans    = $calendar->evaluateSpan($ids, '2026-07-22', '2026-07-22');

        $statuses = [];

        foreach ($ids as $id) {
            $statuses[] = $calendar->withPendingArrival($id, '2026-07-22', $spans[$id]['2026-07-22'])->status;
        }

        $this->assertContains(AttendanceCalendar::STATUS_LATE, $statuses);
        // The absent employee AND the admin (also on the roster, not checked in)
        // both read as absences once the start time has passed.
        $this->assertCount(2, array_filter($statuses, static fn (string $s): bool => $s === AttendanceCalendar::STATUS_ABSENT));

        $dashboard->assertSee('Late');
        $dashboard->assertSee('Absent');
        $dashboard->assertSee('/admin/attendance/today');
    }

    public function testAdminDashboardSurfacesPendingRequests(): void
    {
        Time::setTestNow(Time::parse('2026-07-22 04:00:00', 'UTC'));

        $employee = $this->seedWebUser(['email' => 'waiting@example.com']);
        model(LeaveRequestModel::class)->insert([
            'user_id'    => $employee->id,
            'type'       => LeaveRequestModel::TYPE_LEAVE,
            'leave_kind' => 'annual',
            'start_date' => '2026-08-03',
            'end_date'   => '2026-08-03',
            'reason'     => 'Waiting on a decision',
            'status'     => LeaveRequestModel::STATUS_PENDING,
        ]);

        $this->loginAs($this->seedWebUser(['email' => 'boss2@example.com', 'zone' => 'admin']));

        $result = $this->withSession()->get('/admin/dashboard');

        $result->assertStatus(200);
        $result->assertSee('Requests awaiting a decision');
    }

    // ------------------------------------------------------- client dashboard

    public function testClientDashboardShowsTheOwnersOwnMonthOnly(): void
    {
        Time::setTestNow(Time::parse('2026-07-22 11:30:00', 'UTC'));

        $me    = $this->seedWebUser(['email' => 'me@example.com', 'name' => 'My Own Self']);
        $other = $this->seedWebUser(['email' => 'other@example.com', 'name' => 'Someone Else']);
        $model = model(AttendanceDayModel::class);

        // 08:30 -> 18:00 = 8h00 net for me.
        $mine = $model->checkin((int) $me->id, '10.0.0.1', '2026-07-22', $this->utc('2026-07-22', '08:30'));
        $model->checkout((int) $mine->id, '10.0.0.1', $this->utc('2026-07-22', '18:00'));
        // A much longer day for somebody else, which must not leak in.
        $theirs = $model->checkin((int) $other->id, '10.0.0.2', '2026-07-22', $this->utc('2026-07-22', '06:00'));
        $model->checkout((int) $theirs->id, '10.0.0.2', $this->utc('2026-07-22', '22:00'));

        $this->loginAs($me);
        $result = $this->withSession()->get('/dashboard');

        $result->assertStatus(200);
        $result->assertSee('08:30');
        $result->assertSee('8h 00m');
        $result->assertDontSee('Someone Else');
        $result->assertDontSee('14h');
    }

    public function testClientDashboardCallToActionFollowsTodaysState(): void
    {
        Time::setTestNow(Time::parse('2026-07-22 02:00:00', 'UTC'));

        $me = $this->seedWebUser(['email' => 'cta@example.com']);
        $this->loginAs($me);

        // Nothing recorded yet -> invites a check-in.
        $this->withSession()->get('/dashboard')->assertSee('Check in');

        $model = model(AttendanceDayModel::class);
        $row   = $model->checkin((int) $me->id, '10.0.0.1', '2026-07-22', $this->utc('2026-07-22', '08:30'));

        // Checked in, still working -> invites a check-out.
        $this->withSession()->get('/dashboard')->assertSee('Check out');

        $model->checkout((int) $row->id, '10.0.0.1', $this->utc('2026-07-22', '18:00'));

        // Day complete -> stops nagging and just links through.
        $this->withSession()->get('/dashboard')->assertSee('View my attendance');
    }

    public function testClientDashboardWarnsAboutAMissingCheckout(): void
    {
        Time::setTestNow(Time::parse('2026-07-23 04:00:00', 'UTC'));

        $me    = $this->seedWebUser(['email' => 'forgot@example.com']);
        $model = model(AttendanceDayModel::class);
        $row   = $model->checkin((int) $me->id, '10.0.0.1', '2026-07-22', $this->utc('2026-07-22', '08:30'));
        $model->flagMissingCheckout([(int) $row->id]);

        $this->loginAs($me);

        $this->withSession()->get('/dashboard')->assertSee('no check-out recorded');
    }
}
