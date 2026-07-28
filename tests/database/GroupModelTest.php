<?php

use App\Models\GroupMemberModel;
use App\Models\GroupModel;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class GroupModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    public function testCreateGroupDerivesUniqueSlug(): void
    {
        $model = new GroupModel();

        $id = $model->createGroup('Marketing Team', 'Campaigns and content');

        $group = $model->findGroup($id);
        $this->assertSame('Marketing Team', $group->name);
        $this->assertSame('marketing-team', $group->slug);
        $this->assertSame('Campaigns and content', $group->description);
    }

    public function testCreateGroupDeduplicatesSlugWithNumericSuffix(): void
    {
        $model = new GroupModel();

        $model->createGroup('Marketing Team');
        $secondId = $model->createGroup('Marketing Team');

        $this->assertSame('marketing-team-2', $model->findGroup($secondId)->slug);
    }

    public function testFindGroupReturnsNullForUnknownId(): void
    {
        $this->assertNull((new GroupModel())->findGroup(999999));
    }

    public function testAddMembersOfAndRemoveManageMembership(): void
    {
        $groupModel  = new GroupModel();
        $memberModel = new GroupMemberModel();

        $groupId = $groupModel->createGroup('Engineering');
        $userId  = $this->createUser('alice@example.com');

        $memberModel->add($groupId, $userId);

        $members = $memberModel->membersOf($groupId);
        $this->assertCount(1, $members);
        $this->assertSame('alice@example.com', $members[0]->email);

        // Adding the same user twice must not create a duplicate row.
        $memberModel->add($groupId, $userId);
        $this->assertCount(1, $memberModel->membersOf($groupId));

        $memberModel->remove($groupId, $userId);
        $this->assertSame([], $memberModel->membersOf($groupId));
    }

    public function testGroupsForUserReturnsOnlyGroupsTheUserBelongsTo(): void
    {
        $groupModel  = new GroupModel();
        $memberModel = new GroupMemberModel();

        $groupA = $groupModel->createGroup('Group A');
        $groupB = $groupModel->createGroup('Group B');
        $userId = $this->createUser('bob@example.com');

        $memberModel->add($groupA, $userId);

        $groups = $groupModel->groupsForUser($userId);

        $this->assertCount(1, $groups);
        $this->assertSame('Group A', $groups[0]->name);
    }

    public function testCountAllCountsOnlyNonSoftDeletedGroups(): void
    {
        $groupModel = new GroupModel();

        $groupModel->createGroup('Group X');
        $deletedId = $groupModel->createGroup('Group Y');
        $groupModel->createGroup('Group Z');
        $groupModel->delete($deletedId);

        $this->assertSame(2, $groupModel->countAll());
    }

    private function createUser(string $email): int
    {
        return (int) (new UserModel())->insert([
            'name'     => $email,
            'email'    => $email,
            'password' => 'irrelevant-hash',
            'zone'     => 'user',
            'status'   => 1,
        ]);
    }
}
