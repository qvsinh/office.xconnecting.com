<?php

use App\Models\ProjectModel;
use App\Models\TaskColumnModel;
use App\Models\TaskModel;
use App\Models\UserModel;
use App\Models\WorkspaceModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class TaskModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    public function testSeedDefaultsCreatesTheThreeStandardColumns(): void
    {
        $columnModel = new TaskColumnModel();
        $projectId   = $this->createProject();

        $columnModel->seedDefaults($projectId);

        $columns = $columnModel->forProject($projectId);

        $this->assertCount(3, $columns);
        $this->assertSame(['To Do', 'In Progress', 'Done'], array_column($columns, 'name'));
        $this->assertSame([0, 1, 2], array_map('intval', array_column($columns, 'position')));
    }

    public function testForProjectOrdersByColumnThenPositionAndAttachesAssigneeName(): void
    {
        $taskModel   = new TaskModel();
        $columnModel = new TaskColumnModel();
        $projectId   = $this->createProject();
        $userId      = $this->createUser('assignee@example.com');

        $columnModel->seedDefaults($projectId);
        $columns = $columnModel->forProject($projectId);

        $taskModel->insert(['project_id' => $projectId, 'column_id' => $columns[0]->id, 'title' => 'Second', 'position' => 1, 'assignee_id' => $userId, 'created_by' => $userId]);
        $taskModel->insert(['project_id' => $projectId, 'column_id' => $columns[0]->id, 'title' => 'First', 'position' => 0, 'created_by' => $userId]);

        $tasks = $taskModel->forProject($projectId);

        $this->assertSame(['First', 'Second'], array_column($tasks, 'title'));
        $this->assertSame('assignee@example.com', $tasks[1]->assignee_name);
    }

    public function testForAssigneeReturnsOnlyTasksAssignedToThatUser(): void
    {
        $taskModel = new TaskModel();
        $projectId = $this->createProject();
        $assignee  = $this->createUser('mine@example.com');
        $other     = $this->createUser('other-assignee@example.com');

        $taskModel->insert(['project_id' => $projectId, 'title' => 'My Task', 'assignee_id' => $assignee, 'created_by' => $assignee]);
        $taskModel->insert(['project_id' => $projectId, 'title' => 'Not Mine', 'assignee_id' => $other, 'created_by' => $other]);

        $titles = array_column($taskModel->forAssignee($assignee), 'title');

        $this->assertSame(['My Task'], $titles);
    }

    public function testReorderUpdatesColumnAndPosition(): void
    {
        $taskModel   = new TaskModel();
        $columnModel = new TaskColumnModel();
        $projectId   = $this->createProject();
        $userId      = $this->createUser('reorder@example.com');

        $columnModel->seedDefaults($projectId);
        $columns = $columnModel->forProject($projectId);

        $taskId = (int) $taskModel->insert(['project_id' => $projectId, 'column_id' => $columns[0]->id, 'title' => 'Movable', 'position' => 0, 'created_by' => $userId]);

        $taskModel->reorder($taskId, $columns[2]->id, 3);

        $task = $taskModel->findTask($taskId);
        $this->assertSame($columns[2]->id, $task->column_id);
        $this->assertSame(3, (int) $task->position);
    }

    public function testCountByPriorityIncludesEveryPriorityBucket(): void
    {
        $taskModel = new TaskModel();
        $projectId = $this->createProject();
        $userId    = $this->createUser('priority@example.com');

        $taskModel->insert(['project_id' => $projectId, 'title' => 'Urgent one', 'priority' => 'urgent', 'created_by' => $userId]);
        $taskModel->insert(['project_id' => $projectId, 'title' => 'Urgent two', 'priority' => 'urgent', 'created_by' => $userId]);
        $taskModel->insert(['project_id' => $projectId, 'title' => 'Normal one', 'priority' => 'normal', 'created_by' => $userId]);

        $counts = $taskModel->countByPriority($projectId);

        $this->assertSame(['low' => 0, 'normal' => 1, 'high' => 0, 'urgent' => 2], $counts);
    }

    private function createProject(): int
    {
        $ownerId     = $this->createUser('project-owner@example.com');
        $workspaceId = (new WorkspaceModel())->createWorkspace('Task Workspace', $ownerId);

        return (new ProjectModel())->createProject($workspaceId, 'Task Project', $ownerId);
    }

    private function createUser(string $email): int
    {
        return (int) (new UserModel())->insert([
            'name'     => $email,
            'email'    => $email,
            'password' => 'irrelevant-hash',
            'zone'     => 'client',
            'status'   => 1,
        ]);
    }
}
