<?php

namespace App\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Model;

/**
 * Over the `email_templates` table (see
 * kanban/processing/20260714-admin-emails.md). Keyed by `(name, lang_code)`;
 * `content` is trusted admin-authored HTML, stored verbatim in the DB and
 * never written to disk or eval'd (the legacy `Email_templates::update()`
 * wrote it into an executable `.php` view file — an RCE vector that is
 * deliberately not ported here, see the plan's §7).
 */
class EmailTemplateModel extends Model
{
    /**
     * Sortable columns for the admin email-templates list (`searchAdmin()`);
     * anything else falls back to `id`.
     */
    public const SORT_ALLOWLIST = ['id', 'name', 'lang_code', 'subject', 'active', 'created_at'];

    /**
     * Columns searched by a `keyword` filter term in `searchAdmin()`.
     */
    private const KEYWORD_FIELDS = ['name', 'subject'];

    protected $table            = 'email_templates';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = true;
    protected $protectFields    = true;
    protected $allowedFields    = ['name', 'lang_code', 'subject', 'content', 'active'];
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = ''; // no updated_at column on email_templates
    protected $deletedField     = 'deleted_at';

    /**
     * Typed wrapper over `find()` (whose generic signature phpstan can't
     * narrow to `object` from `$returnType`) — mirrors `ServerModel::findServer()`.
     */
    public function findTemplate(int $id): ?object
    {
        return $this->find($id);
    }

    /**
     * Manual composite-unique check for `(name, lang_code)` — CI4's
     * `is_unique` rule can't span two columns. Used by `Admin\EmailTemplates`
     * before insert/update; the DB `UNIQUE(name, lang_code)` key (`lang_UN`)
     * is the backstop against a race.
     */
    public function existsForNameLang(string $name, string $langCode, ?int $excludeId = null): bool
    {
        $builder = $this->builder()
            ->where('name', $name)
            ->where('lang_code', $langCode);

        if ($excludeId !== null) {
            $builder->where('id !=', $excludeId);
        }

        return $builder->countAllResults() > 0;
    }

    /**
     * Admin list search (`Admin\EmailTemplates::index()`): keyword LIKE
     * across name/subject, optional exact `lang_code` filter. `id` is always
     * a secondary sort key in the same direction so ties resolve
     * deterministically across pages — mirrors `TranslationModel::searchAdmin()`.
     * Soft-deleted rows are excluded automatically by `useSoftDeletes`.
     *
     * @param array{keyword?: string, lang_code?: string} $filters
     *
     * @return list<object>
     */
    public function searchAdmin(array $filters, string $sort = 'id', string $order = 'desc', int $limit = 25, int $offset = 0): array
    {
        $this->applyAdminFilters($this->builder(), $filters);

        $sortColumn = in_array($sort, self::SORT_ALLOWLIST, true) ? $sort : 'id';
        $sortOrder  = strtolower($order) === 'asc' ? 'ASC' : 'DESC';

        $this->orderBy($sortColumn, $sortOrder);
        if ($sortColumn !== 'id') {
            $this->orderBy('id', $sortOrder);
        }

        return $this->findAll($limit, $offset);
    }

    /**
     * @param array{keyword?: string, lang_code?: string} $filters
     */
    public function countAdmin(array $filters): int
    {
        $this->applyAdminFilters($this->builder(), $filters);

        return $this->countAllResults();
    }

    /**
     * The one place `keyword`/`lang_code` get translated into WHERE clauses
     * for the admin list — shared by `searchAdmin()`/`countAdmin()` so the
     * paginated rows and the total can't drift apart.
     *
     * @param array{keyword?: string, lang_code?: string} $filters
     */
    private function applyAdminFilters(BaseBuilder $builder, array $filters): void
    {
        $langCode = trim((string) ($filters['lang_code'] ?? ''));
        if ($langCode !== '') {
            $builder->where('lang_code', $langCode);
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $builder->groupStart();

            foreach (self::KEYWORD_FIELDS as $i => $field) {
                $i === 0 ? $builder->like($field, $keyword) : $builder->orLike($field, $keyword);
            }
            $builder->groupEnd();
        }
    }

    /**
     * Distinct language codes present in the table — feeds the admin filter
     * dropdown. ponytail: one query, no separate `languages` reference table
     * (none exists in the live schema) — mirrors `TranslationModel::distinctLangCodes()`.
     *
     * @return list<string>
     */
    public function distinctLangCodes(): array
    {
        $rows = $this->builder()
            ->select('lang_code')
            ->distinct()
            ->orderBy('lang_code', 'ASC')
            ->get()
            ->getResult();

        return array_values(array_filter(array_map(
            static fn (object $row): ?string => $row->lang_code,
            $rows,
        ), static fn (?string $code): bool => $code !== null));
    }
}
