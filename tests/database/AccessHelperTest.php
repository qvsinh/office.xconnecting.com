<?php

use App\Models\ProjectMemberModel;
use App\Models\ProjectModel;
use App\Models\UserModel;
use App\Models\WorkspaceMemberModel;
use App\Models\WorkspaceModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * `access_helper.php` against real `workspaces`/`workspace_members`/
 * `projects`/`project_members` rows — mirrors `UserCanHelperTest`'s use of a
 * real session + DB rather than mocks.
 *
 * @internal
 */
final class AccessHelperTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    protected function setUp(): void
    {
        parent::setUp();
        helper('auth');
        helper('access');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        session()->destroy();
    }

    public function testWorkspaceRoleReturnsTheMembersRole(): void
    {
        [$workspaceId, $userId] = $this->createWorkspaceWithMember('admin');
        $this->loginAsClient($userId);

        $this->assertSame('admin', workspace_role($workspaceId));
    }

    public function testWorkspaceRoleReturnsNullForANonMember(): void
    {
        [$workspaceId] = $this->createWorkspaceWithMember('member');
        $strangerId = $this->createUser('stranger@example.com');
        $this->loginAsClient($strangerId);

        $this->assertNull(workspace_role($workspaceId));
    }

    public function testWorkspaceRoleTreatsGlobalAdminsAsImplicitOwner(): void
    {
        [$workspaceId] = $this->createWorkspaceWithMember('member');
        $adminId = $this->createUser('admin-user@example.com', 'global');
        $this->loginAsAdmin($adminId);

        $this->assertSame('owner', workspace_role($workspaceId));
    }

    public function testProjectRoleReturnsTheDirectProjectMembersRole(): void
    {
        $ownerId     = $this->createUser('project-owner@example.com');
        $workspaceId = (new WorkspaceModel())->createWorkspace('Workspace', $ownerId);
        $projectId   = (new ProjectModel())->createProject($workspaceId, 'Project', $ownerId);
        $userId      = $this->createUser('project-member@example.com');

        (new ProjectMemberModel())->add($projectId, $userId, 'viewer');
        $this->loginAsClient($userId);

        $this->assertSame('viewer', project_role($projectId));
    }

    public function testProjectRoleFallsBackToWorkspaceRoleWhenNotADirectProjectMember(): void
    {
        $ownerId     = $this->createUser('fallback-owner@example.com');
        $workspaceId = (new WorkspaceModel())->createWorkspace('Fallback Workspace', $ownerId);
        $projectId   = (new ProjectModel())->createProject($workspaceId, 'Fallback Project', $ownerId);
        $userId      = $this->createUser('workspace-only@example.com');

        (new WorkspaceMemberModel())->add($workspaceId, $userId, 'admin');
        $this->loginAsClient($userId);

        $this->assertSame('admin', project_role($projectId));
    }

    public function testCanManageWorkspaceIsTrueOnlyForOwnerOrAdmin(): void
    {
        [$workspaceId, $memberUserId] = $this->createWorkspaceWithMember('member');
        $this->loginAsClient($memberUserId);
        $this->assertFalse(can_manage_workspace($workspaceId));

        $adminUserId = $this->createUser('workspace-admin@example.com');
        (new WorkspaceMemberModel())->add($workspaceId, $adminUserId, 'admin');
        $this->loginAsClient($adminUserId);
        $this->assertTrue(can_manage_workspace($workspaceId));
    }

    public function testCanEditProjectIsFalseForAViewer(): void
    {
        $ownerId     = $this->createUser('viewer-owner@example.com');
        $workspaceId = (new WorkspaceModel())->createWorkspace('Viewer Workspace', $ownerId);
        $projectId   = (new ProjectModel())->createProject($workspaceId, 'Viewer Project', $ownerId);
        $viewerId    = $this->createUser('viewer-user@example.com');

        (new ProjectMemberModel())->add($projectId, $viewerId, 'viewer');
        $this->loginAsClient($viewerId);

        $this->assertFalse(can_edit_project($projectId));
    }

    /**
     * @return array{0: int, 1: int} [workspaceId, memberUserId]
     */
    private function createWorkspaceWithMember(string $role): array
    {
        $ownerId     = $this->createUser('workspace-owner@example.com');
        $workspaceId = (new WorkspaceModel())->createWorkspace('Access Workspace', $ownerId);
        $userId      = $this->createUser('workspace-member-' . $role . '@example.com');

        (new WorkspaceMemberModel())->add($workspaceId, $userId, $role);

        return [$workspaceId, $userId];
    }

    private function createUser(string $email, string $zone = 'client'): int
    {
        return (int) (new UserModel())->insert([
            'name'     => $email,
            'email'    => $email,
            'password' => 'irrelevant-hash',
            'zone'     => $zone,
            'status'   => 1,
        ]);
    }

    private function loginAsClient(int $userId): void
    {
        session()->set('userAuthenticated', (object) ['id' => $userId, 'zone' => 'client']);
        session()->set('userWorking', (object) ['id' => $userId, 'zone' => 'client']);
    }

    private function loginAsAdmin(int $userId): void
    {
        session()->set('userAuthenticated', (object) ['id' => $userId, 'zone' => 'global']);
        session()->set('userWorking', (object) ['id' => $userId, 'zone' => 'global']);
    }
}
