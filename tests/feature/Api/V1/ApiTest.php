<?php

use App\Models\ApiKeyModel;
use App\Models\ProjectMemberModel;
use App\Models\ProjectModel;
use App\Models\TaskColumnModel;
use App\Models\TaskModel;
use App\Models\UserModel;
use App\Models\WorkspaceMemberModel;
use App\Models\WorkspaceModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * `/api/v1/*` (`ApiKeyFilter`, `Api\V1\Me`/`Projects`/`Tasks`), exercised
 * through real routing + filters — no session, no CSRF, Bearer-token auth
 * only: missing/bad token, read-scope key blocked from writes, the
 * `project_role()`/`can_edit_project()` IDOR guard against the API key's
 * owner (never a session), and `last_used_at` stamping. See
 * `kanban/processing/20260721-07-rest-api.md`.
 *
 * @internal
 */
final class ApiTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace;

    public function testMissingTokenIsUnauthorized(): void
    {
        $response = $this->get('/api/v1/me');

        $response->assertStatus(401);
        $this->assertSame('unauthorized', json_decode($response->getJSON(), true)['error']);
    }

    public function testBadTokenIsUnauthorized(): void
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer sk_' . bin2hex(random_bytes(20))])
            ->get('/api/v1/me');

        $response->assertStatus(401);
    }

    public function testReadScopeKeyIsBlockedFromWrites(): void
    {
        [$projectId, $ownerId] = $this->makeProjectWithOwner('read-owner@example.com');
        $token                 = $this->mintToken($ownerId, 'read');

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->post('/api/v1/projects/' . $projectId . '/tasks', ['title' => 'Nope']);

        $response->assertStatus(403);
        $this->assertSame('forbidden', json_decode($response->getJSON(), true)['error']);
    }

    public function testMeReturnsTheKeyOwnerAndStampsLastUsedAt(): void
    {
        $owner  = $this->seedUser('me-owner@example.com');
        $result = model(ApiKeyModel::class)->createForUser((int) $owner->id, 'CLI', 'read');

        $this->assertNull($result['row']->last_used_at);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $result['plain']])->get('/api/v1/me');

        $response->assertStatus(200);
        $json = json_decode($response->getJSON(), true);
        $this->assertSame((int) $owner->id, $json['data']['id']);
        $this->assertSame($owner->email, $json['data']['email']);
        $this->assertNotNull(model(ApiKeyModel::class)->find($result['row']->id)->last_used_at);
    }

    public function testListProjectsOnlyReturnsTheKeyUsersProjects(): void
    {
        [, $ownerId] = $this->makeProjectWithOwner('list-owner@example.com');
        $this->makeProjectWithOwner('other-owner@example.com');
        $token = $this->mintToken($ownerId, 'read');

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])->get('/api/v1/projects');

        $response->assertStatus(200);
        $json = json_decode($response->getJSON(), true);
        $this->assertCount(1, $json['data']);
    }

    public function testCreateGetUpdateAndDeleteATaskInAnAccessibleProject(): void
    {
        [$projectId, $ownerId, , , $columns] = $this->makeProjectWithOwner('crud-owner@example.com');
        $token                               = $this->mintToken($ownerId, 'read,write');

        $create = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->post('/api/v1/projects/' . $projectId . '/tasks', ['title' => 'Ship it', 'priority' => 'high']);
        $create->assertStatus(201);
        $created = json_decode($create->getJSON(), true)['data'];
        $this->assertSame('Ship it', $created['title']);
        $this->assertSame('high', $created['priority']);
        $this->assertSame((int) $columns[0]->id, $created['column_id']);
        $taskId = $created['id'];

        $get = $this->withHeaders(['Authorization' => 'Bearer ' . $token])->get('/api/v1/tasks/' . $taskId);
        $get->assertStatus(200);
        $this->assertSame('Ship it', json_decode($get->getJSON(), true)['data']['title']);

        $update = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->withBodyFormat('json')
            ->patch('/api/v1/tasks/' . $taskId, ['title' => 'Ship it today', 'priority' => 'urgent']);
        $update->assertStatus(200);
        $updated = json_decode($update->getJSON(), true)['data'];
        $this->assertSame('Ship it today', $updated['title']);
        $this->assertSame('urgent', $updated['priority']);

        $delete = $this->withHeaders(['Authorization' => 'Bearer ' . $token])->delete('/api/v1/tasks/' . $taskId);
        $delete->assertStatus(200);
        $this->assertTrue(json_decode($delete->getJSON(), true)['data']['deleted']);
        $this->assertNull(model(TaskModel::class)->findTask($taskId));
    }

    public function testListAndAddTaskComments(): void
    {
        [$projectId, $ownerId, , , $columns] = $this->makeProjectWithOwner('comment-owner@example.com');
        $taskId                              = model(TaskModel::class)->insert([
            'project_id' => $projectId,
            'column_id'  => $columns[0]->id,
            'title'      => 'Discuss',
            'position'   => 0,
            'created_by' => $ownerId,
        ]);

        // A read-scope key cannot POST a comment (write scope required).
        $readToken = $this->mintToken($ownerId, 'read');
        $this->withHeaders(['Authorization' => 'Bearer ' . $readToken])
            ->post('/api/v1/tasks/' . $taskId . '/comments', ['body' => 'nope'])
            ->assertStatus(403);

        $token = $this->mintToken($ownerId, 'read,write');

        $add = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->post('/api/v1/tasks/' . $taskId . '/comments', ['body' => 'Looks good to me']);
        $add->assertStatus(200);
        $this->assertSame('Looks good to me', json_decode($add->getJSON(), true)['data']['body']);

        $list = $this->withHeaders(['Authorization' => 'Bearer ' . $token])->get('/api/v1/tasks/' . $taskId . '/comments');
        $list->assertStatus(200);
        $comments = json_decode($list->getJSON(), true)['data'];
        $this->assertCount(1, $comments);
        $this->assertSame('Looks good to me', $comments[0]['body']);

        // Empty body is rejected (422).
        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->post('/api/v1/tasks/' . $taskId . '/comments', ['body' => ''])
            ->assertStatus(422);
    }

    public function testCreateTaskFailsValidationWithoutATitle(): void
    {
        [$projectId, $ownerId] = $this->makeProjectWithOwner('validation-owner@example.com');
        $token                 = $this->mintToken($ownerId, 'read,write');

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->post('/api/v1/projects/' . $projectId . '/tasks', []);

        $response->assertStatus(422);
        $json = json_decode($response->getJSON(), true);
        $this->assertSame('validation', $json['error']);
        $this->assertArrayHasKey('title', $json['errors']);
    }

    public function testATaskInAnInaccessibleProjectIs404(): void
    {
        [$projectId, $ownerId] = $this->makeProjectWithOwner('owner-a@example.com');
        $taskId                = (int) model(TaskModel::class)->insert([
            'project_id' => $projectId,
            'title'      => 'Hidden from outsiders',
            'position'   => 0,
            'created_by' => $ownerId,
        ]);

        $outsiderId = (int) $this->seedUser('outsider@example.com')->id;
        $token      = $this->mintToken($outsiderId, 'read,write');

        $get = $this->withHeaders(['Authorization' => 'Bearer ' . $token])->get('/api/v1/tasks/' . $taskId);
        $get->assertStatus(404);

        $update = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->withBodyFormat('json')
            ->patch('/api/v1/tasks/' . $taskId, ['title' => 'Hijacked']);
        $update->assertStatus(404);
        $this->assertSame('Hidden from outsiders', model(TaskModel::class)->findTask($taskId)->title);

        $delete = $this->withHeaders(['Authorization' => 'Bearer ' . $token])->delete('/api/v1/tasks/' . $taskId);
        $delete->assertStatus(404);
        $this->assertNotNull(model(TaskModel::class)->findTask($taskId));
    }

    private function seedUser(string $email): object
    {
        $id = model(UserModel::class)->insert([
            'name'     => $email,
            'email'    => $email,
            'password' => 'irrelevant-hash',
            'zone'     => 'client',
            'status'   => 1,
        ]);

        return model(UserModel::class)->findUser((int) $id);
    }

    private function mintToken(int $userId, string $scopes): string
    {
        return model(ApiKeyModel::class)->createForUser($userId, 'Test key', $scopes)['plain'];
    }

    /**
     * Creates a workspace + project with `$email` as owner, seeded with the
     * three default columns — mirrors `BoardTest::makeProjectWithOwner()`.
     *
     * @return array{0: int, 1: int, 2: WorkspaceMemberModel, 3: ProjectMemberModel, 4: list<object>}
     */
    private function makeProjectWithOwner(string $email): array
    {
        $owner       = $this->seedUser($email);
        $wsMember    = model(WorkspaceMemberModel::class);
        $workspaceId = model(WorkspaceModel::class)->createWorkspace('API Space ' . $email, (int) $owner->id);
        $wsMember->add($workspaceId, (int) $owner->id, 'owner');

        $projectMemberModel = model(ProjectMemberModel::class);
        $projectId          = model(ProjectModel::class)->createProject($workspaceId, 'API Project ' . $email, (int) $owner->id);
        $projectMemberModel->add($projectId, (int) $owner->id, 'owner');

        $columnModel = model(TaskColumnModel::class);
        $columnModel->seedDefaults($projectId);

        return [$projectId, (int) $owner->id, $wsMember, $projectMemberModel, $columnModel->forProject($projectId)];
    }
}
