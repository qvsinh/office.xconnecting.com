<?php

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

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

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

        $ownerId  = $this->createUser('owner2@example.com');
        $memberId = $this->createUser('member@example.com');
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
    }

    public function testWorkspaceMemberRoleForAddAndRemove(): void
    {
        $model       = new WorkspaceModel();
        $memberModel = new WorkspaceMemberModel();

        $ownerId = $this->createUser('owner4@example.com');
        $userId  = $this->createUser('role-user@example.com');
        $workspaceId = $model->createWorkspace('Role Workspace', $ownerId);

        $this->assertNull($memberModel->roleFor($workspaceId, $userId));

        $memberModel->add($workspaceId, $userId, 'admin');
        $this->assertSame('admin', $memberModel->roleFor($workspaceId, $userId));

        $memberModel->remove($workspaceId, $userId);
        $this->assertNull($memberModel->roleFor($workspaceId, $userId));
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
