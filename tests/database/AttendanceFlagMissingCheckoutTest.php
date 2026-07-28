<?php

use App\Libraries\ScheduledTaskRunner;
use App\Models\AttendanceDayModel;
use App\Models\ScheduledTaskModel;
use App\Models\UserModel;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * The `attendance.flag_missing_checkout` scheduled handler: it must flag
 * yesterday's forgotten check-outs, leave today's still-open day alone, and
 * never invent a check-out time (fabricated payroll input is worse than an
 * incomplete row a human has to look at).
 *
 * @internal
 */
final class AttendanceFlagMissingCheckoutTest extends CIUnitTestCase
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

    private function makeUser(string $email): int
    {
        return (int) model(UserModel::class)->insert([
            'name'     => 'Employee',
            'email'    => $email,
            'password' => password_hash('secret-password', PASSWORD_DEFAULT),
            'zone'     => 'user',
            'status'   => 1,
        ], true);
    }

    /**
     * Runs the handler the same way cron does — through `runTask()`, so the
     * dispatch path itself is covered too.
     *
     * @return array{status: string, result: string}
     */
    private function runHandler(): array
    {
        $taskId = (int) model(ScheduledTaskModel::class)->insert([
            'name'             => 'Flag missing check-outs',
            'handler'          => 'attendance.flag_missing_checkout',
            'interval_minutes' => 60,
            'enabled'          => 1,
        ], true);

        $task = model(ScheduledTaskModel::class)->findTask($taskId);

        return (new ScheduledTaskRunner())->runTask($task);
    }

    public function testMigrationSeedsTheScheduleAndTheHandlerIsAllowlisted(): void
    {
        $this->assertContains('attendance.flag_missing_checkout', (new ScheduledTaskRunner())->handlerKeys());
        $this->seeInDatabase('scheduled_tasks', ['handler' => 'attendance.flag_missing_checkout', 'enabled' => 1]);
    }

    public function testFlagsYesterdaysOpenDayButNotTodaysAndNotACompletedOne(): void
    {
        Time::setTestNow(Time::parse('2026-07-22 04:00:00', 'UTC')); // 11:00 in Vietnam

        $model = model(AttendanceDayModel::class);

        $forgotten = $model->checkin($this->makeUser('forgot@example.com'), '10.0.0.1', '2026-07-21', '2026-07-21 01:30:00');
        $stillOpen = $model->checkin($this->makeUser('working@example.com'), '10.0.0.2', '2026-07-22', '2026-07-22 01:30:00');
        $completed = $model->checkin($this->makeUser('done@example.com'), '10.0.0.3', '2026-07-21', '2026-07-21 01:30:00');
        $model->checkout((int) $completed->id, '10.0.0.3', '2026-07-21 11:00:00');

        $outcome = $this->runHandler();

        $this->assertSame('success', $outcome['status']);
        $this->assertStringContainsString('Flagged 1 day(s)', $outcome['result']);

        $this->assertSame(1, (int) $model->findDay((int) $forgotten->id)->missing_checkout);
        $this->assertSame(0, (int) $model->findDay((int) $stillOpen->id)->missing_checkout);
        $this->assertSame(0, (int) $model->findDay((int) $completed->id)->missing_checkout);
    }

    public function testNeverInventsACheckoutTime(): void
    {
        Time::setTestNow(Time::parse('2026-07-22 04:00:00', 'UTC'));

        $model     = model(AttendanceDayModel::class);
        $forgotten = $model->checkin($this->makeUser('forgot2@example.com'), '10.0.0.1', '2026-07-21', '2026-07-21 01:30:00');

        $this->runHandler();

        // Flagged, but the check-out is still empty — a human has to decide.
        $row = $model->findDay((int) $forgotten->id);
        $this->assertSame(1, (int) $row->missing_checkout);
        $this->assertNull($row->checkout_at);
    }

    public function testIsIdempotentAndReportsWhenThereIsNothingToDo(): void
    {
        Time::setTestNow(Time::parse('2026-07-22 04:00:00', 'UTC'));

        $model = model(AttendanceDayModel::class);
        $model->checkin($this->makeUser('forgot3@example.com'), '10.0.0.1', '2026-07-21', '2026-07-21 01:30:00');

        $this->assertStringContainsString('Flagged 1 day(s)', $this->runHandler()['result']);
        // Second pass finds nothing new — safe under hourly cron.
        $this->assertStringContainsString('No open attendance rows', $this->runHandler()['result']);
    }

    public function testTheVietnamCalendarDecidesWhichDayIsYesterday(): void
    {
        // 17:30 UTC on the 21st is already 00:30 on the 22nd in Vietnam, so
        // "yesterday" is the 21st — a UTC-based handler would look at the 20th.
        Time::setTestNow(Time::parse('2026-07-21 17:30:00', 'UTC'));

        $model     = model(AttendanceDayModel::class);
        $forgotten = $model->checkin($this->makeUser('forgot4@example.com'), '10.0.0.1', '2026-07-21', '2026-07-21 01:30:00');

        $this->runHandler();

        $this->assertSame(1, (int) $model->findDay((int) $forgotten->id)->missing_checkout);
    }
}
