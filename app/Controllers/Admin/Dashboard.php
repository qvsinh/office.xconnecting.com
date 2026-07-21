<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\GroupModel;
use App\Models\ProjectModel;
use App\Models\TaskModel;
use App\Models\UserModel;
use App\Models\WorkspaceModel;

/**
 * Company-wide admin dashboard: at-a-glance stat cards + recent/most-active
 * lists across users, groups, workspaces, projects, and tasks (see
 * `kanban/processing/20260721-08-dashboards.md`).
 */
class Dashboard extends BaseController
{
    public function index(): string
    {
        $taskModel = model(TaskModel::class);

        return view('admin/dashboard', [
            'user'  => session('userWorking'),
            'stats' => [
                'users'      => model(UserModel::class)->countAdmin(['status' => 1]),
                'groups'     => model(GroupModel::class)->countAll(),
                'workspaces' => model(WorkspaceModel::class)->countAll(),
                'projects'   => model(ProjectModel::class)->countAll(),
                'tasks_open' => $taskModel->countOpenAll(),
                'tasks_done' => $taskModel->countDoneAll(),
            ],
            'tasksByPriority'  => $taskModel->countByPriorityAll(),
            'recentWorkspaces' => model(WorkspaceModel::class)->recent(),
            'recentProjects'   => model(ProjectModel::class)->recent(),
            'activeProjects'   => model(ProjectModel::class)->mostActive(),
        ]);
    }
}
