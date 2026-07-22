<?php

use App\Models\ProjectMemberModel;
use App\Models\ProjectModel;
use App\Models\TaskColumnModel;
use App\Models\TaskCommentModel;
use App\Models\TaskModel;
use App\Models\UserModel;
use App\Models\WorkspaceMemberModel;
use App\Models\WorkspaceModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * Task detail page (`Tasks::show`) + comment thread (add/delete), exercised
 * through real routing/filters/CSRF. Access is the project-membership IDOR
 * guard; comment deletion is limited to the author or a project owner/admin.
 *
 * @internal
 */
final class TaskCommentsTest extends CIUnitTestCase
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

    public function testDetailPageRendersForMemberAndRedirectsNonMember(): void
    {
        [$taskId, $owner] = $this->makeTask('owner-detail@example.com');
        $this->loginAs($owner);
        $this->withSession()->get('/tasks/' . $taskId)->assertStatus(200);

        session()->destroy();
        $stranger = $this->seedWebUser(['email' => 'stranger-detail@example.com']);
        $this->loginAs($stranger);
        $this->withSession()->get('/tasks/' . $taskId)->assertRedirectTo('/workspaces');
    }

    public function testMemberCanAddCommentAndNonMemberCannot(): void
    {
        [$taskId, $owner] = $this->makeTask('owner-comment@example.com');
        $this->loginAs($owner);

        $ok = $this->withSession()->post('/tasks/' . $taskId . '/comments', $this->withCsrf(['body' => 'First comment']));
        $ok->assertStatus(200);
        $this->assertSame(1, model(TaskCommentModel::class)->where('task_id', $taskId)->countAllResults());

        // Empty body rejected.
        $this->withSession()->post('/tasks/' . $taskId . '/comments', $this->withCsrf(['body' => '']))->assertStatus(400);

        // Non-member forbidden.
        session()->destroy();
        $stranger = $this->seedWebUser(['email' => 'stranger-comment@example.com']);
        $this->loginAs($stranger);
        $this->withSession()->post('/tasks/' . $taskId . '/comments', $this->withCsrf(['body' => 'sneaky']))->assertStatus(403);
        $this->assertSame(1, model(TaskCommentModel::class)->where('task_id', $taskId)->countAllResults());
    }

    public function testCommentDeletionIsLimitedToAuthorOrManager(): void
    {
        [$taskId, $owner, $projectId] = $this->makeTask('owner-del@example.com');

        // A plain member authors a comment.
        $member = $this->seedWebUser(['email' => 'member-del@example.com']);
        model(ProjectMemberModel::class)->add($projectId, (int) $member->id, 'member');
        $commentId = model(TaskCommentModel::class)->insert([
            'task_id' => $taskId,
            'user_id' => (int) $member->id,
            'body'    => 'Member comment',
        ]);

        // Another plain member cannot delete it.
        $other = $this->seedWebUser(['email' => 'other-del@example.com']);
        model(ProjectMemberModel::class)->add($projectId, (int) $other->id, 'member');
        $this->loginAs($other);
        $this->withSession()->post('/comments/delete/' . $commentId, $this->withCsrf([]))->assertStatus(403);

        // The project owner can.
        session()->destroy();
        $this->loginAs($owner);
        $this->withSession()->post('/comments/delete/' . $commentId, $this->withCsrf([]))->assertStatus(200);
        $this->assertNull(model(TaskCommentModel::class)->find($commentId));
    }

    /**
     * @return array{0: int, 1: object, 2: int} [taskId, owner, projectId]
     */
    private function makeTask(string $email): array
    {
        $owner       = $this->seedWebUser(['email' => $email]);
        $workspaceId = model(WorkspaceModel::class)->createWorkspace('WS ' . $email, (int) $owner->id);
        model(WorkspaceMemberModel::class)->add($workspaceId, (int) $owner->id, 'owner');
        $projectId = model(ProjectModel::class)->createProject($workspaceId, 'Proj ' . $email, (int) $owner->id);
        model(ProjectMemberModel::class)->add($projectId, (int) $owner->id, 'owner');
        model(TaskColumnModel::class)->seedDefaults($projectId);
        $columns = model(TaskColumnModel::class)->forProject($projectId);
        $taskId  = model(TaskModel::class)->insert([
            'project_id' => $projectId,
            'column_id'  => $columns[0]->id,
            'title'      => 'A task',
            'position'   => 0,
            'created_by' => (int) $owner->id,
        ]);

        return [(int) $taskId, $owner, (int) $projectId];
    }
}
