<?php

namespace App\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Model;

/**
 * Over the `permissions` table — dotted permission names (e.g.
 * `users.manage`) granted to roles via `role_permissions`. Backs `user_can()`
 * (`app/Helpers/auth_helper.php`) and the (future) admin roles UI.
 */
class PermissionModel extends Model
{
    /**
     * Sortable columns for the admin permissions list (`searchAdmin()`);
     * anything else falls back to `name`.
     */
    public const SORT_ALLOWLIST = ['id', 'name', 'label', 'created_at'];

    /**
     * Columns searched by a `keyword` filter term in `searchAdmin()`.
     */
    private const KEYWORD_FIELDS = ['name', 'label'];

    protected $table            = 'permissions';
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
     * narrow to `object` from `$returnType`) — mirrors `RoleModel::findRole()`.
     */
    public function findPermission(int $id): ?object
    {
        return $this->find($id);
    }

    public function findByName(string $name): ?object
    {
        return $this->where('name', $name)->first();
    }

    /**
     * Admin list search (`Admin\Permissions::index()`, a later card): keyword
     * LIKE across name/label. `id` is always a secondary sort key in the same
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
