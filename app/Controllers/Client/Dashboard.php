<?php

namespace App\Controllers\Client;

use App\Controllers\BaseController;
use App\Models\ProjectModel;
use App\Models\TaskModel;
use App\Models\WorkspaceModel;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Personal dashboard: the signed-in user's own workspaces/projects/tasks
 * summary (see `kanban/processing/20260721-08-dashboards.md`).
 */
class Dashboard extends BaseController
{
    /**
     * How many workspace cards the dashboard shows — a quick-links widget,
     * not the full list (`Workspaces::index()` has that).
     */
    private const WORKSPACE_PREVIEW_LIMIT = 5;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        helper('auth');
    }

    public function index(): string
    {
        $userId    = current_user_id();
        $taskModel = model(TaskModel::class);

        $myTasks = $taskModel->forAssignee($userId);
        $this->annotateOverdue($myTasks);

        return view('client/dashboard', [
            'user'  => session('userWorking'),
            'stats' => [
                'workspaces' => model(WorkspaceModel::class)->countForUser($userId),
                'projects'   => model(ProjectModel::class)->countForUser($userId),
                'open_tasks' => $this->countOpen($myTasks),
                'due_soon'   => $taskModel->countDueSoonForUser($userId),
            ],
            'myTasks'      => $myTasks,
            'myWorkspaces' => array_slice(model(WorkspaceModel::class)->forUser($userId), 0, self::WORKSPACE_PREVIEW_LIMIT),
        ]);
    }

    /**
     * Stamps `is_overdue` on each task (`due_date` in the past, not in a
     * `Done` column) — computed here rather than in SQL since it's a cheap
     * per-row check over an already-fetched, per-user-bounded list.
     *
     * @param list<object> $tasks
     */
    private function annotateOverdue(array $tasks): void
    {
        $today = date('Y-m-d');

        foreach ($tasks as $task) {
            $task->is_overdue = $task->due_date !== null
                && $task->due_date < $today
                && $task->column_name !== 'Done';
        }
    }

    /**
     * @param list<object> $tasks annotated by `forAssignee()`'s `column_name`
     */
    private function countOpen(array $tasks): int
    {
        return count(array_filter($tasks, static fn (object $task): bool => $task->column_name !== 'Done'));
    }
}
