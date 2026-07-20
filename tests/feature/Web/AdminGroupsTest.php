<?php

use App\Models\GroupMemberModel;
use App\Models\GroupModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * Admin user groups (`Admin\Groups`), exercised through real routing +
 * filters (`authFilter`/`adminFilter`) + CSRF: CRUD, slug de-duplication,
 * soft-delete (cascading `group_members`), membership add/remove, admin
 * gating. `index()`/`members()` render views that don't exist yet
 * (`frontend-coder` builds them next) — this suite only exercises gating on
 * those two (filters run before the controller/view, so the view's absence
 * doesn't matter there), plus every JSON endpoint in full.
 *
 * @internal
 */
final class AdminGroupsTest extends CIUnitTestCase
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

    public function testCreateStoresGroupWithDerivedSlug(): void
    {
        $admin = $this->seedWebUser(['email' => 'creator@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $create = $this->withSession()->post('/admin/groups/create', $this->withCsrf([
            'name'        => 'Support Team',
            'description' => 'Handles tickets',
        ]));

        $create->assertStatus(200);
        $json = json_decode($create->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertArrayHasKey('csrf_hash', $json);

        $row = model(GroupModel::class)->where('name', 'Support Team')->first();
        $this->assertNotNull($row);
        $this->assertSame('support-team', $row->slug);
        $this->assertSame('Handles tickets', $row->description);
    }

    public function testCreateRejectsMissingName(): void
    {
        $admin = $this->seedWebUser(['email' => 'creator2@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $result = $this->withSession()->post('/admin/groups/create', $this->withCsrf(['name' => '']));

        $result->assertStatus(400);
        $this->assertSame(0, model(GroupModel::class)->countAllResults());
    }

    public function testCreateDeduplicatesSlugForSameName(): void
    {
        $admin = $this->seedWebUser(['email' => 'creator3@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $this->withSession()->post('/admin/groups/create', $this->withCsrf(['name' => 'Ops']));
        $this->withSession()->post('/admin/groups/create', $this->withCsrf(['name' => 'Ops']));

        $model = model(GroupModel::class);
        $this->assertSame(2, $model->where('name', 'Ops')->countAllResults());
        $this->assertNotNull($model->where('slug', 'ops')->first());
        $this->assertNotNull($model->where('slug', 'ops-2')->first());
    }

    public function testEditReturnsGroupFields(): void
    {
        $admin = $this->seedWebUser(['email' => 'edit-admin@example.com', 'zone' => 'global']);
        $id    = model(GroupModel::class)->createGroup('Marketing', 'Growth team');
        $this->loginAs($admin);

        $edit = $this->withSession()->get('/admin/groups/edit/' . $id);

        $edit->assertStatus(200);
        $json = json_decode($edit->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertSame('Marketing', $json['group']['name']);
        $this->assertSame('marketing', $json['group']['slug']);
        $this->assertSame('Growth team', $json['group']['description']);
    }

    public function testEditRejectsUnknownGroupId(): void
    {
        $admin = $this->seedWebUser(['email' => 'edit-admin2@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $edit = $this->withSession()->get('/admin/groups/edit/999999');

        $edit->assertStatus(404);
    }

    public function testUpdatePersistsEditsWithoutChangingSlug(): void
    {
        $admin = $this->seedWebUser(['email' => 'updater@example.com', 'zone' => 'global']);
        $model = model(GroupModel::class);
        $id    = $model->createGroup('Original Name');
        $this->loginAs($admin);

        $update = $this->withSession()->post('/admin/groups/update', $this->withCsrf([
            'id'          => $id,
            'name'        => 'Updated Name',
            'description' => 'Updated desc',
        ]));

        $update->assertStatus(200);

        $reloaded = $model->findGroup($id);
        $this->assertSame('Updated Name', $reloaded->name);
        $this->assertSame('original-name', $reloaded->slug);
        $this->assertSame('Updated desc', $reloaded->description);
    }

    public function testUpdateRejectsUnknownGroupId(): void
    {
        $admin = $this->seedWebUser(['email' => 'updater2@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $update = $this->withSession()->post('/admin/groups/update', $this->withCsrf([
            'id'   => 999999,
            'name' => 'nope',
        ]));

        $update->assertStatus(404);
    }

    public function testDeleteSoftDeletesGroupAndRemovesItsMembers(): void
    {
        $admin       = $this->seedWebUser(['email' => 'deleter@example.com', 'zone' => 'global']);
        $groupModel  = model(GroupModel::class);
        $memberModel = model(GroupMemberModel::class);
        $groupId     = $groupModel->createGroup('Delete Me');
        $member      = $this->seedWebUser(['email' => 'member-of-deleted@example.com']);
        $memberModel->add($groupId, (int) $member->id);
        $this->loginAs($admin);

        $delete = $this->withSession()->post('/admin/groups/delete', $this->withCsrf(['id' => $groupId]));

        $delete->assertStatus(200);
        $this->assertNull($groupModel->findGroup($groupId));
        $this->assertNotNull($groupModel->withDeleted()->find($groupId)->deleted_at);
        $this->assertSame(0, $memberModel->where('group_id', $groupId)->countAllResults());
    }

    public function testDeleteRejectsUnknownGroupId(): void
    {
        $admin = $this->seedWebUser(['email' => 'deleter2@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $delete = $this->withSession()->post('/admin/groups/delete', $this->withCsrf(['id' => 999999]));

        $delete->assertStatus(404);
    }

    public function testMembersDataListsCurrentMembers(): void
    {
        $admin   = $this->seedWebUser(['email' => 'members-data-admin@example.com', 'zone' => 'global']);
        $groupId = model(GroupModel::class)->createGroup('Data Team');
        $member  = $this->seedWebUser(['email' => 'listed-member@example.com', 'name' => 'Listed Member']);
        model(GroupMemberModel::class)->add($groupId, (int) $member->id);
        $this->loginAs($admin);

        $result = $this->withSession()->get('/admin/groups/' . $groupId . '/members-data');

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertCount(1, $json['members']);
        $this->assertSame('Listed Member', $json['members'][0]['name']);
    }

    public function testMembersDataRejectsUnknownGroupId(): void
    {
        $admin = $this->seedWebUser(['email' => 'members-data-admin2@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $result = $this->withSession()->get('/admin/groups/999999/members-data');

        $result->assertStatus(404);
    }

    public function testAddMemberAddsUserAndReflectsInCount(): void
    {
        $admin      = $this->seedWebUser(['email' => 'add-admin@example.com', 'zone' => 'global']);
        $groupModel = model(GroupModel::class);
        $groupId    = $groupModel->createGroup('Add Target');
        $user       = $this->seedWebUser(['email' => 'to-be-added@example.com']);
        $this->loginAs($admin);

        $add = $this->withSession()->post('/admin/groups/' . $groupId . '/members/add', $this->withCsrf([
            'user_id' => $user->id,
        ]));

        $add->assertStatus(200);
        $this->assertSame(1, model(GroupMemberModel::class)->where('group_id', $groupId)->where('user_id', $user->id)->countAllResults());

        $withCounts = $groupModel->searchAdmin(['keyword' => 'Add Target']);
        $this->assertSame(1, (int) $withCounts[0]->member_count);
    }

    public function testAddMemberRejectsUnknownUserId(): void
    {
        $admin   = $this->seedWebUser(['email' => 'add-admin2@example.com', 'zone' => 'global']);
        $groupId = model(GroupModel::class)->createGroup('Add Target 2');
        $this->loginAs($admin);

        $add = $this->withSession()->post('/admin/groups/' . $groupId . '/members/add', $this->withCsrf([
            'user_id' => 999999,
        ]));

        $add->assertStatus(404);
    }

    public function testAddMemberRejectsUnknownGroupId(): void
    {
        $admin = $this->seedWebUser(['email' => 'add-admin3@example.com', 'zone' => 'global']);
        $user  = $this->seedWebUser(['email' => 'irrelevant-user@example.com']);
        $this->loginAs($admin);

        $add = $this->withSession()->post('/admin/groups/999999/members/add', $this->withCsrf([
            'user_id' => $user->id,
        ]));

        $add->assertStatus(404);
    }

    public function testRemoveMemberRemovesUserAndReflectsInCount(): void
    {
        $admin       = $this->seedWebUser(['email' => 'remove-admin@example.com', 'zone' => 'global']);
        $groupModel  = model(GroupModel::class);
        $memberModel = model(GroupMemberModel::class);
        $groupId     = $groupModel->createGroup('Remove Target');
        $user        = $this->seedWebUser(['email' => 'to-be-removed@example.com']);
        $memberModel->add($groupId, (int) $user->id);
        $this->loginAs($admin);

        $remove = $this->withSession()->post('/admin/groups/' . $groupId . '/members/remove', $this->withCsrf([
            'user_id' => $user->id,
        ]));

        $remove->assertStatus(200);
        $this->assertSame(0, $memberModel->where('group_id', $groupId)->where('user_id', $user->id)->countAllResults());

        $withCounts = $groupModel->searchAdmin(['keyword' => 'Remove Target']);
        $this->assertSame(0, (int) $withCounts[0]->member_count);
    }

    public function testNonAdminClientSessionIsRejectedByAdminGate(): void
    {
        $client = $this->seedWebUser(['email' => 'client-gate@example.com', 'zone' => 'client']);
        $this->loginAs($client);

        $index = $this->withSession()->get('/admin/groups');
        $index->assertRedirectTo('/dashboard');

        $create = $this->withSession()->post('/admin/groups/create', $this->withCsrf([
            'name' => 'should_not_be_created',
        ]));
        $create->assertRedirectTo('/dashboard');
        $this->assertNull(model(GroupModel::class)->where('name', 'should_not_be_created')->first());

        $groupId  = model(GroupModel::class)->createGroup('Gated Group');
        $members  = $this->withSession()->get('/admin/groups/members/' . $groupId);
        $members->assertRedirectTo('/dashboard');
    }

    public function testGuestIsRedirectedToLoginFromAdminGroupsIndex(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $result = $this->get('/admin/groups');

        $result->assertRedirectTo('/login');
    }
}
