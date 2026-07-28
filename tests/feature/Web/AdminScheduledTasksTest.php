<?php

use App\Models\ScheduledTaskModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * Admin scheduled tasks (`Admin\ScheduledTasks`), exercised through real
 * routing + filters (`authFilter`/`adminFilter`) + CSRF: CRUD, the
 * CRITICAL `handler` allowlist gate, toggle, runNow, admin gating.
 * `index()` renders a view that doesn't exist yet (`frontend-coder` builds
 * it next) — this suite only exercises routes that return JSON, plus gating
 * on `index()` (filters run before the controller/view, so the view's
 * absence doesn't matter there).
 *
 * @internal
 */
final class AdminScheduledTasksTest extends CIUnitTestCase
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

    public function testCreateStoresTaskAndReturnsSuccessEnvelope(): void
    {
        $admin = $this->seedWebUser(['email' => 'creator@example.com', 'zone' => 'admin']);
        $this->loginAs($admin);

        $create = $this->withSession()->post('/admin/scheduled-tasks/create', $this->withCsrf([
            'name'             => 'Purge sent emails',
            'handler'          => 'emails.purge',
            'params'           => json_encode(['days' => 7]),
            'interval_minutes' => 1440,
            'enabled'          => 1,
            'description'      => 'Nightly email purge',
        ]));

        $create->assertStatus(200);
        $json = json_decode($create->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertArrayHasKey('csrf_hash', $json);

        $row = model(ScheduledTaskModel::class)->where('name', 'Purge sent emails')->first();
        $this->assertNotNull($row);
        $this->assertSame('emails.purge', $row->handler);
        $this->assertSame(1440, (int) $row->interval_minutes);
        $this->assertSame(1, (int) $row->enabled);
        $this->assertNotNull($row->next_run_at);
    }

    public function testCreateRejectsMissingRequiredFields(): void
    {
        $admin = $this->seedWebUser(['email' => 'creator2@example.com', 'zone' => 'admin']);
        $this->loginAs($admin);

        $missing = $this->withSession()->post('/admin/scheduled-tasks/create', $this->withCsrf([
            'name'             => '',
            'handler'          => '',
            'interval_minutes' => '',
        ]));

        $missing->assertStatus(400);
        $this->assertSame(0, model(ScheduledTaskModel::class)->where('name', '')->countAllResults());
    }

    /**
     * CRITICAL gate (plan §4): a `handler` outside the fixed allowlist must
     * be rejected at creation — this is what stands in for the legacy
     * DB-driven class/method reflection RCE.
     */
    public function testCreateRejectsHandlerNotInAllowlist(): void
    {
        $admin = $this->seedWebUser(['email' => 'creator3@example.com', 'zone' => 'admin']);
        $this->loginAs($admin);

        $result = $this->withSession()->post('/admin/scheduled-tasks/create', $this->withCsrf([
            'name'             => 'Malicious',
            'handler'          => 'App\\Libraries\\Tasks\\Evil::run',
            'interval_minutes' => 60,
        ]));

        $result->assertStatus(400);
        $this->assertNull(model(ScheduledTaskModel::class)->where('name', 'Malicious')->first());
    }

    public function testCreateRejectsInvalidJsonParams(): void
    {
        $admin = $this->seedWebUser(['email' => 'creator4@example.com', 'zone' => 'admin']);
        $this->loginAs($admin);

        $result = $this->withSession()->post('/admin/scheduled-tasks/create', $this->withCsrf([
            'name'             => 'Bad params',
            'handler'          => 'emails.purge',
            'params'           => '{not json',
            'interval_minutes' => 60,
        ]));

        $result->assertStatus(400);
        $this->assertNull(model(ScheduledTaskModel::class)->where('name', 'Bad params')->first());
    }

    public function testEditReturnsTaskFields(): void
    {
        $admin = $this->seedWebUser(['email' => 'edit-admin@example.com', 'zone' => 'admin']);
        $model = model(ScheduledTaskModel::class);
        $id    = $model->insert(['name' => 'Purge notifications', 'handler' => 'notifications.purge', 'interval_minutes' => 30, 'enabled' => 1]);
        $this->loginAs($admin);

        $edit = $this->withSession()->get('/admin/scheduled-tasks/edit/' . $id);

        $edit->assertStatus(200);
        $json = json_decode($edit->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertSame('Purge notifications', $json['task']['name']);
        $this->assertSame('notifications.purge', $json['task']['handler']);
        $this->assertSame(30, $json['task']['interval_minutes']);
    }

    public function testEditRejectsUnknownTaskId(): void
    {
        $admin = $this->seedWebUser(['email' => 'edit-admin2@example.com', 'zone' => 'admin']);
        $this->loginAs($admin);

        $edit = $this->withSession()->get('/admin/scheduled-tasks/edit/999999');

        $edit->assertStatus(404);
    }

    public function testUpdatePersistsEditsAndRecomputesNextRunAtOnlyWhenIntervalChanges(): void
    {
        $admin = $this->seedWebUser(['email' => 'updater@example.com', 'zone' => 'admin']);
        $model = model(ScheduledTaskModel::class);
        $id    = $model->insert(['name' => 'Old name', 'handler' => 'emails.purge', 'interval_minutes' => 60, 'enabled' => 1, 'next_run_at' => '2020-01-01 00:00:00']);
        $this->loginAs($admin);

        // Renaming only: next_run_at must not be disturbed.
        $rename = $this->withSession()->post('/admin/scheduled-tasks/update', $this->withCsrf([
            'id' => $id, 'name' => 'New name', 'handler' => 'emails.purge', 'interval_minutes' => 60,
        ]));
        $rename->assertStatus(200);

        $afterRename = $model->findTask($id);
        $this->assertSame('New name', $afterRename->name);
        $this->assertSame('2020-01-01 00:00:00', $afterRename->next_run_at);

        // Changing the interval must recompute next_run_at from now.
        $reinterval = $this->withSession()->post('/admin/scheduled-tasks/update', $this->withCsrf([
            'id' => $id, 'name' => 'New name', 'handler' => 'emails.purge', 'interval_minutes' => 120,
        ]));
        $reinterval->assertStatus(200);

        $afterInterval = $model->findTask($id);
        $this->assertSame(120, (int) $afterInterval->interval_minutes);
        $this->assertGreaterThan(time(), strtotime($afterInterval->next_run_at));
    }

    public function testUpdateRejectsHandlerNotInAllowlist(): void
    {
        $admin = $this->seedWebUser(['email' => 'updater2@example.com', 'zone' => 'admin']);
        $model = model(ScheduledTaskModel::class);
        $id    = $model->insert(['name' => 'Task', 'handler' => 'emails.purge', 'interval_minutes' => 60, 'enabled' => 1]);
        $this->loginAs($admin);

        $result = $this->withSession()->post('/admin/scheduled-tasks/update', $this->withCsrf([
            'id' => $id, 'name' => 'Task', 'handler' => 'evil.reflection', 'interval_minutes' => 60,
        ]));

        $result->assertStatus(400);
        $this->assertSame('emails.purge', $model->findTask($id)->handler);
    }

    public function testUpdateRejectsUnknownTaskId(): void
    {
        $admin = $this->seedWebUser(['email' => 'updater3@example.com', 'zone' => 'admin']);
        $this->loginAs($admin);

        $update = $this->withSession()->post('/admin/scheduled-tasks/update', $this->withCsrf([
            'id' => 999999, 'name' => 'Nope', 'handler' => 'emails.purge', 'interval_minutes' => 60,
        ]));

        $update->assertStatus(404);
    }

    public function testDeleteHardRemovesTask(): void
    {
        $admin = $this->seedWebUser(['email' => 'deleter@example.com', 'zone' => 'admin']);
        $model = model(ScheduledTaskModel::class);
        $id    = $model->insert(['name' => 'Delete me', 'handler' => 'emails.purge', 'interval_minutes' => 60]);
        $this->loginAs($admin);

        $delete = $this->withSession()->post('/admin/scheduled-tasks/delete', $this->withCsrf(['id' => $id]));

        $delete->assertStatus(200);
        $this->assertNull($model->findTask($id));
    }

    public function testDeleteRejectsUnknownTaskId(): void
    {
        $admin = $this->seedWebUser(['email' => 'deleter2@example.com', 'zone' => 'admin']);
        $this->loginAs($admin);

        $delete = $this->withSession()->post('/admin/scheduled-tasks/delete', $this->withCsrf(['id' => 999999]));

        $delete->assertStatus(404);
    }

    public function testToggleFlipsEnabled(): void
    {
        $admin = $this->seedWebUser(['email' => 'toggler@example.com', 'zone' => 'admin']);
        $model = model(ScheduledTaskModel::class);
        $id    = $model->insert(['name' => 'Task', 'handler' => 'emails.purge', 'interval_minutes' => 60, 'enabled' => 1]);
        $this->loginAs($admin);

        $off = $this->withSession()->post('/admin/scheduled-tasks/toggle', $this->withCsrf(['id' => $id, 'enabled' => 0]));
        $off->assertStatus(200);
        $this->assertSame(0, (int) $model->findTask($id)->enabled);

        $on = $this->withSession()->post('/admin/scheduled-tasks/toggle', $this->withCsrf(['id' => $id, 'enabled' => 1]));
        $on->assertStatus(200);
        $this->assertSame(1, (int) $model->findTask($id)->enabled);
    }

    public function testToggleRejectsUnknownTaskId(): void
    {
        $admin = $this->seedWebUser(['email' => 'toggler2@example.com', 'zone' => 'admin']);
        $this->loginAs($admin);

        $result = $this->withSession()->post('/admin/scheduled-tasks/toggle', $this->withCsrf(['id' => 999999, 'enabled' => 1]));

        $result->assertStatus(404);
    }

    public function testRunNowExecutesAndReturnsResult(): void
    {
        $admin = $this->seedWebUser(['email' => 'runner@example.com', 'zone' => 'admin']);
        $model = model(ScheduledTaskModel::class);
        $id    = $model->insert(['name' => 'Purge notifications', 'handler' => 'notifications.purge', 'interval_minutes' => 60, 'enabled' => 1]);
        $this->loginAs($admin);

        $run = $this->withSession()->post('/admin/scheduled-tasks/run', $this->withCsrf(['id' => $id]));

        $run->assertStatus(200);
        $json = json_decode($run->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertSame('success', $json['last_status']);
        $this->assertStringContainsString('Purged', $json['last_result']);

        $this->assertSame('success', $model->findTask($id)->last_status);
        $this->assertNotNull($model->findTask($id)->last_run_at);
    }

    public function testRunNowRejectsUnknownTaskId(): void
    {
        $admin = $this->seedWebUser(['email' => 'runner2@example.com', 'zone' => 'admin']);
        $this->loginAs($admin);

        $run = $this->withSession()->post('/admin/scheduled-tasks/run', $this->withCsrf(['id' => 999999]));

        $run->assertStatus(404);
    }

    public function testNonAdminClientSessionIsRejectedByAdminGate(): void
    {
        $client = $this->seedWebUser(['email' => 'client-gate@example.com', 'zone' => 'user']);
        $this->loginAs($client);

        $index = $this->withSession()->get('/admin/scheduled-tasks');
        $index->assertRedirectTo('/dashboard');

        $create = $this->withSession()->post('/admin/scheduled-tasks/create', $this->withCsrf([
            'name' => 'should_not_be_created', 'handler' => 'emails.purge', 'interval_minutes' => 60,
        ]));
        $create->assertRedirectTo('/dashboard');
        $this->assertNull(model(ScheduledTaskModel::class)->where('name', 'should_not_be_created')->first());
    }

    public function testGuestIsRedirectedToLoginFromAdminScheduledTasksIndex(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $result = $this->get('/admin/scheduled-tasks');

        $result->assertRedirectTo('/login');
    }
}
