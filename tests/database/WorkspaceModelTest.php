<?php

use App\Models\ProjectModel;
use App\Models\UserModel;
use App\Models\WorkspaceMemberModel;
use App\Models\WorkspaceModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class WorkspaceModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace;

    public function testCreateWorkspaceDerivesUniqueSlug(): void
    {
        $model  = new WorkspaceModel();
        $userId = $this->createUser('owner@example.com');

        $id = $model->createWorkspace('Design Team', $userId, 'UI/UX crew');

        $workspace = $model->findWorkspace($id);
        $this->assertSame('Design Team', $workspace->name);
        $this->assertSame('design-team', $workspace->slug);
        $this->assertSame($userId, (int) $workspace->owner_id);
    }

    public function testForUserReturnsOwnedMemberAndCompanyWorkspacesOnly(): void
    {
        $model       = new WorkspaceModel();
        $memberModel = new WorkspaceMemberModel();

        $ownerId    = $this->createUser('owner2@example.com');
        $memberId   = $this->createUser('member@example.com');
        $strangerId = $this->createUser('stranger@example.com');

        $ownedId   = $model->createWorkspace('Owned Workspace', $ownerId);
        $joinedId  = $model->createWorkspace('Joined Workspace', $ownerId);
        $companyId = $model->createWorkspace('Company Workspace', $ownerId, null, 'company');
        $model->createWorkspace('Unrelated Workspace', $ownerId);

        $memberModel->add($joinedId, $memberId, 'member');

        $names = array_column($model->forUser($memberId), 'name');

        $this->assertContains('Joined Workspace', $names);
        $this->assertContains('Company Workspace', $names);
        $this->assertNotContains('Unrelated Workspace', $names);
        $this->assertNotContains('Owned Workspace', $names);

        // The owner sees their own workspace plus the company-wide one.
        $ownerNames = array_column($model->forUser($ownerId), 'name');
        $this->assertContains('Owned Workspace', $ownerNames);

        // A stranger with no membership/ownership only sees company ones.
        $strangerNames = array_column($model->forUser($strangerId), 'name');
        $this->assertSame(['Company Workspace'], $strangerNames);
        $this->assertSame($companyId, (int) $model->findWorkspace($companyId)->id);
    }

    public function testForUserDoesNotDuplicateAWorkspaceWithMultipleMembers(): void
    {
        $model       = new WorkspaceModel();
        $memberModel = new WorkspaceMemberModel();

        $ownerId = $this->createUser('owner3@example.com');
        $userId  = $this->createUser('shared@example.com');
        $otherId = $this->createUser('other@example.com');

        $workspaceId = $model->createWorkspace('Shared Workspace', $ownerId);
        $memberModel->add($workspaceId, $userId, 'member');
        $memberModel->add($workspaceId, $otherId, 'member');

        $rows = $model->forUser($userId);

        $this->assertCount(1, $rows);
        $this->assertSame(2, (int) $rows[0]->member_count);
        $this->assertSame('member', $rows[0]->role);
    }

    public function testForUserReturnsCallersOwnRoleAndProjectCount(): void
    {
        $model        = new WorkspaceModel();
        $memberModel  = new WorkspaceMemberModel();
        $projectModel = new ProjectModel();

        $ownerId    = $this->createUser('owner5@example.com');
        $strangerId = $this->createUser('stranger2@example.com');

        $workspaceId = $model->createWorkspace('Role Aware Workspace', $ownerId);
        $memberModel->add($workspaceId, $ownerId, 'owner');
        $projectModel->createProject($workspaceId, 'First Project', $ownerId);

        $ownerRow = $model->forUser($ownerId)[0];
        $this->assertSame('owner', $ownerRow->role);
        $this->assertSame(1, (int) $ownerRow->project_count);

        // Visible only via `visibility='company'`, not yet joined: no role.
        $companyId  = $model->createWorkspace('Open Workspace', $ownerId, null, 'company');
        $companyRow = array_values(array_filter($model->forUser($strangerId), static fn ($row) => (int) $row->id === $companyId))[0];
        $this->assertNull($companyRow->role);
    }

    public function testWorkspaceMemberRoleForAddAndRemove(): void
    {
        $model       = new WorkspaceModel();
        $memberModel = new WorkspaceMemberModel();

        $ownerId     = $this->createUser('owner4@example.com');
        $userId      = $this->createUser('role-user@example.com');
        $workspaceId = $model->createWorkspace('Role Workspace', $ownerId);

        $this->assertNull($memberModel->roleFor($workspaceId, $userId));

        $memberModel->add($workspaceId, $userId, 'admin');
        $this->assertSame('admin', $memberModel->roleFor($workspaceId, $userId));

        $memberModel->remove($workspaceId, $userId);
        $this->assertNull($memberModel->roleFor($workspaceId, $userId));
    }

    public function testCountForUserMatchesForUserCountWithoutFetchingRows(): void
    {
        $model       = new WorkspaceModel();
        $memberModel = new WorkspaceMemberModel();

        $ownerId    = $this->createUser('owner6@example.com');
        $memberId   = $this->createUser('member6@example.com');
        $strangerId = $this->createUser('stranger6@example.com');

        $joinedId = $model->createWorkspace('Counted Workspace', $ownerId);
        $model->createWorkspace('Company Countable', $ownerId, null, 'company');
        $model->createWorkspace('Unrelated Countable', $ownerId);

        $memberModel->add($joinedId, $memberId, 'member');

        $this->assertSame(2, $model->countForUser($memberId));
        $this->assertSame(1, $model->countForUser($strangerId));
        $this->assertSame(count($model->forUser($ownerId)), $model->countForUser($ownerId));
    }

    public function testRecentOrdersByNewestFirstAndRespectsLimit(): void
    {
        $model  = new WorkspaceModel();
        $userId = $this->createUser('recent-owner@example.com');

        $model->createWorkspace('Oldest', $userId);
        $model->createWorkspace('Middle', $userId);
        $model->createWorkspace('Newest', $userId);

        $names = array_column($model->recent(2), 'name');

        $this->assertSame(['Newest', 'Middle'], $names);
    }

    public function testCountAllCountsOnlyNonSoftDeletedWorkspaces(): void
    {
        $model  = new WorkspaceModel();
        $userId = $this->createUser('count-all-owner@example.com');

        $model->createWorkspace('Kept One', $userId);
        $deletedId = $model->createWorkspace('Deleted One', $userId);
        $model->delete($deletedId);

        $this->assertSame(1, $model->countAll());
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
