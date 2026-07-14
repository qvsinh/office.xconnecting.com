<?php

use App\Models\ScheduledTaskModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class ScheduledTaskModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    protected function setUp(): void
    {
        parent::setUp();

        // CreateScheduledTasks seeds 3 disabled default rows on a fresh
        // table (plan §7); tests want a clean slate for exact-count
        // assertions.
        (new ScheduledTaskModel())->builder()->emptyTable();
    }

    public function testFindTaskReturnsRowAndNullForUnknownId(): void
    {
        $model = new ScheduledTaskModel();
        $id    = $model->insert(['name' => 'Purge emails', 'handler' => 'emails.purge', 'interval_minutes' => 60]);

        $this->assertSame('Purge emails', $model->findTask($id)->name);
        $this->assertNull($model->findTask(999999));
    }

    public function testSearchAdminFiltersByKeywordAcrossNameHandlerAndDescription(): void
    {
        $model = new ScheduledTaskModel();
        $model->insert(['name' => 'Purge emails', 'handler' => 'emails.purge', 'interval_minutes' => 60, 'description' => 'Sent mail log']);
        $model->insert(['name' => 'Purge notifications', 'handler' => 'notifications.purge', 'interval_minutes' => 60, 'description' => 'User feed']);

        $byName = $model->searchAdmin(['keyword' => 'emails']);
        $this->assertCount(1, $byName);
        $this->assertSame('emails.purge', $byName[0]->handler);

        $byDescription = $model->searchAdmin(['keyword' => 'feed']);
        $this->assertCount(1, $byDescription);
        $this->assertSame('notifications.purge', $byDescription[0]->handler);
    }

    public function testSearchAdminFiltersByExactHandlerAndEnabled(): void
    {
        $model = new ScheduledTaskModel();
        $model->insert(['name' => 'A', 'handler' => 'emails.purge', 'interval_minutes' => 60, 'enabled' => 1]);
        $model->insert(['name' => 'B', 'handler' => 'emails.purge', 'interval_minutes' => 60, 'enabled' => 0]);
        $model->insert(['name' => 'C', 'handler' => 'api_history.purge', 'interval_minutes' => 60, 'enabled' => 1]);

        $this->assertCount(2, $model->searchAdmin(['handler' => 'emails.purge']));
        $this->assertCount(2, $model->searchAdmin(['enabled' => '1']));
        $this->assertCount(1, $model->searchAdmin(['handler' => 'emails.purge', 'enabled' => '0']));
    }

    public function testSearchAdminRejectsArbitrarySortColumnAndFallsBackToName(): void
    {
        $model = new ScheduledTaskModel();
        $model->insert(['name' => 'Zeta', 'handler' => 'emails.purge', 'interval_minutes' => 60]);
        $model->insert(['name' => 'Alpha', 'handler' => 'emails.purge', 'interval_minutes' => 60]);

        $rows = $model->searchAdmin([], 'last_result; DROP TABLE scheduled_tasks;', 'asc');

        // Falls back to default sort (name asc) rather than erroring or
        // injecting the malicious column into the query.
        $this->assertSame('Alpha', $rows[0]->name);
    }

    public function testCountAdminMatchesUnpaginatedRowCountForSameFilters(): void
    {
        $model = new ScheduledTaskModel();
        for ($i = 0; $i < 5; $i++) {
            $model->insert(['name' => "Task {$i}", 'handler' => 'emails.purge', 'interval_minutes' => 60]);
        }

        $filters = ['handler' => 'emails.purge'];
        $page    = $model->searchAdmin($filters, 'id', 'asc', 2, 0);

        $this->assertCount(2, $page);
        $this->assertSame(5, $model->countAdmin($filters));
    }

    public function testDueTasksSelectsEnabledWithNullOrPastNextRunAt(): void
    {
        $model = new ScheduledTaskModel();
        $due1  = $model->insert(['name' => 'Never run', 'handler' => 'emails.purge', 'interval_minutes' => 60, 'enabled' => 1, 'next_run_at' => null]);
        $due2  = $model->insert(['name' => 'Past due', 'handler' => 'emails.purge', 'interval_minutes' => 60, 'enabled' => 1, 'next_run_at' => date('Y-m-d H:i:s', time() - 3600)]);
        $model->insert(['name' => 'Future', 'handler' => 'emails.purge', 'interval_minutes' => 60, 'enabled' => 1, 'next_run_at' => date('Y-m-d H:i:s', time() + 3600)]);
        $model->insert(['name' => 'Disabled but due', 'handler' => 'emails.purge', 'interval_minutes' => 60, 'enabled' => 0, 'next_run_at' => null]);

        $dueIds = array_map(static fn (object $row): int => (int) $row->id, $model->dueTasks());

        $this->assertContains($due1, $dueIds);
        $this->assertContains($due2, $dueIds);
        $this->assertCount(2, $dueIds);
    }

    public function testMarkRunningSetsRunningStatusAndLastRunAt(): void
    {
        $model = new ScheduledTaskModel();
        $id    = $model->insert(['name' => 'Task', 'handler' => 'emails.purge', 'interval_minutes' => 60]);

        $model->markRunning($id);

        $row = $model->findTask($id);
        $this->assertSame('running', $row->last_status);
        $this->assertNotNull($row->last_run_at);
    }

    public function testRecordResultPersistsAllFieldsAndTruncatesLastResult(): void
    {
        $model  = new ScheduledTaskModel();
        $id     = $model->insert(['name' => 'Task', 'handler' => 'emails.purge', 'interval_minutes' => 60]);
        $nextAt = date('Y-m-d H:i:s', time() + 3600);

        $model->recordResult($id, 'success', str_repeat('x', 6000), 42, $nextAt);

        $row = $model->findTask($id);
        $this->assertSame('success', $row->last_status);
        $this->assertSame(5000, mb_strlen($row->last_result));
        $this->assertSame(42, (int) $row->last_duration_ms);
        $this->assertSame($nextAt, $row->next_run_at);
    }

    public function testSetEnabledFlipsTheFlag(): void
    {
        $model = new ScheduledTaskModel();
        $id    = $model->insert(['name' => 'Task', 'handler' => 'emails.purge', 'interval_minutes' => 60, 'enabled' => 1]);

        $model->setEnabled($id, false);
        $this->assertSame(0, (int) $model->findTask($id)->enabled);

        $model->setEnabled($id, true);
        $this->assertSame(1, (int) $model->findTask($id)->enabled);
    }
}
