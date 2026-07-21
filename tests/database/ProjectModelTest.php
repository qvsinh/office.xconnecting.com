<?php

use App\Models\ProjectMemberModel;
use App\Models\ProjectModel;
use App\Models\TaskColumnModel;
use App\Models\TaskModel;
use App\Models\UserModel;
use App\Models\WorkspaceMemberModel;
use App\Models\WorkspaceModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class ProjectModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    public function testCreateProjectDerivesSlugUniquePerWorkspace(): void
    {
        $projectModel   = new ProjectModel();
        $workspaceModel = new WorkspaceModel();

        $ownerId     = $this->createUser('owner@example.com');
        $workspaceId = $workspaceModel->createWorkspace('Workspace One', $ownerId);

        $id = $projectModel->createProject($workspaceId, 'Website Revamp', $ownerId);

        $project = $projectModel->findProject($id);
        $this->assertSame('website-revamp', $project->slug);
    }

    public function testFindInWorkspaceScopesToTheGivenWorkspace(): void
    {
        $projectModel   = new ProjectModel();
        $workspaceModel = new WorkspaceModel();

        $ownerId  = $this->createUser('owner2@example.com');
        $workspaceA = $workspaceModel->createWorkspace('Workspace A', $ownerId);
        $workspaceB = $workspaceModel->createWorkspace('Workspace B', $ownerId);

        $projectId = $projectModel->createProject($workspaceA, 'Scoped Project', $ownerId);

        $this->assertNotNull($projectModel->findInWorkspace($projectId, $workspaceA));
        $this->assertNull($projectModel->findInWorkspace($projectId, $workspaceB));
    }

    public function testForWorkspaceListsOnlyProjectsInThatWorkspace(): void
    {
        $projectModel   = new ProjectModel();
        $workspaceModel = new WorkspaceModel();

        $ownerId    = $this->createUser('owner3@example.com');
        $workspaceA = $workspaceModel->createWorkspace('Workspace A2', $ownerId);
        $workspaceB = $workspaceModel->createWorkspace('Workspace B2', $ownerId);

        $projectModel->createProject($workspaceA, 'Project A1', $ownerId);
        $projectModel->createProject($workspaceB, 'Project B1', $ownerId);

        $names = array_column($projectModel->forWorkspace($workspaceA), 'name');
        $this->assertSame(['Project A1'], $names);
    }

    public function testForUserIncludesDirectProjectMembersAndWorkspaceMembersFallback(): void
    {
        $projectModel        = new ProjectModel();
        $workspaceModel      = new WorkspaceModel();
        $projectMemberModel  = new ProjectMemberModel();
        $workspaceMemberModel = new WorkspaceMemberModel();

        $ownerId = $this->createUser('owner4@example.com');
        $directMemberId = $this->createUser('direct@example.com');
        $workspaceMemberId = $this->createUser('workspace-member@example.com');
        $strangerId = $this->createUser('stranger2@example.com');

        $workspaceId = $workspaceModel->createWorkspace('Fallback Workspace', $ownerId);
        $directProjectId = $projectModel->createProject($workspaceId, 'Direct Project', $ownerId);
        $fallbackProjectId = $projectModel->createProject($workspaceId, 'Fallback Project', $ownerId);

        $projectMemberModel->add($directProjectId, $directMemberId, 'member');
        $workspaceMemberModel->add($workspaceId, $workspaceMemberId, 'member');

        $directNames = array_column($projectModel->forUser($directMemberId), 'name');
        $this->assertContains('Direct Project', $directNames);
        $this->assertNotContains('Fallback Project', $directNames);

        // A workspace member (no direct project membership) sees every
        // project in that workspace via the workspace-membership fallback.
        $fallbackNames = array_column($projectModel->forUser($workspaceMemberId), 'name');
        $this->assertContains('Direct Project', $fallbackNames);
        $this->assertContains('Fallback Project', $fallbackNames);

        $this->assertSame([], $projectModel->forUser($strangerId));
    }

    public function testCountForUserMatchesForUserCountWithoutFetchingRows(): void
    {
        $projectModel       = new ProjectModel();
        $workspaceModel     = new WorkspaceModel();
        $projectMemberModel = new ProjectMemberModel();

        $ownerId = $this->createUser('owner5@example.com');
        $memberId = $this->createUser('member5@example.com');

        $workspaceId = $workspaceModel->createWorkspace('Count Workspace', $ownerId);
        $projectId   = $projectModel->createProject($workspaceId, 'Count Project', $ownerId);
        $projectModel->createProject($workspaceId, 'Other Project', $ownerId);

        $projectMemberModel->add($projectId, $memberId, 'member');

        $this->assertSame(1, $projectModel->countForUser($memberId));
        $this->assertSame([], $projectModel->forUser($this->createUser('stranger3@example.com')));
    }

    public function testRecentOrdersByNewestFirstAndRespectsLimit(): void
    {
        $projectModel   = new ProjectModel();
        $workspaceModel = new WorkspaceModel();

        $ownerId     = $this->createUser('recent-owner@example.com');
        $workspaceId = $workspaceModel->createWorkspace('Recent Workspace', $ownerId);

        $projectModel->createProject($workspaceId, 'Oldest', $ownerId);
        $projectModel->createProject($workspaceId, 'Middle', $ownerId);
        $projectModel->createProject($workspaceId, 'Newest', $ownerId);

        $names = array_column($projectModel->recent(2), 'name');

        $this->assertSame(['Newest', 'Middle'], $names);
    }

    public function testCountAllCountsOnlyNonSoftDeletedProjects(): void
    {
        $projectModel   = new ProjectModel();
        $workspaceModel = new WorkspaceModel();

        $ownerId     = $this->createUser('count-all-owner@example.com');
        $workspaceId = $workspaceModel->createWorkspace('Count All Workspace', $ownerId);

        $projectModel->createProject($workspaceId, 'Kept', $ownerId);
        $deletedId = $projectModel->createProject($workspaceId, 'Deleted', $ownerId);
        $projectModel->delete($deletedId);

        $this->assertSame(1, $projectModel->countAll());
    }

    public function testMostActiveOrdersByOpenTaskCountAndIncludesZeroOpenProjects(): void
    {
        $projectModel     = new ProjectModel();
        $workspaceModel   = new WorkspaceModel();
        $taskModel        = new TaskModel();
        $taskColumnModel  = new TaskColumnModel();

        $ownerId     = $this->createUser('active-owner@example.com');
        $workspaceId = $workspaceModel->createWorkspace('Active Workspace', $ownerId);

        $busyId    = $projectModel->createProject($workspaceId, 'Busy Project', $ownerId);
        $quietId   = $projectModel->createProject($workspaceId, 'Quiet Project', $ownerId);
        $doneOnlyId = $projectModel->createProject($workspaceId, 'All Done Project', $ownerId);

        $taskColumnModel->seedDefaults($busyId);
        $taskColumnModel->seedDefaults($quietId);
        $taskColumnModel->seedDefaults($doneOnlyId);

        $busyColumns    = $taskColumnModel->forProject($busyId);
        $quietColumns   = $taskColumnModel->forProject($quietId);
        $doneOnlyColumns = $taskColumnModel->forProject($doneOnlyId);
        $doneColumn     = static fn (array $columns) => $columns[2]; // 'Done' is seeded last

        // Busy: 2 open tasks.
        $taskModel->insert(['project_id' => $busyId, 'column_id' => $busyColumns[0]->id, 'title' => 'B1', 'created_by' => $ownerId]);
        $taskModel->insert(['project_id' => $busyId, 'column_id' => $busyColumns[1]->id, 'title' => 'B2', 'created_by' => $ownerId]);
        $taskModel->insert(['project_id' => $busyId, 'column_id' => $doneColumn($busyColumns)->id, 'title' => 'B3 Done', 'created_by' => $ownerId]);

        // Quiet: 1 open task.
        $taskModel->insert(['project_id' => $quietId, 'column_id' => $quietColumns[0]->id, 'title' => 'Q1', 'created_by' => $ownerId]);

        // All-done: only a Done task — must still appear with open_tasks = 0.
        $taskModel->insert(['project_id' => $doneOnlyId, 'column_id' => $doneColumn($doneOnlyColumns)->id, 'title' => 'D1 Done', 'created_by' => $ownerId]);

        $rows = $projectModel->mostActive(5);
        $byName = [];
        foreach ($rows as $row) {
            $byName[$row->name] = (int) $row->open_tasks;
        }

        $this->assertSame(2, $byName['Busy Project']);
        $this->assertSame(1, $byName['Quiet Project']);
        $this->assertSame(0, $byName['All Done Project']);
        $this->assertSame(['Busy Project', 'Quiet Project', 'All Done Project'], array_keys($byName));
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
