<?php

namespace App\Controllers\Client;

use App\Controllers\BaseController;
use App\Models\ApiHistoryModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Self-service API history viewer, strictly scoped to the current session
 * user (`session('userWorking')->id`) — the same convention as
 * `Client\ApiAccessKeys`. Every read/mutation resolves the owning `user_id`
 * from the session and is never accepted from request input, and
 * `findOwned()`/`deleteOwned()` fetch by `id` AND `user_id` first (no IDOR).
 * Deletes are soft-deletes only (the model's `useSoftDeletes` scope).
 */
class ApiHistory extends BaseController
{
    public function logs(): string
    {
        $me      = session('userWorking');
        $model   = model(ApiHistoryModel::class);
        $page    = max(1, (int) $this->request->getGet('page'));
        $limit   = $this->resolveLimit();
        $offset  = ($page - 1) * $limit;
        $sortBy  = (string) $this->request->getGet('sort_by');
        $sort    = (string) $this->request->getGet('sort');
        $filters = $this->filtersFromRequest((int) $me->id);

        return view('client/api_history/index', [
            'items'   => $model->searchHistory($filters, $sortBy, $sort, $limit, $offset),
            'total'   => $model->countHistory($filters),
            'page'    => $page,
            'limit'   => $limit,
            'sortBy'  => $sortBy,
            'sort'    => $sort,
            'filters' => $filters,
        ]);
    }

    /**
     * JSON detail for the preview modal. Returns the raw stored strings
     * (params/response/metadata) — the view binds them via Alpine `x-text`,
     * never `x-html`, so escaping is the frontend's job, not this endpoint's.
     */
    public function preview(): ResponseInterface
    {
        $me  = session('userWorking');
        $id  = (int) $this->request->getGet('id');
        $row = $id > 0 ? model(ApiHistoryModel::class)->findOwned($id, (int) $me->id) : null;

        if ($row === null) {
            return $this->jsonFailed('Record not found.', 404);
        }

        return $this->jsonSuccess('OK', ['row' => $row]);
    }

    public function delete(): ResponseInterface
    {
        $me = session('userWorking');
        $id = (int) $this->request->getPost('id');

        if ($id <= 0 || ! model(ApiHistoryModel::class)->deleteOwned($id, (int) $me->id)) {
            return $this->jsonFailed('Record not found.', 404);
        }

        return $this->jsonSuccess('Log entry has been deleted.');
    }

    public function deleteAll(): ResponseInterface
    {
        $me      = session('userWorking');
        $filters = $this->filtersFromRequest((int) $me->id);
        $count   = model(ApiHistoryModel::class)->deleteFiltered($filters);

        return $this->jsonSuccess("{$count} log(s) deleted.", ['deleted' => $count]);
    }

    public function deleteByAge(): ResponseInterface
    {
        if (! $this->validate([
            'days' => 'required|is_natural_no_zero',
        ])) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $me    = session('userWorking');
        $days  = (int) $this->request->getPost('days');
        $count = model(ApiHistoryModel::class)->deleteByAge($days, (int) $me->id);

        return $this->jsonSuccess("{$count} log(s) deleted.", ['deleted' => $count]);
    }

    /**
     * Shared GET+POST filter extraction (`getVar()` reads both) so the list
     * query string and the delete-all POST body use identical filter
     * semantics — always force-scoped to the caller's `user_id`.
     *
     * @return array{user_id: int, keyword: string, service_name: string, service_id: string, env: string, status: string}
     */
    private function filtersFromRequest(int $userId): array
    {
        return [
            'user_id'      => $userId,
            'keyword'      => trim((string) $this->request->getVar('keyword')),
            'service_name' => trim((string) $this->request->getVar('service_name')),
            'service_id'   => trim((string) $this->request->getVar('service_id')),
            'env'          => trim((string) $this->request->getVar('env')),
            'status'       => (string) $this->request->getVar('status'),
        ];
    }

    private function resolveLimit(): int
    {
        $limitGet = (int) $this->request->getGet('limit');

        if ($limitGet > 0) {
            return $limitGet;
        }

        $default = (int) getenv('app.rpp_default');

        return $default > 0 ? $default : 25;
    }
}
