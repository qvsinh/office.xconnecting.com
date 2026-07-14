<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\ScheduledTaskRunner;
use App\Models\ScheduledTaskModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Admin scheduled-task (cron) management: list/search/paginate, create/edit/
 * delete, enable/disable (`toggle`), and manually run one job (`runNow`).
 * Gated by `authFilter` + `adminFilter` at the route (see Config/Routes.php).
 * Mirrors `Admin\Translations`/`Admin\Servers`.
 *
 * `handler` is always validated against `ScheduledTaskRunner::handlerKeys()`
 * — the fixed allowlist — never trusted free text (plan §4, CRITICAL gate).
 */
class ScheduledTasks extends BaseController
{
    public function index(): string
    {
        $page    = max(1, (int) $this->request->getGet('page'));
        $limit   = $this->resolveLimit();
        $offset  = ($page - 1) * $limit;
        $sort    = (string) $this->request->getGet('sort');
        $order   = (string) $this->request->getGet('order');
        $filters = [
            'keyword' => trim((string) $this->request->getGet('keyword')),
            'handler' => trim((string) $this->request->getGet('handler')),
            'enabled' => (string) $this->request->getGet('enabled'),
        ];

        $taskModel = model(ScheduledTaskModel::class);
        $runner    = new ScheduledTaskRunner();

        return view('admin/scheduled_tasks/index', [
            'items'       => $taskModel->searchAdmin($filters, $sort !== '' ? $sort : 'name', $order !== '' ? $order : 'asc', $limit, $offset),
            'total'       => $taskModel->countAdmin($filters),
            'page'        => $page,
            'limit'       => $limit,
            'sort'        => $sort,
            'order'       => $order,
            'filters'     => $filters,
            'handlerKeys' => $runner->handlerKeys(),
        ]);
    }

    public function create(): ResponseInterface
    {
        $runner = new ScheduledTaskRunner();

        if (! $this->validate($this->taskValidationRules($runner->handlerKeys(), false))) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $params = trim((string) $this->request->getPost('params'));
        if (! $this->isValidJsonOrEmpty($params)) {
            return $this->jsonFailed('Params must be valid JSON.');
        }

        $intervalMinutes = (int) $this->request->getPost('interval_minutes');

        model(ScheduledTaskModel::class)->insert([
            'name'             => trim((string) $this->request->getPost('name')),
            'handler'          => (string) $this->request->getPost('handler'),
            'params'           => $params !== '' ? $params : null,
            'interval_minutes' => $intervalMinutes,
            'enabled'          => $this->request->getPost('enabled') !== null ? (int) $this->request->getPost('enabled') : 1,
            'description'      => trim((string) $this->request->getPost('description')),
            'next_run_at'      => $this->nextRunAtFromNow($intervalMinutes),
        ]);

        return $this->jsonSuccess('Scheduled task has been created successfully.');
    }

    /**
     * JSON task record for the edit modal (AJAX-fetched).
     */
    public function edit(int $id): ResponseInterface
    {
        $task = model(ScheduledTaskModel::class)->findTask($id);

        if (! $task) {
            return $this->jsonFailed('Scheduled task not found.', 404);
        }

        return $this->jsonSuccess('OK', ['task' => [
            'id'               => (int) $task->id,
            'name'             => $task->name,
            'handler'          => $task->handler,
            'params'           => $task->params,
            'interval_minutes' => (int) $task->interval_minutes,
            'enabled'          => (int) $task->enabled,
            'description'      => $task->description,
            'last_run_at'      => $task->last_run_at,
            'next_run_at'      => $task->next_run_at,
            'last_status'      => $task->last_status,
            'last_result'      => $task->last_result,
            'last_duration_ms' => $task->last_duration_ms !== null ? (int) $task->last_duration_ms : null,
        ]]);
    }

    public function update(): ResponseInterface
    {
        $runner = new ScheduledTaskRunner();
        $rules  = $this->taskValidationRules($runner->handlerKeys(), true);

        if (! $this->validate($rules)) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $params = trim((string) $this->request->getPost('params'));
        if (! $this->isValidJsonOrEmpty($params)) {
            return $this->jsonFailed('Params must be valid JSON.');
        }

        $id        = (int) $this->request->getPost('id');
        $taskModel = model(ScheduledTaskModel::class);
        $task      = $taskModel->findTask($id);

        if (! $task) {
            return $this->jsonFailed('Scheduled task not found.', 404);
        }

        $intervalMinutes = (int) $this->request->getPost('interval_minutes');

        $data = [
            'name'             => trim((string) $this->request->getPost('name')),
            'handler'          => (string) $this->request->getPost('handler'),
            'params'           => $params !== '' ? $params : null,
            'interval_minutes' => $intervalMinutes,
            'enabled'          => $this->request->getPost('enabled') !== null ? (int) $this->request->getPost('enabled') : (int) $task->enabled,
            'description'      => trim((string) $this->request->getPost('description')),
        ];

        // Only reset the schedule clock when the interval actually changed —
        // renaming a task or editing its description must not push its next
        // run out.
        if ($intervalMinutes !== (int) $task->interval_minutes) {
            $data['next_run_at'] = $this->nextRunAtFromNow($intervalMinutes);
        }

        $taskModel->update($id, $data);

        return $this->jsonSuccess('Scheduled task has been updated successfully.');
    }

