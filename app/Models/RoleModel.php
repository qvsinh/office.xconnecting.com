<?php

namespace App\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Model;

/**
 * Over the `roles` table — grants a named set of `permissions` (via
 * `role_permissions`) to every `users` row carrying its `id` as `role_id`.
 * Backs `user_can()` (`app/Helpers/auth_helper.php`) and the (future) admin
 * roles UI.
 */
class RoleModel extends Model
{
    /**
     * Sortable columns for the admin roles list (`searchAdmin()`); anything
     * else falls back to `name`.
     */
    public const SORT_ALLOWLIST = ['id', 'name', 'label', 'created_at'];

    /**
     * Columns searched by a `keyword` filter term in `searchAdmin()`.
     */
    private const KEYWORD_FIELDS = ['name', 'label'];

    protected $table            = 'roles';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = ['name', 'label'];
    protected $useTimestamps    = false;
    protected $createdField     = 'created_at';
    protected $updatedField     = '';
    protected $deletedField     = '';

    /**
     * Typed wrapper over `find()` (whose generic signature phpstan can't
     * narrow to `object` from `$returnType`) — mirrors `PermissionModel::findPermission()`.
     */
    public function findRole(int $id): ?object
    {
        return $this->find($id);
    }

    public function findByName(string $name): ?object
    {
        return $this->where('name', $name)->first();
    }

    /**
     * Admin list search (`Admin\Roles::index()`, a later card): keyword LIKE
     * across name/label. `id` is always a secondary sort key in the same
     * direction so ties resolve deterministically across pages — mirrors
     * `TranslationModel::searchAdmin()`.
     *
     * @param array{keyword?: string} $filters
     *
     * @return list<object>
     */
    public function searchAdmin(array $filters, string $sort = 'name', string $order = 'asc', int $limit = 25, int $offset = 0): array
    {
        $this->applyAdminFilters($this->builder(), $filters);

        $sortColumn = in_array($sort, self::SORT_ALLOWLIST, true) ? $sort : 'name';
        $sortOrder  = strtolower($order) === 'desc' ? 'DESC' : 'ASC';

        $this->orderBy($sortColumn, $sortOrder);
        if ($sortColumn !== 'id') {
            $this->orderBy('id', $sortOrder);
        }

        return $this->findAll($limit, $offset);
    }

    /**
     * @param array{keyword?: string} $filters
     */
    public function countAdmin(array $filters): int
    {
        $this->applyAdminFilters($this->builder(), $filters);

        return $this->countAllResults();
    }

    /**
     * Permission names granted to a role, joined through `role_permissions`.
     * The lookup `user_can()` runs (once per role per request, cached) to
     * decide whether the session's working user's role grants a permission.
     *
     * @return list<string>
     */
    public function permissionsFor(int $roleId): array
    {
        $rows = $this->db->table('role_permissions')
            ->select('permissions.name')
            ->join('permissions', 'permissions.id = role_permissions.permission_id')
            ->where('role_permissions.role_id', $roleId)
            ->get()
            ->getResultArray();

        return array_column($rows, 'name');
    }

    /**
     * Replaces a role's permission grants wholesale (delete then reinsert) —
     * the one place `role_permissions` rows are written, so the admin roles
     * UI (a later card) can never leave a stale grant behind.
     *
     * @param list<int> $permissionIds
     */
    public function syncPermissions(int $roleId, array $permissionIds): void
    {
        $this->db->table('role_permissions')->where('role_id', $roleId)->delete();

        $permissionIds = array_unique($permissionIds);
        if ($permissionIds === []) {
            return;
        }

        $rows = array_map(
            static fn (int $permissionId): array => ['role_id' => $roleId, 'permission_id' => $permissionId],
            $permissionIds,
        );

        $this->db->table('role_permissions')->insertBatch($rows);
    }

    /**
     * @param array{keyword?: string} $filters
     */
    private function applyAdminFilters(BaseBuilder $builder, array $filters): void
    {
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
