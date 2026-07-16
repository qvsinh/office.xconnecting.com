<?php

use App\Models\ScheduledTaskModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;

/**
 * `php spark schedule:run` — real routing through the command layer, real
 * `emails`/`notifications` rows, and the security-critical unknown-handler
 * error path (plan §5 Phase 3.3).
 *
 * @internal
 */
final class RunScheduledTasksCommandTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    protected function setUp(): void
    {
        parent::setUp();

        // CreateScheduledTasks seeds 3 disabled default rows on a fresh
        // table; these tests want a clean slate.
        (new ScheduledTaskModel())->builder()->emptyTable();
    }

    public function testCommandPurgesOldEmailsAndAdvancesBookkeeping(): void
    {
        $db = Database::connect();
        $db->table('emails')->insert([
            'id' => 'old-1', 'subject' => 'old', 'content' => 'x', 'from_email' => 'a@b.com',
            'created_at' => date('Y-m-d H:i:s', time() - (10 * 86400)),
        ]);
        $db->table('emails')->insert([
            'id' => 'recent-1', 'subject' => 'recent', 'content' => 'x', 'from_email' => 'a@b.com',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $model = new ScheduledTaskModel();
        $id    = $model->insert([
            'name' => 'Purge emails', 'handler' => 'emails.purge', 'params' => json_encode(['days' => 7]),
            'interval_minutes' => 60, 'enabled' => 1, 'next_run_at' => null,
        ]);

        command('schedule:run');

        $this->assertSame(0, $db->table('emails')->where('id', 'old-1')->countAllResults());
        $this->assertSame(1, $db->table('emails')->where('id', 'recent-1')->countAllResults());

        $task = $model->findTask($id);
        $this->assertSame('success', $task->last_status);
        $this->assertStringContainsString('Purged 1 row(s)', $task->last_result);
        $this->assertNotNull($task->last_run_at);
        $this->assertGreaterThan(time(), strtotime($task->next_run_at));
    }

    public function testCommandPurgesOldNotifications(): void
    {
        $db = Database::connect();
        $old    = date('Y-m-d H:i:s', time() - (40 * 86400));
        $recent = date('Y-m-d H:i:s');

        $db->table('notifications')->insert(['message' => 'old', 'created_at' => $old]);
        $db->table('notifications')->insert(['message' => 'recent', 'created_at' => $recent]);

        $model = new ScheduledTaskModel();
        $model->insert(['name' => 'Purge notifications', 'handler' => 'notifications.purge', 'interval_minutes' => 60, 'enabled' => 1, 'next_run_at' => null]);

        command('schedule:run');

        $this->assertSame(0, $db->table('notifications')->where('message', 'old')->countAllResults());
        $this->assertSame(1, $db->table('notifications')->where('message', 'recent')->countAllResults());
    }

    public function testCommandSkipsFutureAndDisabledTasks(): void
    {
        $model  = new ScheduledTaskModel();
        $future = $model->insert([
            'name' => 'Future', 'handler' => 'emails.purge', 'interval_minutes' => 60,
            'enabled' => 1, 'next_run_at' => date('Y-m-d H:i:s', time() + 3600),
        ]);
        $disabled = $model->insert([
            'name' => 'Disabled', 'handler' => 'emails.purge', 'interval_minutes' => 60,
            'enabled' => 0, 'next_run_at' => null,
        ]);

        command('schedule:run');

        $this->assertNull($model->findTask($future)->last_status);
        $this->assertNull($model->findTask($disabled)->last_status);
    }

    /**
     * CRITICAL security path: a `handler` value outside the fixed allowlist
     * (inserted directly via the model, bypassing controller validation)
     * must record `last_status='error'` and must NOT abort the batch — the
     * legacy DB-driven class/method reflection is exactly what this
     * allowlist replaces (plan §4).
     */
    public function testUnknownHandlerRecordsErrorAndDoesNotAbortTheBatch(): void
    {
        $model   = new ScheduledTaskModel();
        $badId   = $model->insert(['name' => 'Tampered', 'handler' => 'does.not.exist', 'interval_minutes' => 60, 'enabled' => 1, 'next_run_at' => null]);
        $goodId  = $model->insert(['name' => 'Good', 'handler' => 'notifications.purge', 'interval_minutes' => 60, 'enabled' => 1, 'next_run_at' => null]);

        command('schedule:run');

        $badTask = $model->findTask($badId);
        $this->assertSame('error', $badTask->last_status);
        $this->assertStringContainsString('Unknown handler', $badTask->last_result);

        // The sibling task still ran despite the tampered row.
        $goodTask = $model->findTask($goodId);
        $this->assertSame('success', $goodTask->last_status);
    }

    public function testCommandReportsNoDueTasks(): void
    {
        command('schedule:run');

        $this->assertStringContainsString('No due tasks', $this->getStreamFilterBuffer());
    }
}
