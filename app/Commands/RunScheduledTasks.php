<?php

namespace App\Commands;

use App\Libraries\ScheduledTaskRunner;
use App\Models\ScheduledTaskModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `php spark schedule:run` — invoked by OS cron every minute (see plan §8).
 * Dispatches every due `scheduled_tasks` row through
 * `ScheduledTaskRunner::runTask()` (the same dispatch path `runNow` uses), so
 * cron and the admin "Run now" action can never drift apart.
 */
class RunScheduledTasks extends BaseCommand
{
    protected $group       = 'App';
    protected $name        = 'schedule:run';
    protected $description = 'Runs every due scheduled task (recurring maintenance jobs).';
    protected $usage       = 'schedule:run [--task=<id>] [--force]';
    protected $options     = [
        '--task'  => 'Run one task by id instead of the due set.',
        '--force' => 'With --task, ignore its next_run_at and run it anyway.',
    ];

    public function run(array $params)
    {
        $taskModel = model(ScheduledTaskModel::class);
        $runner    = new ScheduledTaskRunner();

        $taskId = CLI::getOption('task');
        $tasks  = $taskId !== null ? $this->resolveSingleTask($taskModel, (int) $taskId) : $taskModel->dueTasks();

        if ($tasks === []) {
            CLI::write('No due tasks.', 'yellow');

            return;
        }

        foreach ($tasks as $task) {
            $outcome = $runner->runTask($task);
            $color   = $outcome['status'] === 'success' ? 'green' : 'red';

            CLI::write("[{$task->id}] {$task->name} ({$task->handler}): {$outcome['status']} - {$outcome['result']}", $color);
        }
    }

    /**
     * `--task=<id>` support: run one task by id. Without `--force`, still
     * honours its `next_run_at` (returns empty if not yet due); `--force`
     * ignores `next_run_at` and runs it regardless.
     *
     * @return list<object>
     */
    private function resolveSingleTask(ScheduledTaskModel $taskModel, int $taskId): array
    {
        $task = $taskModel->findTask($taskId);

        if ($task === null) {
            CLI::error("No such task: #{$taskId}");

            return [];
        }

        $isDue = $task->next_run_at === null || $task->next_run_at <= date('Y-m-d H:i:s');

        if (! CLI::getOption('force') && ! $isDue) {
            CLI::write("Task #{$taskId} is not due yet; use --force to run anyway.", 'yellow');

            return [];
        }

        return [$task];
    }
}
