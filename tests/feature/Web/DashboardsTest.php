<?php

use App\Models\GroupModel;
use App\Models\ProjectModel;
use App\Models\TaskColumnModel;
use App\Models\TaskModel;
use App\Models\UserModel;
use App\Models\WorkspaceMemberModel;
use App\Models\WorkspaceModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * `Admin\Dashboard`/`Client\Dashboard` (see
 * `kanban/processing/20260721-08-dashboards.md`): company-wide stats for
 * admins, personal stats + own-data-only scoping for clients, and the
 * `adminFilter` gate on `/admin/dashboard`.
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
        parent::tearDown();
        session()->destroy();
    }

    public function testAdminDashboardRendersWithCompanyWideStats(): void
    {
        $admin = $this->seedWebUser(['email' => 'company-admin@example.com', 'zone' => 'global', 'status' => 1]);

        model(GroupModel::class)->createGroup('Engineering');

        $ownerId     = (int) $this->seedWebUser(['email' => 'ws-owner@example.com'])->id;
        $workspaceModel = model(WorkspaceModel::class);
        $projectModel   = model(ProjectModel::class);
        $taskModel      = model(TaskModel::class);
        $columnModel    = model(TaskColumnModel::class);

        $workspaceId = $workspaceModel->createWorkspace('Marketing', $ownerId);
        $projectId   = $projectModel->createProject($workspaceId, 'Website Relaunch', $ownerId);
        $columnModel->seedDefaults($projectId);
        $columns = $columnModel->forProject($projectId);

        $taskModel->insert(['project_id' => $projectId, 'column_id' => $columns[0]->id, 'title' => 'Open Task', 'priority' => 'high', 'created_by' => $ownerId]);
        $taskModel->insert(['project_id' => $projectId, 'column_id' => $columns[2]->id, 'title' => 'Done Task', 'priority' => 'low', 'created_by' => $ownerId]);

        // Expected counts, computed independently of the controller under
        // test — the render assertion below must match these exactly.
        $expectedActiveUsers = model(UserModel::class)->countAdmin(['status' => 1]);
        $expectedOpenTasks   = $taskModel->countOpenAll();
        $expectedDoneTasks   = $taskModel->countDoneAll();

        $this->assertSame(1, $expectedOpenTasks);
        $this->assertSame(1, $expectedDoneTasks);

        $this->loginAs($admin);
        $result = $this->withSession()->get('/admin/dashboard');

        $result->assertStatus(200);
        $result->assertSee('Marketing');
        $result->assertSee('Website Relaunch');
        $result->assertSee((string) $expectedActiveUsers);
        $result->assertSee((string) $expectedOpenTasks);
        $result->assertSee((string) $expectedDoneTasks);
    }

    public function testAdminDashboardBlocksNonAdminClient(): void
    {
        $client = $this->seedWebUser(['email' => 'gate-client@example.com', 'zone' => 'client']);
        $this->loginAs($client);

        $result = $this->withSession()->get('/admin/dashboard');

        $result->assertRedirectTo('/dashboard');
    }

    public function testAdminDashboardRedirectsGuestToLogin(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $result = $this->get('/admin/dashboard');

        $result->assertRedirectTo('/login');
    }

    public function testClientDashboardShowsOnlyTheOwnersOwnTasksAndWorkspaces(): void
    {
        $owner     = $this->seedWebUser(['email' => 'my-dash@example.com', 'zone' => 'client']);
        $stranger  = $this->seedWebUser(['email' => 'not-mine@example.com', 'zone' => 'client']);
        $ownerId   = (int) $owner->id;

        $workspaceModel = model(WorkspaceModel::class);
        $projectModel   = model(ProjectModel::class);
        $taskModel      = model(TaskModel::class);
        $columnModel    = model(TaskColumnModel::class);

        $workspaceId = $workspaceModel->createWorkspace('My Workspace', $ownerId);
        model(WorkspaceMemberModel::class)->add($workspaceId, $ownerId, 'owner');

        $projectId = $projectModel->createProject($workspaceId, 'My Project', $ownerId);
        $columnModel->seedDefaults($projectId);
        $columns = $columnModel->forProject($projectId);

        $taskModel->insert(['project_id' => $projectId, 'column_id' => $columns[0]->id, 'title' => 'My Assigned Task', 'assignee_id' => $ownerId, 'created_by' => $ownerId]);
        $taskModel->insert(['project_id' => $projectId, 'column_id' => $columns[0]->id, 'title' => 'Someone Elses Task', 'assignee_id' => (int) $stranger->id, 'created_by' => $ownerId]);

        // Scoping asserted at the model layer, independent of the view.
        $myTaskTitles = array_column($taskModel->forAssignee($ownerId), 'title');
        $this->assertSame(['My Assigned Task'], $myTaskTitles);
        $this->assertSame(1, $workspaceModel->countForUser($ownerId));
        $this->assertSame(1, $projectModel->countForUser($ownerId));

        $this->loginAs($owner);
        $result = $this->withSession()->get('/dashboard');

        $result->assertStatus(200);
        $result->assertSee('My Assigned Task');
        $result->assertSee('My Workspace');
        $result->assertDontSee('Someone Elses Task');
    }

    public function testClientDashboardOpenTasksStatExcludesDoneColumn(): void
    {
        $owner   = $this->seedWebUser(['email' => 'open-stat@example.com', 'zone' => 'client']);
        $ownerId = (int) $owner->id;

        $workspaceModel = model(WorkspaceModel::class);
        $projectModel   = model(ProjectModel::class);
        $taskModel      = model(TaskModel::class);
        $columnModel    = model(TaskColumnModel::class);

        $workspaceId = $workspaceModel->createWorkspace('Stat Workspace', $ownerId);
        $projectId   = $projectModel->createProject($workspaceId, 'Stat Project', $ownerId);
        $columnModel->seedDefaults($projectId);
        $columns = $columnModel->forProject($projectId);

        $taskModel->insert(['project_id' => $projectId, 'column_id' => $columns[0]->id, 'title' => 'Still Open', 'assignee_id' => $ownerId, 'created_by' => $ownerId]);
        $taskModel->insert(['project_id' => $projectId, 'column_id' => $columns[2]->id, 'title' => 'Finished Up', 'assignee_id' => $ownerId, 'created_by' => $ownerId]);

        $this->loginAs($owner);
        $result = $this->withSession()->get('/dashboard');

        $result->assertStatus(200);
        $result->assertSee('Still Open');
        // The done task still shows up in "My tasks" (all assigned tasks),
        // just excluded from the open-tasks stat card.
        $result->assertSee('Finished Up');
    }
}
