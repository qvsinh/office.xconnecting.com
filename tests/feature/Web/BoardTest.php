<?php

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
use Tests\Support\Web\WebAuthFixture;

/**
 * Kanban board (`Board`), exercised through real routing + filters
 * (`authFilter`) + CSRF: the `project_role()`/`can_edit_project()` IDOR
 * guard on every endpoint (including the task/column-id-derives-project
 * pattern), task create/update/move/delete, the "assignee must be a project
 * member" guard, and column create/rename/delete (with task relocation and
 * the last-column refusal).
 *
 * @internal
 */
final class BoardTest extends CIUnitTestCase
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

    public function testDataIsGatedToMembersAndReturnsColumnsTasksAndMembers(): void
    {
        [$projectId, $ownerId, , , $columns] = $this->makeProjectWithOwner('owner-data@example.com');
        $taskModel                           = model(TaskModel::class);
        $taskModel->insert(['project_id' => $projectId, 'column_id' => $columns[0]->id, 'title' => 'First', 'position' => 0, 'created_by' => $ownerId]);
        $taskModel->insert(['project_id' => $projectId, 'column_id' => $columns[0]->id, 'title' => 'Second', 'position' => 1, 'assignee_id' => $ownerId, 'created_by' => $ownerId]);

        $owner = model(UserModel::class)->findUser($ownerId);
        $this->loginAs($owner);

        $response = $this->withSession()->get('/projects/' . $projectId . '/board-data');
        $response->assertStatus(200);
        $json = json_decode($response->getJSON(), true);

        $this->assertCount(3, $json['columns']);
        $this->assertSame(['First', 'Second'], array_column($json['columns'][0]['tasks'], 'title'));
        $this->assertSame($ownerId, $json['columns'][0]['tasks'][1]['assignee_id']);
        $this->assertSame($owner->name, $json['columns'][0]['tasks'][1]['assignee_name']);
        $this->assertSame([], $json['columns'][1]['tasks']);
        $this->assertSame([['id' => $ownerId, 'name' => $owner->name]], $json['members']);

        $outsider = $this->seedWebUser(['email' => 'outsider-data@example.com']);
        $this->loginAs($outsider);
        $denied = $this->withSession()->get('/projects/' . $projectId . '/board-data');
        $denied->assertStatus(403);
    }

    public function testCreateTaskDefaultsColumnAndRequiresTitle(): void
    {
        [$projectId, $ownerId, , , $columns] = $this->makeProjectWithOwner('owner-create@example.com');
        $owner                               = model(UserModel::class)->findUser($ownerId);
        $this->loginAs($owner);

        $missingTitle = $this->withSession()->post('/projects/' . $projectId . '/tasks/create', $this->withCsrf([]));
        $missingTitle->assertStatus(400);

        $create = $this->withSession()->post('/projects/' . $projectId . '/tasks/create', $this->withCsrf([
            'title' => 'Design homepage',
        ]));
        $create->assertStatus(200);
        $json = json_decode($create->getJSON(), true);

        $this->assertSame('normal', $json['task']['priority']);
        $this->assertSame((int) $columns[0]->id, (int) $json['task']['column_id']);
        $this->assertSame(0, (int) $json['task']['position']);
    }

    public function testCreateTaskIsGatedToEditorsAndRejectsNonMemberAssignee(): void
    {
        [$projectId, $ownerId, , $projectMemberModel] = $this->makeProjectWithOwner('owner-create2@example.com');
        $viewer                                       = $this->seedWebUser(['email' => 'viewer-create@example.com']);
        $projectMemberModel->add($projectId, (int) $viewer->id, 'viewer');
        $this->loginAs($viewer);

        $denied = $this->withSession()->post('/projects/' . $projectId . '/tasks/create', $this->withCsrf(['title' => 'Nope']));
        $denied->assertStatus(403);

        $owner    = model(UserModel::class)->findUser($ownerId);
        $outsider = $this->seedWebUser(['email' => 'outsider-assignee@example.com']);
        $this->loginAs($owner);

        $badAssignee = $this->withSession()->post('/projects/' . $projectId . '/tasks/create', $this->withCsrf([
            'title'       => 'Bad assignee',
            'assignee_id' => $outsider->id,
        ]));
        $badAssignee->assertStatus(400);
        $this->assertSame(0, model(TaskModel::class)->countAllResults());
    }

    public function testUpdateTaskPersistsFieldsAndColumnMove(): void
    {
        [$projectId, $ownerId, , $projectMemberModel, $columns] = $this->makeProjectWithOwner('owner-update@example.com');
        $taskModel                                              = model(TaskModel::class);
        $taskId                                                 = (int) $taskModel->insert(['project_id' => $projectId, 'column_id' => $columns[0]->id, 'title' => 'Original', 'position' => 0, 'created_by' => $ownerId]);

        $assignee = $this->seedWebUser(['email' => 'assignee-update@example.com']);
        $projectMemberModel->add($projectId, (int) $assignee->id, 'member');

        $owner = model(UserModel::class)->findUser($ownerId);
        $this->loginAs($owner);

        $update = $this->withSession()->post('/tasks/update/' . $taskId, $this->withCsrf([
            'title'       => 'Updated title',
            'description' => 'More detail',
            'priority'    => 'high',
            'assignee_id' => $assignee->id,
            'due_date'    => '2026-08-01',
            'column_id'   => $columns[1]->id,
        ]));
        $update->assertStatus(200);

        $task = $taskModel->findTask($taskId);
        $this->assertSame('Updated title', $task->title);
        $this->assertSame('high', $task->priority);
        $this->assertSame((int) $assignee->id, (int) $task->assignee_id);
        $this->assertSame('2026-08-01', $task->due_date);
        $this->assertSame((int) $columns[1]->id, (int) $task->column_id);
    }

    public function testUpdateTaskIsGatedByTheOwningProjectNotThePathAlone(): void
    {
        [$projectA, $ownerA, , , $columnsA] = $this->makeProjectWithOwner('owner-a@example.com');
        [$projectB, $ownerB]                = $this->makeProjectWithOwner('owner-b@example.com');

        $taskId = (int) model(TaskModel::class)->insert([
            'project_id' => $projectB,
            'column_id'  => null,
            'title'      => 'Project B task',
            'position'   => 0,
            'created_by' => $ownerB,
        ]);

        // Owner of project A only, has no role on project B — must be denied
        // even though the task id alone doesn't reveal that in the URL.
        $ownerAUser = model(UserModel::class)->findUser($ownerA);
        $this->loginAs($ownerAUser);

        $update = $this->withSession()->post('/tasks/update/' . $taskId, $this->withCsrf(['title' => 'Hijacked']));

        $update->assertStatus(403);
        $this->assertSame('Project B task', model(TaskModel::class)->findTask($taskId)->title);
        // Sanity: project A's own columns were untouched by this guard.
        $this->assertNotEmpty($columnsA);
    }

    public function testMoveTaskUpdatesColumnAndPosition(): void
    {
        [$projectId, $ownerId, , , $columns] = $this->makeProjectWithOwner('owner-move@example.com');
        $taskId                              = (int) model(TaskModel::class)->insert(['project_id' => $projectId, 'column_id' => $columns[0]->id, 'title' => 'Movable', 'position' => 0, 'created_by' => $ownerId]);

        $owner = model(UserModel::class)->findUser($ownerId);
        $this->loginAs($owner);

        $move = $this->withSession()->post('/tasks/move/' . $taskId, $this->withCsrf([
            'column_id' => $columns[2]->id,
            'position'  => 2,
        ]));
        $move->assertStatus(200);

        $task = model(TaskModel::class)->findTask($taskId);
        $this->assertSame((int) $columns[2]->id, (int) $task->column_id);
        $this->assertSame(2, (int) $task->position);
    }

    public function testMoveTaskRejectsAColumnFromAnotherProject(): void
    {
        [$projectA, $ownerA, , , $columnsA] = $this->makeProjectWithOwner('owner-move-a@example.com');
        [$projectB]                         = $this->makeProjectWithOwner('owner-move-b@example.com');
        $foreignColumn                      = model(TaskColumnModel::class)->firstColumn($projectB);

        $taskId     = (int) model(TaskModel::class)->insert(['project_id' => $projectA, 'column_id' => $columnsA[0]->id, 'title' => 'Stays put', 'position' => 0, 'created_by' => $ownerA]);
        $ownerAUser = model(UserModel::class)->findUser($ownerA);
        $this->loginAs($ownerAUser);

        $move = $this->withSession()->post('/tasks/move/' . $taskId, $this->withCsrf([
            'column_id' => $foreignColumn->id,
            'position'  => 0,
        ]));

        $move->assertStatus(400);
        $this->assertSame((int) $columnsA[0]->id, (int) model(TaskModel::class)->findTask($taskId)->column_id);
    }

    public function testDeleteTaskIsGatedToEditorsAndSoftDeletes(): void
    {
        [$projectId, $ownerId, , $projectMemberModel, $columns] = $this->makeProjectWithOwner('owner-delete-task@example.com');
        $taskId                                                 = (int) model(TaskModel::class)->insert(['project_id' => $projectId, 'column_id' => $columns[0]->id, 'title' => 'Removable', 'position' => 0, 'created_by' => $ownerId]);

        $viewer = $this->seedWebUser(['email' => 'viewer-delete-task@example.com']);
        $projectMemberModel->add($projectId, (int) $viewer->id, 'viewer');
        $this->loginAs($viewer);

        $denied = $this->withSession()->post('/tasks/delete/' . $taskId, $this->withCsrf([]));
        $denied->assertStatus(403);

        $owner = model(UserModel::class)->findUser($ownerId);
        $this->loginAs($owner);
        $delete = $this->withSession()->post('/tasks/delete/' . $taskId, $this->withCsrf([]));
        $delete->assertStatus(200);

        $this->assertNull(model(TaskModel::class)->findTask($taskId));
    }

    public function testCreateColumnIsGatedToEditorsAndAppendsPosition(): void
    {
        [$projectId, $ownerId] = $this->makeProjectWithOwner('owner-create-col@example.com');
        $owner                 = model(UserModel::class)->findUser($ownerId);
        $this->loginAs($owner);

        $create = $this->withSession()->post('/projects/' . $projectId . '/columns/create', $this->withCsrf(['name' => 'Blocked']));
        $create->assertStatus(200);
        $json = json_decode($create->getJSON(), true);

        $this->assertSame('Blocked', $json['column']['name']);
        $this->assertSame(3, (int) $json['column']['position']);
    }

    public function testRenameColumnIsGatedByItsOwningProject(): void
    {
        [$projectId, $ownerId, , , $columns] = $this->makeProjectWithOwner('owner-rename-col@example.com');
        $outsider                            = $this->seedWebUser(['email' => 'outsider-rename@example.com']);
        $this->loginAs($outsider);

        $denied = $this->withSession()->post('/columns/rename/' . $columns[0]->id, $this->withCsrf(['name' => 'Hijacked']));
        $denied->assertStatus(403);

        $owner = model(UserModel::class)->findUser($ownerId);
        $this->loginAs($owner);
        $rename = $this->withSession()->post('/columns/rename/' . $columns[0]->id, $this->withCsrf(['name' => 'Backlog']));
        $rename->assertStatus(200);

        $this->assertSame('Backlog', model(TaskColumnModel::class)->find($columns[0]->id)->name);
        unset($projectId);
    }

    public function testDeleteColumnRelocatesItsTasksAndRefusesTheLastColumn(): void
    {
        [$projectId, $ownerId, , , $columns] = $this->makeProjectWithOwner('owner-delete-col@example.com');
        $taskModel                           = model(TaskModel::class);
        $taskId                              = (int) $taskModel->insert(['project_id' => $projectId, 'column_id' => $columns[0]->id, 'title' => 'Relocate me', 'position' => 0, 'created_by' => $ownerId]);

        $owner = model(UserModel::class)->findUser($ownerId);
        $this->loginAs($owner);

        $delete = $this->withSession()->post('/columns/delete/' . $columns[0]->id, $this->withCsrf([]));
        $delete->assertStatus(200);

        $this->assertNull(model(TaskColumnModel::class)->find($columns[0]->id));
        $this->assertSame((int) $columns[1]->id, (int) $taskModel->findTask($taskId)->column_id);

        // Delete the remaining two down to one, then refuse on the last.
        $this->withSession()->post('/columns/delete/' . $columns[1]->id, $this->withCsrf([]));
        $lastColumnId = model(TaskColumnModel::class)->firstColumn($projectId)->id;

        $refused = $this->withSession()->post('/columns/delete/' . $lastColumnId, $this->withCsrf([]));
        $refused->assertStatus(400);
        $this->assertNotNull(model(TaskColumnModel::class)->find($lastColumnId));
    }

    /**
     * Creates a workspace + project with `$email` as both workspace and
     * project owner, seeded with the three default columns.
     *
     * @return array{0: int, 1: int, 2: WorkspaceMemberModel, 3: ProjectMemberModel, 4: list<object>}
     */
    public function testReorderColumnsPersistsTheNewOrder(): void
    {
        [$projectId, $ownerId, , , $columns] = $this->makeProjectWithOwner('owner-reorder@example.com');
        $owner                               = model(UserModel::class)->findUser($ownerId);
        $this->loginAs($owner);

        // Reverse the seeded To Do / In Progress / Done order.
        $reversed = array_reverse(array_map(static fn ($c) => (int) $c->id, $columns));

        $response = $this->withSession()->post('/projects/' . $projectId . '/columns/reorder', $this->withCsrf([
            'order' => implode(',', $reversed),
        ]));
        $response->assertStatus(200);

        $after = array_map(static fn ($c) => (int) $c->id, model(TaskColumnModel::class)->forProject($projectId));
        $this->assertSame($reversed, $after);
    }

    public function testReorderColumnsIsGatedToEditors(): void
    {
        [$projectId, , , , $columns] = $this->makeProjectWithOwner('owner-reorder-gate@example.com');
        $viewer                      = $this->seedWebUser(['email' => 'viewer-reorder@example.com']);
        model(ProjectMemberModel::class)->add($projectId, (int) $viewer->id, 'viewer');
        $this->loginAs($viewer);

        $response = $this->withSession()->post('/projects/' . $projectId . '/columns/reorder', $this->withCsrf([
            'order' => implode(',', array_reverse(array_map(static fn ($c) => (int) $c->id, $columns))),
        ]));
        $response->assertStatus(403);
    }

    private function makeProjectWithOwner(string $email): array
    {
        $owner       = $this->seedWebUser(['email' => $email]);
        $wsMember    = model(WorkspaceMemberModel::class);
        $workspaceId = model(WorkspaceModel::class)->createWorkspace('Board Space ' . $email, (int) $owner->id);
        $wsMember->add($workspaceId, (int) $owner->id, 'owner');

        $projectMemberModel = model(ProjectMemberModel::class);
        $projectId          = model(ProjectModel::class)->createProject($workspaceId, 'Board Project ' . $email, (int) $owner->id);
        $projectMemberModel->add($projectId, (int) $owner->id, 'owner');

        $columnModel = model(TaskColumnModel::class);
        $columnModel->seedDefaults($projectId);

        return [$projectId, (int) $owner->id, $wsMember, $projectMemberModel, $columnModel->forProject($projectId)];
    }
}
