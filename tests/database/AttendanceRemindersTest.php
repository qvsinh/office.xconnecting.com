<?php

use App\Libraries\AttendanceReminders;
use App\Libraries\ScheduledTaskRunner;
use App\Models\AttendanceDayModel;
use App\Models\LeaveRequestModel;
use App\Models\NotificationModel;
use App\Models\PermissionModel;
use App\Models\RoleModel;
use App\Models\SiteVariableModel;
use App\Models\UserModel;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * The scheduled reminders and manager digests (card 01d).
 *
 * The property that matters most: **every handler is safe under minute-by-minute
 * cron.** `schedule:run` fires 1 440 times a day, so "sends once" has to come from
 * a guard in the data, not from luck about timing.
 *
 * Second: nothing is sent when there is nothing to say — a daily "all good"
 * notification trains managers to ignore the channel.
 *
 * @internal
 */
final class AttendanceRemindersTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    protected function tearDown(): void
    {
        Time::setTestNow();
        parent::tearDown();
    }

    private function makeEmployee(string $email, string $name = 'Employee'): int
    {
        return (int) model(UserModel::class)->insert([
            'name'     => $name,
            'email'    => $email,
            'password' => password_hash('secret-password', PASSWORD_DEFAULT),
            'zone'     => 'user',
            'status'   => 1,
        ], true);
    }

    /**
     * A client-zone user whose role grants the named permission — a department
     * head, not a global admin.
     */
    private function makeHolder(string $email, string $permission): int
    {
        $roleId     = (int) model(RoleModel::class)->insert(['name' => 'holder-' . $email, 'label' => 'Holder'], true);
        $permission = model(PermissionModel::class)->findByName($permission);
        $this->assertNotNull($permission, 'the permission should be seeded by migration');

        $this->db->table('role_permissions')->insert([
            'role_id'       => $roleId,
            'permission_id' => (int) $permission->id,
        ]);

        return (int) model(UserModel::class)->insert([
            'name'     => 'Manager',
            'email'    => $email,
            'password' => password_hash('secret-password', PASSWORD_DEFAULT),
            'zone'     => 'user',
            'status'   => 1,
            'role_id'  => $roleId,
        ], true);
    }

    private function notificationsFor(int $userId): array
    {
        return model(NotificationModel::class)->where('user_id', $userId)->findAll();
    }

    // ------------------------------------------------------------------ registry

    public function testAllFourHandlersAreAllowlistedAndSeeded(): void
    {
        $keys = (new ScheduledTaskRunner())->handlerKeys();

        foreach ([
            'attendance.remind_checkout',
            'attendance.absence_digest',
            'attendance.missing_checkout_digest',
            'requests.pending_digest',
        ] as $handler) {
            $this->assertContains($handler, $keys, "{$handler} should be in the allowlist");
            $this->seeInDatabase('scheduled_tasks', ['handler' => $handler, 'enabled' => 1]);
        }
    }

    // -------------------------------------------------------- check-out reminder

    public function testChecksOutReminderNotifiesOnceAndOnlyOnce(): void
    {
        // 18:30 Vietnam on a Wednesday — past the 18:00 end plus 15 minutes.
        Time::setTestNow(Time::parse('2026-07-22 11:30:00', 'UTC'));

        $userId = $this->makeEmployee('forgot@example.com');
        model(AttendanceDayModel::class)->checkin($userId, '10.0.0.1', '2026-07-22', '2026-07-22 01:30:00');

        $reminders = new AttendanceReminders();

        $this->assertStringContainsString('Reminded 1', $reminders->remindCheckout());
        $this->assertCount(1, $this->notificationsFor($userId));

        // Second pass on the same day sends nothing — the column is the guard.
        $this->assertStringContainsString('Nobody to remind', $reminders->remindCheckout());
        $this->assertCount(1, $this->notificationsFor($userId));
    }

    public function testNoReminderBeforeTheEndTimeHasPassed(): void
    {
        // 17:00 Vietnam — still inside the working day.
        Time::setTestNow(Time::parse('2026-07-22 10:00:00', 'UTC'));

        $userId = $this->makeEmployee('working@example.com');
        model(AttendanceDayModel::class)->checkin($userId, '10.0.0.1', '2026-07-22', '2026-07-22 01:30:00');

        $this->assertStringContainsString('Too early', (new AttendanceReminders())->remindCheckout());
        $this->assertCount(0, $this->notificationsFor($userId));
    }

    public function testAnEmployeeWhoCheckedOutIsNeverReminded(): void
    {
        Time::setTestNow(Time::parse('2026-07-22 11:30:00', 'UTC'));

        $userId = $this->makeEmployee('done@example.com');
        $model  = model(AttendanceDayModel::class);
        $row    = $model->checkin($userId, '10.0.0.1', '2026-07-22', '2026-07-22 01:30:00');
        $model->checkout((int) $row->id, '10.0.0.1', '2026-07-22 11:00:00');

        $this->assertStringContainsString('Nobody to remind', (new AttendanceReminders())->remindCheckout());
        $this->assertCount(0, $this->notificationsFor($userId));
    }

    public function testTheReminderCanBeSwitchedOff(): void
    {
        Time::setTestNow(Time::parse('2026-07-22 11:30:00', 'UTC'));

        $userId = $this->makeEmployee('optout@example.com');
        model(AttendanceDayModel::class)->checkin($userId, '10.0.0.1', '2026-07-22', '2026-07-22 01:30:00');
        model(SiteVariableModel::class)->setValue('attendance_checkout_reminder', '0');

        // "Disabled." is reported rather than the job silently idling.
        $this->assertSame('Disabled.', (new AttendanceReminders())->remindCheckout());
        $this->assertCount(0, $this->notificationsFor($userId));
    }

    // ----------------------------------------------------------- absence digest

    public function testAbsenceDigestReachesOnlyPermissionHolders(): void
    {
        // 09:30 Vietnam — past 08:30 + 30 minutes.
        Time::setTestNow(Time::parse('2026-07-22 02:30:00', 'UTC'));

        $absent    = $this->makeEmployee('missing@example.com', 'Missing Person');
        $manager   = $this->makeHolder('head@example.com', 'attendance.manage');
        $bystander = $this->makeEmployee('nosy@example.com', 'Plain Employee');

        $result = (new AttendanceReminders())->absenceDigest();

        $this->assertStringContainsString('Digest sent to', $result);

        $sent = $this->notificationsFor($manager);
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('Missing Person', $sent[0]->message);

        // The absent employee and an unrelated colleague hear nothing.
        $this->assertCount(0, $this->notificationsFor($absent));
        $this->assertCount(0, $this->notificationsFor($bystander));
    }

    public function testAbsenceDigestSendsNothingWhenEverybodyIsAccountedFor(): void
    {
        Time::setTestNow(Time::parse('2026-07-22 02:30:00', 'UTC'));

        $present = $this->makeEmployee('present@example.com');
        $manager = $this->makeHolder('head2@example.com', 'attendance.manage');

        // Both are checked in on time. The manager needs a row too: managers are
        // employees on the same roster, so a manager who has not checked in is
        // genuinely absent and would (correctly) appear in their own digest.
        $model = model(AttendanceDayModel::class);

        foreach ([$present, $manager] as $userId) {
            $row = $model->checkin($userId, '10.0.0.1', '2026-07-22', '2026-07-22 01:30:00');
            $model->checkout((int) $row->id, '10.0.0.1', '2026-07-22 11:00:00');
        }

        $this->assertStringContainsString('Nothing to report', (new AttendanceReminders())->absenceDigest());
        $this->assertCount(0, $this->notificationsFor($manager));
    }

    public function testAbsenceDigestIsQuietBeforeTheWorkingDayBegins(): void
    {
        // 08:00 Vietnam — nobody is absent yet.
        Time::setTestNow(Time::parse('2026-07-22 01:00:00', 'UTC'));

        $this->makeEmployee('sleepy@example.com');
        $manager = $this->makeHolder('head3@example.com', 'attendance.manage');

        $this->assertStringContainsString('Too early', (new AttendanceReminders())->absenceDigest());
        $this->assertCount(0, $this->notificationsFor($manager));
    }

    public function testAbsenceDigestReportsWhenNobodyCanReceiveIt(): void
    {
        Time::setTestNow(Time::parse('2026-07-22 02:30:00', 'UTC'));

        $this->makeEmployee('alone@example.com');

        // No manager exists — say so rather than appearing to have sent something.
        $this->assertStringContainsString('Nobody holds attendance.manage', (new AttendanceReminders())->absenceDigest());
    }

    public function testAbsenceDigestCanBeSwitchedOff(): void
    {
        Time::setTestNow(Time::parse('2026-07-22 02:30:00', 'UTC'));

        $this->makeEmployee('ignored@example.com');
        $manager = $this->makeHolder('head4@example.com', 'attendance.manage');
        model(SiteVariableModel::class)->setValue('attendance_absence_digest', '0');

        $this->assertSame('Disabled.', (new AttendanceReminders())->absenceDigest());
        $this->assertCount(0, $this->notificationsFor($manager));
    }

    public function testApprovedLeaveKeepsSomebodyOutOfTheAbsenceDigest(): void
    {
        Time::setTestNow(Time::parse('2026-07-22 02:30:00', 'UTC'));

        $onLeave = $this->makeEmployee('onleave@example.com', 'On Leave Person');
        $manager = $this->makeHolder('head5@example.com', 'attendance.manage');

        // The manager is on the same roster, so check them in — otherwise their
        // own absence is what the digest would report.
        $model = model(AttendanceDayModel::class);
        $row   = $model->checkin($manager, '10.0.0.1', '2026-07-22', '2026-07-22 01:30:00');
        $model->checkout((int) $row->id, '10.0.0.1', '2026-07-22 11:00:00');

        model(LeaveRequestModel::class)->insert([
            'user_id'     => $onLeave,
            'type'        => LeaveRequestModel::TYPE_LEAVE,
            'leave_kind'  => 'annual',
            'start_date'  => '2026-07-22',
            'end_date'    => '2026-07-22',
            'day_portion' => 'full',
            'reason'      => 'Approved holiday',
            'status'      => LeaveRequestModel::STATUS_APPROVED,
        ]);

        // Approved leave is not an absence — the manager must not be pinged.
        $this->assertStringContainsString('Nothing to report', (new AttendanceReminders())->absenceDigest());
        $this->assertCount(0, $this->notificationsFor($manager));
    }

    // ------------------------------------------------ missing check-out digest

    public function testMissingCheckoutDigestReportsOnlyUncorrectedDays(): void
    {
        Time::setTestNow(Time::parse('2026-07-24 04:00:00', 'UTC'));

        $userId  = $this->makeEmployee('flagged@example.com', 'Flagged Person');
        $manager = $this->makeHolder('head6@example.com', 'attendance.manage');
        $model   = model(AttendanceDayModel::class);

        $open = $model->checkin($userId, '10.0.0.1', '2026-07-22', '2026-07-22 01:30:00');
        $model->flagMissingCheckout([(int) $open->id]);

        $result = (new AttendanceReminders())->missingCheckoutDigest();
        $this->assertStringContainsString('Reported 1 day(s)', $result);
        $this->assertCount(1, $this->notificationsFor($manager));

        // Once corrected, it drops off the list.
        $model->applyCorrection((int) $open->id, ['checkout_at' => '2026-07-22 11:00:00'], $manager, '2026-07-24 04:00:00');
        $this->assertStringContainsString('No uncorrected', (new AttendanceReminders())->missingCheckoutDigest());
    }

    // ------------------------------------------------- pending requests digest

    public function testPendingRequestsDigestOnlyCountsStaleOnes(): void
    {
        $employee = $this->makeEmployee('waiting@example.com');
        $approver = $this->makeHolder('approver@example.com', 'requests.approve');
        $model    = model(LeaveRequestModel::class);

        // Fresh request: nothing to nag about yet.
        $model->insert([
            'user_id'    => $employee,
            'type'       => LeaveRequestModel::TYPE_LEAVE,
            'leave_kind' => 'annual',
            'start_date' => '2026-08-03',
            'end_date'   => '2026-08-03',
            'reason'     => 'Just submitted',
            'status'     => LeaveRequestModel::STATUS_PENDING,
        ]);

        $this->assertStringContainsString('No requests pending', (new AttendanceReminders())->pendingRequestsDigest());
        $this->assertCount(0, $this->notificationsFor($approver));

        // Backdate it two days — now it is overdue.
        $id = (int) $model->where('user_id', $employee)->first()->id;
        $this->db->table('leave_requests')->where('id', $id)->update(['created_at' => date('Y-m-d H:i:s', time() - 48 * 3600)]);

        $result = (new AttendanceReminders())->pendingRequestsDigest();
        $this->assertStringContainsString('1 pending request(s)', $result);
        $this->assertCount(1, $this->notificationsFor($approver));
    }

    public function testPendingRequestsDigestIgnoresDecidedRequests(): void
    {
        $employee = $this->makeEmployee('decided@example.com');
        $approver = $this->makeHolder('approver2@example.com', 'requests.approve');

        model(LeaveRequestModel::class)->insert([
            'user_id'    => $employee,
            'type'       => LeaveRequestModel::TYPE_LEAVE,
            'leave_kind' => 'annual',
            'start_date' => '2026-08-03',
            'end_date'   => '2026-08-03',
            'reason'     => 'Already approved',
            'status'     => LeaveRequestModel::STATUS_APPROVED,
        ]);
        $id = (int) model(LeaveRequestModel::class)->where('user_id', $employee)->first()->id;
        $this->db->table('leave_requests')->where('id', $id)->update(['created_at' => date('Y-m-d H:i:s', time() - 48 * 3600)]);

        $this->assertStringContainsString('No requests pending', (new AttendanceReminders())->pendingRequestsDigest());
        $this->assertCount(0, $this->notificationsFor($approver));
    }

    // ------------------------------------------------------- permission lookup

    public function testWithPermissionIncludesAdminZoneUsersAndExcludesInactiveOnes(): void
    {
        $roleHolder = $this->makeHolder('rolehead@example.com', 'attendance.manage');
        $adminZone  = (int) model(UserModel::class)->insert([
            'name' => 'Boss', 'email' => 'boss@example.com',
            'password' => password_hash('x', PASSWORD_DEFAULT), 'zone' => 'admin', 'status' => 1,
        ], true);
        $plain = $this->makeEmployee('plain@example.com');
        // Deactivated manager: still holds the role, must not be notified.
        $inactive = $this->makeHolder('retired@example.com', 'attendance.manage');
        model(UserModel::class)->update($inactive, ['status' => 0]);

        $ids = array_map(static fn (object $u): int => (int) $u->id, model(UserModel::class)->withPermission('attendance.manage'));

        $this->assertContains($roleHolder, $ids);
        // An admin-zone user is an implicit super-user, exactly as user_can() treats them.
        $this->assertContains($adminZone, $ids);
        $this->assertNotContains($plain, $ids);
        $this->assertNotContains($inactive, $ids);
    }

    // ----------------------------------------------- dispatch through the runner

    public function testHandlersRunThroughTheSharedDispatchPath(): void
    {
        Time::setTestNow(Time::parse('2026-07-22 11:30:00', 'UTC'));

        $userId = $this->makeEmployee('dispatch@example.com');
        model(AttendanceDayModel::class)->checkin($userId, '10.0.0.1', '2026-07-22', '2026-07-22 01:30:00');

        $task = model(App\Models\ScheduledTaskModel::class);
        $id   = (int) $task->insert([
            'name'             => 'Remind check-out',
            'handler'          => 'attendance.remind_checkout',
            'interval_minutes' => 15,
            'enabled'          => 1,
        ], true);

        $outcome = (new ScheduledTaskRunner())->runTask($task->findTask($id));

        $this->assertSame('success', $outcome['status']);
        $this->assertStringContainsString('Reminded 1', $outcome['result']);
        $this->assertCount(1, $this->notificationsFor($userId));
    }
}
