<?php

namespace App\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Model;

/**
 * Over the `emails` table (see kanban/processing/20260714-admin-emails.md) —
 * the sent-message log. `id` is a VARCHAR token (not auto-increment), unlike
 * every other admin model here. Read-only in this task: the send pipeline
 * that writes rows doesn't exist yet (out of scope), so only lookups are
 * exercised, but `$allowedFields` is kept honest for that future writer.
 */
class EmailModel extends Model
{
    /**
     * Sortable columns for the admin sent-email list (`searchAdmin()`);
     * anything else falls back to `created_at`.
     */
    public const SORT_ALLOWLIST = ['created_at', 'status', 'to_email', 'name'];

    /**
     * Columns searched by a `keyword` filter term in `searchAdmin()` when the
     * term isn't a valid email address (see `applyAdminFilters()`).
     */
    private const KEYWORD_FIELDS = ['name', 'subject', 'to_email'];

    protected $table            = 'emails';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = true;
    protected $protectFields    = true;
    protected $allowedFields    = ['id', 'subject', 'content', 'from_email', 'to_email', 'status', 'name', 'error_message'];
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = ''; // no updated_at column on emails
    protected $deletedField     = 'deleted_at';

    /**
     * Typed wrapper over `find()` (whose generic signature phpstan can't
     * narrow to `object` from `$returnType`) — mirrors `ServerModel::findServer()`.
     * `id` is a VARCHAR token here, not an int.
     */
    public function findEmail(string $id): ?object
    {
        return $this->find($id);
    }

    /**
     * Admin list search (`Admin\Emails::index()`): keyword against the
     * recipient/subject/template name, optional `status` filter (0/1/2).
     * `created_at` is always a secondary sort key in the same direction so
     * ties resolve deterministically across pages. Soft-deleted rows are
     * excluded automatically by `useSoftDeletes`.
     *
     * @param array{keyword?: string, status?: int|string} $filters
     *
     * @return list<object>
     */
    public function searchAdmin(array $filters, string $sort = 'created_at', string $order = 'desc', int $limit = 25, int $offset = 0): array
    {
        $this->applyAdminFilters($this->builder(), $filters);

        $sortColumn = in_array($sort, self::SORT_ALLOWLIST, true) ? $sort : 'created_at';
        $sortOrder  = strtolower($order) === 'asc' ? 'ASC' : 'DESC';

        $this->orderBy($sortColumn, $sortOrder);
        if ($sortColumn !== 'created_at') {
            $this->orderBy('created_at', $sortOrder);
        }

        return $this->findAll($limit, $offset);
    }

    /**
     * @param array{keyword?: string, status?: int|string} $filters
     */
    public function countAdmin(array $filters): int
    {
        $this->applyAdminFilters($this->builder(), $filters);

        return $this->countAllResults();
    }

    /**
     * The one place `keyword`/`status` get translated into WHERE clauses for
     * the admin list — shared by `searchAdmin()`/`countAdmin()` so the
     * paginated rows and the total can't drift apart.
     *
     * A keyword that's a valid email address matches `to_email` exactly
     * (recipients are looked up precisely far more often than searched by
     * fragment); anything else falls back to a LIKE across
     * name/subject/to_email — mirrors the legacy `Admin\Emails::index()`.
     *
     * @param array{keyword?: string, status?: int|string} $filters
     */
    private function applyAdminFilters(BaseBuilder $builder, array $filters): void
    {
        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, ['0', '1', '2'], true)) {
            $builder->where('status', (int) $status);
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword === '') {
            return;
        }

        if (filter_var($keyword, FILTER_VALIDATE_EMAIL)) {
            $builder->where('to_email', $keyword);

            return;
        }

        $builder->groupStart();

        foreach (self::KEYWORD_FIELDS as $i => $field) {
            $i === 0 ? $builder->like($field, $keyword) : $builder->orLike($field, $keyword);
        }
        $builder->groupEnd();
    }
}
