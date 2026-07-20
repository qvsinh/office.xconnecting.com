<?php

use App\Models\GroupMemberModel;
use App\Models\GroupModel;
use App\Models\WorkspaceMemberModel;
use App\Models\WorkspaceModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * User-created workspaces (`Workspaces`), exercised through real routing +
 * filters (`authFilter`) + CSRF: create/join/leave, owner/admin membership
 * management (incl. group bulk-add), and the `workspace_role()`/
 * `can_manage_workspace()` IDOR guard. `index()`/`show()` render views that
 * don't exist yet (`frontend-coder` builds them next) — this suite only
 * exercises the non-member redirect on `show()` (filters/controller logic
 * run before the view, so the view's absence doesn't matter there), plus
 * every JSON endpoint in full.
 *
 * @internal
 */
final class WorkspacesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use WebAuthFixture;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace;

    protected function tearDown(): void
    {
        parent::tearDown();
        session()->destroy();
    }

    public function testCreateMakesCreatorTheOwnerAndListedInForUser(): void
    {
        $user = $this->seedWebUser(['email' => 'creator@example.com']);
        $this->loginAs($user);

        $create = $this->withSession()->post('/workspaces/create', $this->withCsrf([
            'name'        => 'Design Team',
            'description' => 'UI/UX crew',
            'visibility'  => 'private',
        ]));

        $create->assertStatus(200);
        $json = json_decode($create->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertArrayHasKey('id', $json);

        $id = (int) $json['id'];
        $this->assertSame('owner', model(WorkspaceMemberModel::class)->roleFor($id, (int) $user->id));

        $names = array_column(model(WorkspaceModel::class)->forUser((int) $user->id), 'name');
        $this->assertContains('Design Team', $names);
    }

    public function testCreateRejectsMissingName(): void
    {
        $user = $this->seedWebUser(['email' => 'creator2@example.com']);
        $this->loginAs($user);

        $result = $this->withSession()->post('/workspaces/create', $this->withCsrf(['name' => '']));

        $result->assertStatus(400);
        $this->assertSame(0, model(WorkspaceModel::class)->countAllResults());
    }

    public function testShowRedirectsNonMemberToIndex(): void
    {
        $owner   = $this->seedWebUser(['email' => 'owner@example.com']);
        $id      = model(WorkspaceModel::class)->createWorkspace('Private Space', (int) $owner->id);
        $outside = $this->seedWebUser(['email' => 'outsider@example.com']);
        $this->loginAs($outside);

        $show = $this->withSession()->get('/workspaces/' . $id);

        $show->assertRedirectTo('/workspaces');
    }

    public function testShowRedirectsWhenWorkspaceDoesNotExist(): void
    {
        $user = $this->seedWebUser(['email' => 'ghost@example.com']);
        $this->loginAs($user);

        $show = $this->withSession()->get('/workspaces/999999');

        $show->assertRedirectTo('/workspaces');
    }

    public function testJoinWorksForCompanyWorkspaceOnly(): void
    {
        $owner          = $this->seedWebUser(['email' => 'owner2@example.com']);
        $workspaceModel = model(WorkspaceModel::class);
        $companyId      = $workspaceModel->createWorkspace('Company Wide', (int) $owner->id, null, 'company');
        $privateId      = $workspaceModel->createWorkspace('Private One', (int) $owner->id);
        $joiner         = $this->seedWebUser(['email' => 'joiner@example.com']);
        $this->loginAs($joiner);

        $joinCompany = $this->withSession()->post('/workspaces/' . $companyId . '/join', $this->withCsrf([]));
        $joinCompany->assertStatus(200);
        $this->assertSame('member', model(WorkspaceMemberModel::class)->roleFor($companyId, (int) $joiner->id));

        $joinPrivate = $this->withSession()->post('/workspaces/' . $privateId . '/join', $this->withCsrf([]));
        $joinPrivate->assertStatus(403);
        $this->assertNull(model(WorkspaceMemberModel::class)->roleFor($privateId, (int) $joiner->id));
    }

    public function testJoinRejectsAlreadyMember(): void
    {
        $owner     = $this->seedWebUser(['email' => 'owner3@example.com']);
        $companyId = model(WorkspaceModel::class)->createWorkspace('Company Two', (int) $owner->id, null, 'company');
        $alreadyIn = $this->seedWebUser(['email' => 'already-in@example.com']);
        model(WorkspaceMemberModel::class)->add($companyId, (int) $alreadyIn->id, 'member');
        $this->loginAs($alreadyIn);

        $join = $this->withSession()->post('/workspaces/' . $companyId . '/join', $this->withCsrf([]));

        $join->assertStatus(400);
    }

    public function testSoleOwnerCannotLeave(): void
    {
        $owner = $this->seedWebUser(['email' => 'sole-owner@example.com']);
        $id    = model(WorkspaceModel::class)->createWorkspace('Solo Space', (int) $owner->id);
        model(WorkspaceMemberModel::class)->add($id, (int) $owner->id, 'owner');
        $this->loginAs($owner);

        $leave = $this->withSession()->post('/workspaces/' . $id . '/leave', $this->withCsrf([]));

        $leave->assertStatus(400);
        $this->assertSame('owner', model(WorkspaceMemberModel::class)->roleFor($id, (int) $owner->id));
    }

    public function testSecondOwnerCanLeaveLeavingOneOwnerBehind(): void
    {
        $owner       = $this->seedWebUser(['email' => 'owner4@example.com']);
        $memberModel = model(WorkspaceMemberModel::class);
        $id          = model(WorkspaceModel::class)->createWorkspace('Duo Space', (int) $owner->id);
        $memberModel->add($id, (int) $owner->id, 'owner');
        $secondOwner = $this->seedWebUser(['email' => 'second-owner@example.com']);
        $memberModel->add($id, (int) $secondOwner->id, 'owner');
        $this->loginAs($secondOwner);

        $leave = $this->withSession()->post('/workspaces/' . $id . '/leave', $this->withCsrf([]));

        $leave->assertStatus(200);
        $this->assertNull($memberModel->roleFor($id, (int) $secondOwner->id));
    }

    public function testAddMemberIsGatedToManagers(): void
    {
        $owner       = $this->seedWebUser(['email' => 'owner5@example.com']);
        $memberModel = model(WorkspaceMemberModel::class);
        $id          = model(WorkspaceModel::class)->createWorkspace('Managed Space', (int) $owner->id);
        $memberModel->add($id, (int) $owner->id, 'owner');
        $plainMember = $this->seedWebUser(['email' => 'plain-member@example.com']);
        $memberModel->add($id, (int) $plainMember->id, 'member');
        $newUser = $this->seedWebUser(['email' => 'new-user@example.com']);
        $this->loginAs($plainMember);

        $add = $this->withSession()->post('/workspaces/' . $id . '/members/add', $this->withCsrf([
            'user_id' => $newUser->id,
            'role'    => 'member',
        ]));

        $add->assertStatus(403);
        $this->assertNull($memberModel->roleFor($id, (int) $newUser->id));
    }

    public function testAddMemberSucceedsForManager(): void
    {
        $owner       = $this->seedWebUser(['email' => 'owner6@example.com']);
        $memberModel = model(WorkspaceMemberModel::class);
        $id          = model(WorkspaceModel::class)->createWorkspace('Manager Space', (int) $owner->id);
        $memberModel->add($id, (int) $owner->id, 'owner');
        $newUser = $this->seedWebUser(['email' => 'added-user@example.com']);
        $this->loginAs($owner);

        $add = $this->withSession()->post('/workspaces/' . $id . '/members/add', $this->withCsrf([
            'user_id' => $newUser->id,
            'role'    => 'admin',
        ]));

        $add->assertStatus(200);
        $this->assertSame('admin', $memberModel->roleFor($id, (int) $newUser->id));
    }

    public function testRemoveMemberIsGatedToManagers(): void
    {
        $owner       = $this->seedWebUser(['email' => 'owner7@example.com']);
        $memberModel = model(WorkspaceMemberModel::class);
        $id          = model(WorkspaceModel::class)->createWorkspace('Guarded Space', (int) $owner->id);
        $memberModel->add($id, (int) $owner->id, 'owner');
        $plainMember = $this->seedWebUser(['email' => 'plain-member2@example.com']);
        $memberModel->add($id, (int) $plainMember->id, 'member');
        $target = $this->seedWebUser(['email' => 'target-member@example.com']);
        $memberModel->add($id, (int) $target->id, 'member');
        $this->loginAs($plainMember);

        $remove = $this->withSession()->post('/workspaces/' . $id . '/members/remove', $this->withCsrf([
            'user_id' => $target->id,
        ]));

        $remove->assertStatus(403);
        $this->assertSame('member', $memberModel->roleFor($id, (int) $target->id));
    }

    public function testRemoveMemberCannotRemoveTheOnlyOwner(): void
    {
        $owner       = $this->seedWebUser(['email' => 'owner8@example.com']);
        $memberModel = model(WorkspaceMemberModel::class);
        $id          = model(WorkspaceModel::class)->createWorkspace('Sole Owner Space', (int) $owner->id);
        $memberModel->add($id, (int) $owner->id, 'owner');
        $this->loginAs($owner);

        $remove = $this->withSession()->post('/workspaces/' . $id . '/members/remove', $this->withCsrf([
            'user_id' => $owner->id,
        ]));

        $remove->assertStatus(400);
        $this->assertSame('owner', $memberModel->roleFor($id, (int) $owner->id));
    }

    public function testAddGroupBulkAddsAllGroupMembersAndDedupes(): void
    {
        $owner       = $this->seedWebUser(['email' => 'owner9@example.com']);
        $memberModel = model(WorkspaceMemberModel::class);
        $id          = model(WorkspaceModel::class)->createWorkspace('Group Add Space', (int) $owner->id);
        $memberModel->add($id, (int) $owner->id, 'owner');

        $groupId = model(GroupModel::class)->createGroup('Design Squad');
        $userA   = $this->seedWebUser(['email' => 'squad-a@example.com']);
        $userB   = $this->seedWebUser(['email' => 'squad-b@example.com']);
        model(GroupMemberModel::class)->add($groupId, (int) $userA->id);
        model(GroupMemberModel::class)->add($groupId, (int) $userB->id);
        // Already a member — must not be double-counted.
        $memberModel->add($id, (int) $userA->id, 'member');

        $this->loginAs($owner);

        $addGroup = $this->withSession()->post('/workspaces/' . $id . '/members/add-group', $this->withCsrf([
            'group_id' => $groupId,
        ]));

        $addGroup->assertStatus(200);
        $json = json_decode($addGroup->getJSON(), true);
        $this->assertSame(1, $json['added']);
        $this->assertSame('member', $memberModel->roleFor($id, (int) $userA->id));
        $this->assertSame('member', $memberModel->roleFor($id, (int) $userB->id));
    }

    public function testDeleteIsOwnerOnly(): void
    {
        $owner       = $this->seedWebUser(['email' => 'owner10@example.com']);
        $memberModel = model(WorkspaceMemberModel::class);
        $model       = model(WorkspaceModel::class);
        $id          = $model->createWorkspace('Deletable Space', (int) $owner->id);
        $memberModel->add($id, (int) $owner->id, 'owner');
        $admin = $this->seedWebUser(['email' => 'admin-not-owner@example.com']);
        $memberModel->add($id, (int) $admin->id, 'admin');
        $this->loginAs($admin);

        $delete = $this->withSession()->post('/workspaces/delete/' . $id, $this->withCsrf([]));
        $delete->assertStatus(403);
        $this->assertNotNull($model->findWorkspace($id));

        $this->loginAs($owner);
        $delete = $this->withSession()->post('/workspaces/delete/' . $id, $this->withCsrf([]));
        $delete->assertStatus(200);
        $this->assertNull($model->findWorkspace($id));
    }

    public function testUpdateIsGatedToManagers(): void
    {
        $owner       = $this->seedWebUser(['email' => 'owner11@example.com']);
        $memberModel = model(WorkspaceMemberModel::class);
        $model       = model(WorkspaceModel::class);
        $id          = $model->createWorkspace('Editable Space', (int) $owner->id);
        $memberModel->add($id, (int) $owner->id, 'owner');
        $viewer = $this->seedWebUser(['email' => 'viewer@example.com']);
        $memberModel->add($id, (int) $viewer->id, 'viewer');
        $this->loginAs($viewer);

        $update = $this->withSession()->post('/workspaces/update/' . $id, $this->withCsrf([
            'name' => 'Renamed by viewer',
        ]));
        $update->assertStatus(403);

        $this->loginAs($owner);
        $update = $this->withSession()->post('/workspaces/update/' . $id, $this->withCsrf([
            'name'       => 'Renamed by owner',
            'visibility' => 'company',
        ]));
        $update->assertStatus(200);

        $reloaded = $model->findWorkspace($id);
        $this->assertSame('Renamed by owner', $reloaded->name);
        $this->assertSame('company', $reloaded->visibility);
    }

    public function testGuestIsRedirectedToLoginFromWorkspacesIndex(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $result = $this->get('/workspaces');

        $result->assertRedirectTo('/login');
    }
}