    public function delete(): ResponseInterface
    {
        if (! $this->validate(['id' => 'required|is_natural_no_zero'])) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $id        = (int) $this->request->getPost('id');
        $taskModel = model(ScheduledTaskModel::class);

        if (! $taskModel->findTask($id)) {
            return $this->jsonFailed('Scheduled task not found.', 404);
        }

        $taskModel->delete($id); // hard delete: no soft-delete column on `scheduled_tasks`

        return $this->jsonSuccess('Scheduled task has been deleted.');
    }

    public function toggle(): ResponseInterface
    {
        $id      = (int) $this->request->getPost('id');
        $enabled = (int) $this->request->getPost('enabled');

        if ($id <= 0 || ! in_array($enabled, [0, 1], true)) {
            return $this->jsonFailed('Invalid request.');
        }

        $taskModel = model(ScheduledTaskModel::class);
        $task      = $taskModel->findTask($id);

        if (! $task) {
            return $this->jsonFailed('Scheduled task not found.', 404);
        }

        // Audit-logged: enabling a task arms a recurring destructive purge on a schedule.
        log_message('info', 'Admin #{adminId} {action} scheduled task #{taskId} ({handler}).', [
            'adminId' => session('userAuthenticated')->id ?? null,
            'action'  => $enabled === 1 ? 'enabled' : 'disabled',
            'taskId'  => $task->id,
            'handler' => $task->handler,
        ]);

        $taskModel->setEnabled($id, $enabled === 1);

        return $this->jsonSuccess('Scheduled task has been ' . ($enabled === 1 ? 'enabled' : 'disabled') . '.', ['enabled' => $enabled]);
    }

    /**
     * Manually runs one task immediately via the same `ScheduledTaskRunner`
     * dispatch path `schedule:run` uses (plan §4.3) — audit-logged since this
     * is an admin-triggered side-effecting action outside the normal cron.
     */
    public function runNow(): ResponseInterface
    {
        if (! $this->validate(['id' => 'required|is_natural_no_zero'])) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $id        = (int) $this->request->getPost('id');
        $taskModel = model(ScheduledTaskModel::class);
        $task      = $taskModel->findTask($id);

        if (! $task) {
            return $this->jsonFailed('Scheduled task not found.', 404);
        }

        $admin = session('userAuthenticated');

        log_message('info', 'Admin #{adminId} manually ran scheduled task #{taskId} ({handler}).', [
            'adminId' => $admin->id ?? null,
            'taskId'  => $task->id,
            'handler' => $task->handler,
        ]);

        $outcome = (new ScheduledTaskRunner())->runTask($task);

        return $this->jsonSuccess('Scheduled task has been run.', [
            'last_status' => $outcome['status'],
            'last_result' => $outcome['result'],
        ]);
    }

    /**
     * @param list<string> $handlerKeys
     *
     * @return array<string, string>
     */
    private function taskValidationRules(array $handlerKeys, bool $isUpdate): array
    {
        $rules = [
            'name'             => 'required|max_length[255]',
            'handler'          => 'required|in_list[' . implode(',', $handlerKeys) . ']',
            'interval_minutes' => 'required|is_natural_no_zero',
            'description'      => 'permit_empty',
            'enabled'          => 'permit_empty|in_list[0,1]',
        ];

        if ($isUpdate) {
            $rules['id'] = 'required|is_natural_no_zero';
        }

        return $rules;
    }

    /**
     * `params` isn't validatable with a built-in CI4 rule (JSON, not a
     * scalar shape) — checked manually here, mirrors `Admin\Translations`'
     * manual composite-unique check outside `validate()`. Empty is allowed
     * (handlers fall back to their own defaults).
     */
    private function isValidJsonOrEmpty(string $params): bool
    {
        if ($params === '') {
            return true;
        }

        json_decode($params, true);

        return json_last_error() === JSON_ERROR_NONE;
    }

    private function nextRunAtFromNow(int $intervalMinutes): string
    {
        return date('Y-m-d H:i:s', time() + ($intervalMinutes * 60));
    }

    private function resolveLimit(): int
    {
        $limitGet = (int) $this->request->getGet('limit');

        if ($limitGet > 0) {
            return min($limitGet, 100); // clamp: unbounded limit would let one request pull the whole table (OOM/DoS)
        }

        $default = (int) getenv('app.rpp_default');

        return $default > 0 ? min($default, 100) : 25;
    }
}
