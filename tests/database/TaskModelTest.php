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

    public function testCountByPriorityAllCoversEveryProject(): void
    {
        $taskModel  = new TaskModel();
        $projectA   = $this->createProject();
        $projectB   = $this->createProject();
        $userId     = $this->createUser('priority-all@example.com');

        $taskModel->insert(['project_id' => $projectA, 'title' => 'A urgent', 'priority' => 'urgent', 'created_by' => $userId]);
        $taskModel->insert(['project_id' => $projectB, 'title' => 'B urgent', 'priority' => 'urgent', 'created_by' => $userId]);
        $taskModel->insert(['project_id' => $projectB, 'title' => 'B low', 'priority' => 'low', 'created_by' => $userId]);

        $this->assertSame(['low' => 1, 'normal' => 0, 'high' => 0, 'urgent' => 2], $taskModel->countByPriorityAll());
    }

    public function testCountOpenForProjectAndCountOpenAllExcludeDoneColumn(): void
    {
        $taskModel   = new TaskModel();
        $columnModel = new TaskColumnModel();
        $projectId   = $this->createProject();
        $userId      = $this->createUser('open@example.com');

        $columnModel->seedDefaults($projectId);
        $columns = $columnModel->forProject($projectId);

        $taskModel->insert(['project_id' => $projectId, 'column_id' => $columns[0]->id, 'title' => 'To Do', 'created_by' => $userId]);
        $taskModel->insert(['project_id' => $projectId, 'column_id' => null, 'title' => 'No column yet', 'created_by' => $userId]);
        $taskModel->insert(['project_id' => $projectId, 'column_id' => $columns[2]->id, 'title' => 'Finished', 'created_by' => $userId]);

        $this->assertSame(2, $taskModel->countOpenForProject($projectId));
        $this->assertSame(1, $taskModel->countDoneAll());
        $this->assertSame(2, $taskModel->countOpenAll());
    }

    public function testDueSoonForUserAndCountDueSoonForUserExcludeDoneAndOutOfRange(): void
    {
        $taskModel   = new TaskModel();
        $columnModel = new TaskColumnModel();
        $projectId   = $this->createProject();
        $assignee    = $this->createUser('due-soon@example.com');

        $columnModel->seedDefaults($projectId);
        $columns = $columnModel->forProject($projectId);

        $soon      = date('Y-m-d', strtotime('+2 days'));
        $tooFar    = date('Y-m-d', strtotime('+30 days'));
        $overdue   = date('Y-m-d', strtotime('-1 day'));

        $taskModel->insert(['project_id' => $projectId, 'column_id' => $columns[0]->id, 'title' => 'Due Soon', 'due_date' => $soon, 'assignee_id' => $assignee, 'created_by' => $assignee]);
        $taskModel->insert(['project_id' => $projectId, 'column_id' => $columns[0]->id, 'title' => 'Too Far', 'due_date' => $tooFar, 'assignee_id' => $assignee, 'created_by' => $assignee]);
        $taskModel->insert(['project_id' => $projectId, 'column_id' => $columns[2]->id, 'title' => 'Done But Soon', 'due_date' => $soon, 'assignee_id' => $assignee, 'created_by' => $assignee]);
        $taskModel->insert(['project_id' => $projectId, 'column_id' => $columns[0]->id, 'title' => 'Overdue', 'due_date' => $overdue, 'assignee_id' => $assignee, 'created_by' => $assignee]);

        $titles = array_column($taskModel->dueSoonForUser($assignee, 7), 'title');

        $this->assertSame(['Due Soon'], $titles);
        $this->assertSame(1, $taskModel->countDueSoonForUser($assignee, 7));
    }

    public function testForAssigneeAttachesColumnName(): void
    {
        $taskModel   = new TaskModel();
        $columnModel = new TaskColumnModel();
        $projectId   = $this->createProject();
        $assignee    = $this->createUser('column-name@example.com');

        $columnModel->seedDefaults($projectId);
        $columns = $columnModel->forProject($projectId);

        $taskModel->insert(['project_id' => $projectId, 'column_id' => $columns[2]->id, 'title' => 'Wrapped Up', 'assignee_id' => $assignee, 'created_by' => $assignee]);

        $tasks = $taskModel->forAssignee($assignee);

        $this->assertSame('Done', $tasks[0]->column_name);
    }

    private function createProject(): int
    {
        static $n = 0;
        $n++;

        $ownerId     = $this->createUser('project-owner-' . $n . '@example.com');
        $workspaceId = (new WorkspaceModel())->createWorkspace('Task Workspace ' . $n, $ownerId);

        return (new ProjectModel())->createProject($workspaceId, 'Task Project ' . $n, $ownerId);
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
