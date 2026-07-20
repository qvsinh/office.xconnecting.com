<?php

use App\Models\ProjectMemberModel;
use App\Models\ProjectModel;
use App\Models\TaskColumnModel;
use App\Models\WorkspaceMemberModel;
use App\Models\WorkspaceModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * Projects inside a workspace (`Projects`), exercised through real routing +
 * filters (`authFilter`) + CSRF: create (workspace-manager gated, unique
 * slug, default kanban columns seeded), the `project_role()` IDOR guard on
 * `show()`, owner/admin membership management, and update/delete gating.
 *
 * @internal
 */
final class ProjectsTest extends CIUnitTestCase
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

    public function testCreateIsGatedToWorkspaceManagers(): void
    {
        $owner          = $this->seedWebUser(['email' => 'ws-owner@example.com']);
        $workspaceModel = model(WorkspaceModel::class);
        $memberModel    = model(WorkspaceMemberModel::class);
        $workspaceId    = $workspaceModel->createWorkspace('Engineering', (int) $owner->id);
        $memberModel->add($workspaceId, (int) $owner->id, 'owner');

        $plainMember = $this->seedWebUser(['email' => 'plain-ws-member@example.com']);
        $memberModel->add($workspaceId, (int) $plainMember->id, 'member');
        $this->loginAs($plainMember);

        $create = $this->withSession()->post('/workspaces/' . $workspaceId . '/projects/create', $this->withCsrf([
            'name' => 'Website Revamp',
        ]));

        $create->assertStatus(403);
        $this->assertSame(0, model(ProjectModel::class)->countAllResults());
    }

    public function testCreateSucceedsForManagerAndSeedsOwnerAndColumns(): void
    {
        $owner          = $this->seedWebUser(['email' => 'manager@example.com']);
        $workspaceModel = model(WorkspaceModel::class);
        $memberModel    = model(WorkspaceMemberModel::class);
        $workspaceId    = $workspaceModel->createWorkspace('Design', (int) $owner->id);
        $memberModel->add($workspaceId, (int) $owner->id, 'owner');
        $this->loginAs($owner);

        $create = $this->withSession()->post('/workspaces/' . $workspaceId . '/projects/create', $this->withCsrf([
            'name'        => 'Website Revamp',
            'description' => 'Full redesign',
            'color'       => '#3b82f6',
        ]));

        $create->assertStatus(200);
        $json = json_decode($create->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $id = (int) $json['id'];

        $this->assertSame('owner', model(ProjectMemberModel::class)->roleFor($id, (int) $owner->id));

        $columns = model(TaskColumnModel::class)->forProject($id);
        $this->assertCount(3, $columns);
        $this->assertSame(['To Do', 'In Progress', 'Done'], array_column($columns, 'name'));
    }

    public function testCreateRejectsMissingName(): void
    {
        $owner       = $this->seedWebUser(['email' => 'manager2@example.com']);
        $memberModel = model(WorkspaceMemberModel::class);
        $workspaceId = model(WorkspaceModel::class)->createWorkspace('Ops', (int) $owner->id);
        $memberModel->add($workspaceId, (int) $owner->id, 'owner');
        $this->loginAs($owner);

        $create = $this->withSession()->post('/workspaces/' . $workspaceId . '/projects/create', $this->withCsrf(['name' => '']));

        $create->assertStatus(400);
        $this->assertSame(0, model(ProjectModel::class)->countAllResults());
    }

    public function testCreateDerivesSlugUniquePerWorkspace(): void
    {
        $owner       = $this->seedWebUser(['email' => 'manager3@example.com']);
        $memberModel = model(WorkspaceMemberModel::class);
        $workspaceId = model(WorkspaceModel::class)->createWorkspace('Marketing', (int) $owner->id);
        $memberModel->add($workspaceId, (int) $owner->id, 'owner');
        $this->loginAs($owner);

        // Each response wraps the app's shared Response singleton, so it
        // must be read before issuing the next call — not deferred until
        // after both requests complete (see WorkspacesTest for the same
        // "read immediately" pattern).
        $first    = $this->withSession()->post('/workspaces/' . $workspaceId . '/projects/create', $this->withCsrf(['name' => 'Launch']));
        $firstId  = (int) json_decode($first->getJSON(), true)['id'];
        $second   = $this->withSession()->post('/workspaces/' . $workspaceId . '/projects/create', $this->withCsrf(['name' => 'Launch']));
        $secondId = (int) json_decode($second->getJSON(), true)['id'];

        $projectModel = model(ProjectModel::class);
        $this->assertSame('launch', $projectModel->findProject($firstId)->slug);
        $this->assertSame('launch-2', $projectModel->findProject($secondId)->slug);
    }

    public function testShowRedirectsNonMemberToWorkspaces(): void
    {
        $owner       = $this->seedWebUser(['email' => 'owner-show@example.com']);
        $memberModel = model(WorkspaceMemberModel::class);
        $workspaceId = model(WorkspaceModel::class)->createWorkspace('Private Space', (int) $owner->id);
        $memberModel->add($workspaceId, (int) $owner->id, 'owner');
        $projectId = model(ProjectModel::class)->createProject($workspaceId, 'Secret Project', (int) $owner->id);
        model(ProjectMemberModel::class)->add($projectId, (int) $owner->id, 'owner');

        $outsider = $this->seedWebUser(['email' => 'outsider-show@example.com']);
        $this->loginAs($outsider);

        $show = $this->withSession()->get('/projects/' . $projectId);

        $show->assertRedirectTo('/workspaces');
    }

    public function testShowRedirectsWhenProjectDoesNotExist(): void
    {
        $user = $this->seedWebUser(['email' => 'ghost-project@example.com']);
        $this->loginAs($user);

        $show = $this->withSession()->get('/projects/999999');

        $show->assertRedirectTo('/workspaces');
    }

    public function testShowRedirectsWhenParentWorkspaceIsSoftDeleted(): void
    {
        $owner          = $this->seedWebUser(['email' => 'owner-softdel@example.com']);
        $workspaceModel = model(WorkspaceModel::class);
        $memberModel    = model(WorkspaceMemberModel::class);
        $workspaceId    = $workspaceModel->createWorkspace('Doomed Space', (int) $owner->id);
        $memberModel->add($workspaceId, (int) $owner->id, 'owner');
        $projectId = model(ProjectModel::class)->createProject($workspaceId, 'Orphan Project', (int) $owner->id);
        model(ProjectMemberModel::class)->add($projectId, (int) $owner->id, 'owner');

        $workspaceModel->delete($workspaceId);
        $this->loginAs($owner);

        $show = $this->withSession()->get('/projects/' . $projectId);

        $show->assertRedirectTo('/workspaces');
    }

    public function testUpdateIsGatedToEditorsAndPersistsChanges(): void
    {
        $owner       = $this->seedWebUser(['email' => 'owner-update@example.com']);
        $wsMember    = model(WorkspaceMemberModel::class);
        $workspaceId = model(WorkspaceModel::class)->createWorkspace('Update Space', (int) $owner->id);
        $wsMember->add($workspaceId, (int) $owner->id, 'owner');
        $projectId       = model(ProjectModel::class)->createProject($workspaceId, 'Editable Project', (int) $owner->id);
        $projectMemberModel = model(ProjectMemberModel::class);
        $projectMemberModel->add($projectId, (int) $owner->id, 'owner');

        $viewer = $this->seedWebUser(['email' => 'viewer-project@example.com']);
        $projectMemberModel->add($projectId, (int) $viewer->id, 'viewer');
        $this->loginAs($viewer);

        $update = $this->withSession()->post('/projects/update/' . $projectId, $this->withCsrf([
            'name' => 'Renamed by viewer',
        ]));
        $update->assertStatus(403);

        $this->loginAs($owner);
        $update = $this->withSession()->post('/projects/update/' . $projectId, $this->withCsrf([
            'name'   => 'Renamed by owner',
            'status' => 'archived',
        ]));
        $update->assertStatus(200);

        $reloaded = model(ProjectModel::class)->findProject($projectId);
        $this->assertSame('Renamed by owner', $reloaded->name);
        $this->assertSame('archived', $reloaded->status);
    }

    public function testDeleteRequiresProjectOwnerRoleOrWorkspaceManagement(): void
    {
        $owner              = $this->seedWebUser(['email' => 'owner-delete@example.com']);
        $wsMember           = model(WorkspaceMemberModel::class);
        $workspaceId        = model(WorkspaceModel::class)->createWorkspace('Delete Space', (int) $owner->id);
        $wsMember->add($workspaceId, (int) $owner->id, 'owner');
        $projectId          = model(ProjectModel::class)->createProject($workspaceId, 'Deletable Project', (int) $owner->id);
        $projectMemberModel = model(ProjectMemberModel::class);
        $projectMemberModel->add($projectId, (int) $owner->id, 'owner');

        // A project *admin* (not owner, not a workspace manager) may not delete.
        $projectAdmin = $this->seedWebUser(['email' => 'project-admin@example.com']);
        $projectMemberModel->add($projectId, (int) $projectAdmin->id, 'admin');
        $this->loginAs($projectAdmin);

        $delete = $this->withSession()->post('/projects/delete/' . $projectId, $this->withCsrf([]));
        $delete->assertStatus(403);
        $this->assertNotNull(model(ProjectModel::class)->findProject($projectId));

        // A workspace manager who isn't even a project member may delete it.
        $workspaceAdmin = $this->seedWebUser(['email' => 'ws-admin-delete@example.com']);
        $wsMember->add($workspaceId, (int) $workspaceAdmin->id, 'admin');
        $this->loginAs($workspaceAdmin);

        $delete = $this->withSession()->post('/projects/delete/' . $projectId, $this->withCsrf([]));
        $delete->assertStatus(200);
        $this->assertNull(model(ProjectModel::class)->findProject($projectId));
    }

    public function testDeleteAllowsProjectOwnerEvenWithoutWorkspaceManagement(): void
    {
        $wsOwner     = $this->seedWebUser(['email' => 'ws-owner-solo@example.com']);
        $wsMember    = model(WorkspaceMemberModel::class);
        $workspaceId = model(WorkspaceModel::class)->createWorkspace('Solo Owner Space', (int) $wsOwner->id);
        $wsMember->add($workspaceId, (int) $wsOwner->id, 'owner');

        $projectOwner = $this->seedWebUser(['email' => 'project-owner-solo@example.com']);
        $wsMember->add($workspaceId, (int) $projectOwner->id, 'member');
        $projectId = model(ProjectModel::class)->createProject($workspaceId, 'Solo Project', (int) $projectOwner->id);
        model(ProjectMemberModel::class)->add($projectId, (int) $projectOwner->id, 'owner');
        $this->loginAs($projectOwner);

        $delete = $this->withSession()->post('/projects/delete/' . $projectId, $this->withCsrf([]));

        $delete->assertStatus(200);
        $this->assertNull(model(ProjectModel::class)->findProject($projectId));
    }

    public function testAddMemberIsGatedToOwnerAdmin(): void
    {
        $owner              = $this->seedWebUser(['email' => 'owner-addmember@example.com']);
        $wsMember           = model(WorkspaceMemberModel::class);
        $workspaceId        = model(WorkspaceModel::class)->createWorkspace('Add Member Space', (int) $owner->id);
        $wsMember->add($workspaceId, (int) $owner->id, 'owner');
        $projectId          = model(ProjectModel::class)->createProject($workspaceId, 'Guarded Project', (int) $owner->id);
        $projectMemberModel = model(ProjectMemberModel::class);
        $projectMemberModel->add($projectId, (int) $owner->id, 'owner');

        $plainMember = $this->seedWebUser(['email' => 'plain-project-member@example.com']);
        $projectMemberModel->add($projectId, (int) $plainMember->id, 'member');
        $newUser = $this->seedWebUser(['email' => 'new-project-user@example.com']);
        $this->loginAs($plainMember);

        $add = $this->withSession()->post('/projects/' . $projectId . '/members/add', $this->withCsrf([
            'user_id' => $newUser->id,
            'role'    => 'member',
        ]));

        $add->assertStatus(403);
        $this->assertNull($projectMemberModel->roleFor($projectId, (int) $newUser->id));
    }

    public function testAddMemberSucceedsForOwner(): void
    {
        $owner              = $this->seedWebUser(['email' => 'owner-addmember2@example.com']);
        $wsMember           = model(WorkspaceMemberModel::class);
        $workspaceId        = model(WorkspaceModel::class)->createWorkspace('Manager Project Space', (int) $owner->id);
        $wsMember->add($workspaceId, (int) $owner->id, 'owner');
        $projectId          = model(ProjectModel::class)->createProject($workspaceId, 'Manager Project', (int) $owner->id);
        $projectMemberModel = model(ProjectMemberModel::class);
        $projectMemberModel->add($projectId, (int) $owner->id, 'owner');

        $newUser = $this->seedWebUser(['email' => 'added-project-user@example.com']);
        $this->loginAs($owner);

        $add = $this->withSession()->post('/projects/' . $projectId . '/members/add', $this->withCsrf([
            'user_id' => $newUser->id,
            'role'    => 'admin',
        ]));

        $add->assertStatus(200);
        $this->assertSame('admin', $projectMemberModel->roleFor($projectId, (int) $newUser->id));
    }

    public function testRemoveMemberCannotRemoveTheOnlyOwner(): void
    {
        $owner              = $this->seedWebUser(['email' => 'owner-removemember@example.com']);
        $wsMember           = model(WorkspaceMemberModel::class);
        $workspaceId        = model(WorkspaceModel::class)->createWorkspace('Sole Owner Project Space', (int) $owner->id);
        $wsMember->add($workspaceId, (int) $owner->id, 'owner');
        $projectId          = model(ProjectModel::class)->createProject($workspaceId, 'Sole Owner Project', (int) $owner->id);
        $projectMemberModel = model(ProjectMemberModel::class);
        $projectMemberModel->add($projectId, (int) $owner->id, 'owner');
        $this->loginAs($owner);

        $remove = $this->withSession()->post('/projects/' . $projectId . '/members/remove', $this->withCsrf([
            'user_id' => $owner->id,
        ]));

        $remove->assertStatus(400);
        $this->assertSame('owner', $projectMemberModel->roleFor($projectId, (int) $owner->id));
    }

    public function testRemoveMemberIsGatedToOwnerAdmin(): void
    {
        $owner              = $this->seedWebUser(['email' => 'owner-removemember2@example.com']);
        $wsMember           = model(WorkspaceMemberModel::class);
        $workspaceId        = model(WorkspaceModel::class)->createWorkspace('Guarded Remove Space', (int) $owner->id);
        $wsMember->add($workspaceId, (int) $owner->id, 'owner');
        $projectId          = model(ProjectModel::class)->createProject($workspaceId, 'Guarded Remove Project', (int) $owner->id);
        $projectMemberModel = model(ProjectMemberModel::class);
        $projectMemberModel->add($projectId, (int) $owner->id, 'owner');

        $plainMember = $this->seedWebUser(['email' => 'plain-project-member2@example.com']);
        $projectMemberModel->add($projectId, (int) $plainMember->id, 'member');
        $target = $this->seedWebUser(['email' => 'target-project-member@example.com']);
        $projectMemberModel->add($projectId, (int) $target->id, 'member');
        $this->loginAs($plainMember);

        $remove = $this->withSession()->post('/projects/' . $projectId . '/members/remove', $this->withCsrf([
            'user_id' => $target->id,
        ]));

        $remove->assertStatus(403);
        $this->assertSame('member', $projectMemberModel->roleFor($projectId, (int) $target->id));
    }
}
