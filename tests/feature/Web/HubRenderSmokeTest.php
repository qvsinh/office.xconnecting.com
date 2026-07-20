<?php

use App\Models\GroupModel;
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
 * Render smoke test for the collaboration hub pages. The per-feature suites
 * assert gating (which redirects *before* the view renders), so a broken view
 * variable contract would slip through them. This suite logs in as a real
 * member with seeded data and asserts each page returns 200 (i.e. the view
 * actually renders without a missing-variable/PHP error).
 *
 * @internal
 */
final class HubRenderSmokeTest extends CIUnitTestCase
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

    public function testAdminGroupPagesRender(): void
    {
        $admin = $this->seedWebUser(['email' => 'smoke-admin@example.com', 'zone' => 'global']);
        $groupId = model(GroupModel::class)->createGroup('Engineering', 'The eng team');
        $this->loginAs($admin);

        $this->withSession()->get('/admin/groups')->assertStatus(200);
        $this->withSession()->get('/admin/groups/members/' . $groupId)->assertStatus(200);
    }

    public function testWorkspaceAndProjectPagesRenderForMember(): void
    {
        $user = $this->seedWebUser(['email' => 'smoke-member@example.com', 'zone' => 'client']);

        // Workspace with the user as owner-member.
        $wsId = model(WorkspaceModel::class)->insert([
            'name'       => 'Product',
            'slug'       => 'product',
            'owner_id'   => $user->id,
            'visibility' => 'private',
            'status'     => 'active',
        ], true);
        model(WorkspaceMemberModel::class)->add((int) $wsId, (int) $user->id, 'owner');

        // Project inside it, user as owner-member, default columns seeded.
        $projId = model(ProjectModel::class)->insert([
            'workspace_id' => $wsId,
            'name'         => 'Website',
            'slug'         => 'website',
            'owner_id'     => $user->id,
            'status'       => 'active',
        ], true);
        model(ProjectMemberModel::class)->add((int) $projId, (int) $user->id, 'owner');
        model(TaskColumnModel::class)->seedDefaults((int) $projId);

        $this->loginAs($user);

        $this->withSession()->get('/workspaces')->assertStatus(200);
        $this->withSession()->get('/workspaces/' . $wsId)->assertStatus(200);
        $this->withSession()->get('/projects/' . $projId)->assertStatus(200);
    }

    public function testAccountApiKeysPageRenders(): void
    {
        $user = $this->seedWebUser(['email' => 'smoke-keys@example.com', 'zone' => 'client']);
        $this->loginAs($user);

        $this->withSession()->get('/account/api-keys')->assertStatus(200);
    }
}
