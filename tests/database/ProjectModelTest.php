<?php

use App\Models\ProjectMemberModel;
use App\Models\ProjectModel;
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
