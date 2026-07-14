<?php

namespace App\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Model;

/**
 * Over the existing `users` table (see legacy proxy.services.vn schema).
 * `returnType='object'` so `session('userWorking')->id` etc. keep working
 * exactly as the legacy app expects.
 */
class UserModel extends Model
{
    /**
     * Sortable columns for the admin user list (`searchAdmin()`); anything
     * else falls back to `login_at`.
     */
    public const SORT_ALLOWLIST = ['id', 'name', 'email', 'company', 'zone', 'env', 'status', 'login_at', 'created_at'];

    /**
     * Columns searched by a `keyword` filter term in `searchAdmin()`.
     */
    private const KEYWORD_FIELDS = ['name', 'email', 'company'];

    /**
     * Columns returned by `searchAdmin()` — explicitly excludes
     * `password`/`google2fa_secret` so the admin list result set never
     * carries a secret into the view layer, even by accident.
     */
    private const LIST_COLUMNS = 'id, name, email, company, zone, env, status, login_at, created_at';

    protected $table            = 'users';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = true;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'name',
        'email',
        'password',
        'zone',
        'env',
        'status',
        'two_factor_method',
        'language',
        'timezone',
        'login_at',
        'note',
    ];
    protected $useTimestamps = false;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';

    /**
     * Look up an active (non-soft-deleted) user by email. Soft-deleted rows
     * are excluded automatically because `useSoftDeletes` is enabled.
     */
    public function findActiveByEmail(string $email): ?object
    {
        return $this->where('email', $email)->first();
    }

    /**
     * Stamp `login_at` on successful authentication.
     */
    public function stampLogin(int $id): void
    {
        $this->update($id, ['login_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Typed wrapper over `find()` (whose generic signature phpstan can't
     * narrow to `object` from `$returnType`) for callers that need property
     * access on the result — mirrors `ApiAccessKeyModel::findKey()`.
     */
    public function findUser(int $id): ?object
    {
        return $this->find($id);
    }

    /**
     * Admin list search (`Admin\Users::index()`): keyword LIKE across
     * name/email/company, optional `zone`/`status` filters. `id` is always a
     * secondary sort key in the same direction so ties (e.g. many rows
     * sharing a NULL `login_at`) resolve deterministically across pages —
     * mirrors `ApiHistoryModel::searchHistory()`. Soft-deleted rows are
     * excluded automatically by `useSoftDeletes`.
     *
     * @param array{keyword?: string, zone?: string, status?: int|string} $filters
     *
     * @return list<object>
     */
    public function searchAdmin(array $filters, string $sort = 'login_at', string $order = 'desc', int $limit = 25, int $offset = 0): array
    {
        $this->select(self::LIST_COLUMNS);
        $this->applyAdminFilters($this->builder(), $filters);

        $sortColumn = in_array($sort, self::SORT_ALLOWLIST, true) ? $sort : 'login_at';
        $sortOrder  = strtolower($order) === 'asc' ? 'ASC' : 'DESC';

        $this->orderBy($sortColumn, $sortOrder);
        if ($sortColumn !== 'id') {
            $this->orderBy('id', $sortOrder);
        }

        return $this->findAll($limit, $offset);
    }

    /**
     * @param array{keyword?: string, zone?: string, status?: int|string} $filters
     */
    public function countAdmin(array $filters): int
    {
        $this->applyAdminFilters($this->builder(), $filters);

        return $this->countAllResults();
    }

    /**
     * Count of active (`status=1`), non-soft-deleted `zone=global` users —
     * backs the last-admin lockout guard in `Admin\Users::update()` so an
     * admin can never demote/deactivate the last remaining admin.
     */
    public function countActiveAdmins(): int
    {
        return $this->where('zone', 'global')->where('status', 1)->countAllResults();
    }

    /**
     * The one place `keyword`/`zone`/`status` get translated into WHERE
     * clauses for the admin list — shared by `searchAdmin()`/`countAdmin()`
     * so the paginated rows and the total can't drift apart.
     *
     * @param array{keyword?: string, zone?: string, status?: int|string} $filters
     */
    private function applyAdminFilters(BaseBuilder $builder, array $filters): void
    {
        $zone = (string) ($filters['zone'] ?? '');
        if (in_array($zone, ['global', 'client'], true)) {
            $builder->where('zone', $zone);
        }

        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, ['0', '1'], true)) {
            $builder->where('status', (int) $status);
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword === '') {
            return;
        }

        $builder->groupStart();

        foreach (self::KEYWORD_FIELDS as $i => $field) {
            $i === 0 ? $builder->like($field, $keyword) : $builder->orLike($field, $keyword);
        }
        $builder->groupEnd();
    }
}
