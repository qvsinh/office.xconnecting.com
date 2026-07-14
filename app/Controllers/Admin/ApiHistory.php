<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\ApiHistoryModel;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Admin API history viewer over every user's `api_history` rows. Gated by
 * `authFilter` + `adminFilter` at the route (see Config/Routes.php). Unlike
 * `Client\ApiHistory`, nothing here is user-scoped by default — the extra
 * `user` filter (email or id) narrows to one user on request, resolved via
 * `applyHistoryFilters()`'s existing `user_id` key so filter semantics never
 * drift between the user and admin controllers. No delete-by-age here
 * (kanban plan §7 — user-only for now).
 */
class ApiHistory extends BaseController
{
    public function logs(): string
    {
        $model     = model(ApiHistoryModel::class);
        $userModel = model(UserModel::class);
        $page      = max(1, (int) $this->request->getGet('page'));
        $limit     = $this->resolveLimit();
        $offset    = ($page - 1) * $limit;
        $sortBy    = (string) $this->request->getGet('sort_by');
        $sort      = (string) $this->request->getGet('sort');
        $filters   = $this->filtersFromRequest();

        return view('admin/api_history/index', [
            'items'   => $this->attachUserInfo($model->searchHistory($filters, $sortBy, $sort, $limit, $offset), $userModel),
            'total'   => $model->countHistory($filters),
            'page'    => $page,
            'limit'   => $limit,
            'sortBy'  => $sortBy,
            'sort'    => $sort,
            'filters' => $filters,
            'users'   => $userModel->select('id, name, email')->orderBy('name', 'ASC')->findAll(),
        ]);
    }

    public function preview(): ResponseInterface
    {
        $id  = (int) $this->request->getGet('id');
        $row = $id > 0 ? model(ApiHistoryModel::class)->findByIdAdmin($id) : null;

        if ($row === null) {
            return $this->jsonFailed('Record not found.', 404);
        }

        return $this->jsonSuccess('OK', ['row' => $row]);
    }

    public function delete(): ResponseInterface
    {
        $id    = (int) $this->request->getPost('id');
        $model = model(ApiHistoryModel::class);

        if ($id <= 0 || $model->findByIdAdmin($id) === null) {
            return $this->jsonFailed('Record not found.', 404);
        }

        $model->delete($id);

        return $this->jsonSuccess('Log entry has been deleted.');
    }

    public function deleteAll(): ResponseInterface
    {
        $count = model(ApiHistoryModel::class)->deleteFiltered($this->filtersFromRequest());

        return $this->jsonSuccess("{$count} log(s) deleted.", ['deleted' => $count]);
    }

    /**
     * Same base filters as the client controller, plus `user` (email or
     * numeric id) resolved to `user_id`. An email that doesn't match any
     * user, or a non-numeric/non-email value, resolves to `user_id = 0`
     * (matches nothing) rather than silently ignoring the filter.
     *
     * @return array{user_id?: int, keyword: string, service_name: string, service_id: string, env: string, status: string}
     */
    private function filtersFromRequest(): array
    {
        $filters = [
            'keyword'      => trim((string) $this->request->getVar('keyword')),
            'service_name' => trim((string) $this->request->getVar('service_name')),
            'service_id'   => trim((string) $this->request->getVar('service_id')),
            'env'          => trim((string) $this->request->getVar('env')),
            'status'       => (string) $this->request->getVar('status'),
        ];

        $user = trim((string) $this->request->getVar('user'));
        if ($user === '') {
            return $filters;
        }

        if (filter_var($user, FILTER_VALIDATE_EMAIL)) {
            $found              = model(UserModel::class)->where('email', $user)->first();
            $filters['user_id'] = $found->id ?? 0;
        } elseif (ctype_digit($user)) {
            $filters['user_id'] = (int) $user;
        } else {
            $filters['user_id'] = 0;
        }

        return $filters;
    }

    /**
     * Batch-attach `user_name`/`user_email` onto each row for display,
     * without a join in the model (keeps `searchHistory()`/`countHistory()`
     * shared, unaliased, and simple for both the user and admin callers).
     *
     * Fetched as plain arrays (`getResultArray()`, not the model's
     * `findAll()`) so the result type is unambiguous — `UserModel`'s
     * `returnType = 'object'` makes phpstan infer `array<mixed>|object` on
     * `findAll()`, which can't be property-accessed without a cast.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function attachUserInfo(array $rows, UserModel $userModel): array
    {
        $userIds = array_values(array_unique(array_filter(array_column($rows, 'user_id'))));

        if ($userIds === []) {
            return $rows;
        }

        $usersById = [];
        $users     = $userModel->builder()
            ->select('id, name, email')
            ->whereIn('id', $userIds)
            ->where('deleted_at', null) // raw builder() bypasses the model's soft-delete scope
            ->get()->getResultArray();

        foreach ($users as $user) {
            $usersById[(int) $user['id']] = $user;
        }

        foreach ($rows as &$row) {
            $user              = $usersById[(int) $row['user_id']] ?? null;
            $row['user_name']  = $user['name'] ?? null;
            $row['user_email'] = $user['email'] ?? null;
        }
        unset($row);

        return $rows;
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
