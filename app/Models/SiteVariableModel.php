<?php

namespace App\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Model;

/**
 * Over the `site_variable` table (see kanban/processing/20260714-admin-settings.md).
 * Key/value app config, no `updated_at` column on the live schema.
 */
class SiteVariableModel extends Model
{
    /**
     * Sortable columns for the admin settings list (`searchAdmin()`);
     * anything else falls back to `name`.
     */
    public const SORT_ALLOWLIST = ['id', 'name', 'value', 'type', 'created_at'];

    /**
     * Columns searched by a `keyword` filter term in `searchAdmin()`.
     */
    private const KEYWORD_FIELDS = ['name', 'value', 'description'];

    protected $table            = 'site_variable';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = true;
    protected $protectFields    = true;
    protected $allowedFields    = ['name', 'value', 'type', 'description'];
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = ''; // no updated_at column on site_variable
    protected $deletedField     = 'deleted_at';

    /**
     * Typed wrapper over `find()` (whose generic signature phpstan can't
     * narrow to `object` from `$returnType`) — mirrors `ServerModel::findServer()`.
     */
    public function findVariable(int $id): ?object
    {
        return $this->find($id);
    }

    /**
     * Typed lookup by `name` (the UNIQUE key) — the counterpart to
     * `findVariable()` for callers that hold a setting name rather than an id,
     * e.g. an upsert. Returns null for an unknown or soft-deleted row.
     */
    public function findByName(string $name): ?object
    {
        $row = $this->where('name', $name)->first();

        return is_object($row) ? $row : null;
    }

    /**
     * Read a stored variable's value by name, falling back to `$default`
     * when the name is unknown or soft-deleted. Used by `get_site_variable()`
     * (app_helper) so stored settings take effect app-wide.
     */
    public function getValue(string $name, ?string $default = null): ?string
    {
        $row = $this->findByName($name);

        return $row !== null && $row->value !== null ? $row->value : $default;
    }

    /**
     * Create-or-update by name. One place, so a caller can't half-implement the
     * upsert and hit the UNIQUE(name) key.
     */
    public function setValue(string $name, string $value): void
    {
        $existing = $this->findByName($name);

        $existing === null
            ? $this->insert(['name' => $name, 'value' => $value])
            : $this->update((int) $existing->id, ['value' => $value]);
    }

    /**
     * Admin list search (`Admin\Settings::index()`): keyword LIKE across
     * name/value/description, optional `type` filter. `id` is always a
     * secondary sort key in the same direction so ties resolve
     * deterministically across pages — mirrors `ServerModel::searchAdmin()`.
     * Soft-deleted rows are excluded automatically by `useSoftDeletes`.
     *
     * @param array{keyword?: string, type?: string} $filters
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
     * @param array{keyword?: string, type?: string} $filters
     */
    public function countAdmin(array $filters): int
    {
        $this->applyAdminFilters($this->builder(), $filters);

        return $this->countAllResults();
    }

    /**
     * The one place `keyword`/`type` get translated into WHERE clauses for
     * the admin list — shared by `searchAdmin()`/`countAdmin()` so the
     * paginated rows and the total can't drift apart.
     *
     * @param array{keyword?: string, type?: string} $filters
     */
    private function applyAdminFilters(BaseBuilder $builder, array $filters): void
    {
        $type = trim((string) ($filters['type'] ?? ''));
        if ($type !== '') {
            $builder->where('type', $type);
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
