<?php

namespace App\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Model;

/**
 * Over the `translation` table (see kanban/processing/20260714-admin-translations.md).
 * UI translation strings keyed by `(lang_code, slug)`. Live schema has no
 * timestamp or soft-delete columns — divergence from `SiteVariableModel`.
 */
class TranslationModel extends Model
{
    /**
     * Sortable columns for the admin translations list (`searchAdmin()`);
     * anything else falls back to `lang_code`.
     */
    public const SORT_ALLOWLIST = ['id', 'lang_code', 'slug'];

    /**
     * Columns searched by a `keyword` filter term in `searchAdmin()`.
     */
    private const KEYWORD_FIELDS = ['slug', 'content'];

    protected $table            = 'translation';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = ['lang_code', 'location', 'slug', 'content'];
    protected $useTimestamps    = false;
    protected $createdField     = '';
    protected $updatedField     = '';
    protected $deletedField     = '';

    /**
     * Typed wrapper over `find()` (whose generic signature phpstan can't
     * narrow to `object` from `$returnType`) — mirrors `SiteVariableModel::findVariable()`.
     */
    public function findTranslation(int $id): ?object
    {
        return $this->find($id);
    }

    /**
     * Manual composite-unique check for `(lang_code, slug)` — CI4's
     * `is_unique` rule can't span two columns. Used by `Admin\Translations`
     * before insert/update; the DB `UNIQUE(lang_code, slug)` key is the backstop.
     */
    public function existsForLangSlug(string $langCode, string $slug, ?int $excludeId = null, string $location = ''): bool
    {
        $builder = $this->builder()
            ->where('lang_code', $langCode)
            ->where('location', $location)
            ->where('slug', $slug);

        if ($excludeId !== null) {
            $builder->where('id !=', $excludeId);
        }

        return $builder->countAllResults() > 0;
    }

    /**
     * Admin list search (`Admin\Translations::index()`): keyword LIKE across
     * slug/content, optional exact `lang_code` filter. `id` is always a
     * secondary sort key in the same direction so ties resolve
     * deterministically across pages — mirrors `SiteVariableModel::searchAdmin()`.
     *
     * @param array{keyword?: string, lang_code?: string} $filters
     *
     * @return list<object>
     */
    public function searchAdmin(array $filters, string $sort = 'lang_code', string $order = 'asc', int $limit = 25, int $offset = 0): array
    {
        $this->applyAdminFilters($this->builder(), $filters);

        $sortColumn = in_array($sort, self::SORT_ALLOWLIST, true) ? $sort : 'lang_code';
        $sortOrder  = strtolower($order) === 'desc' ? 'DESC' : 'ASC';

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
        if ($keyword === '') {
            return;
        }

        $builder->groupStart();

        foreach (self::KEYWORD_FIELDS as $i => $field) {
            $i === 0 ? $builder->like($field, $keyword) : $builder->orLike($field, $keyword);
        }
        $builder->groupEnd();
    }

    /**
     * Distinct language codes present in the table — feeds the admin filter
     * dropdown and the export language selector. ponytail: one query, no
     * separate `languages` reference table (none exists in the live schema).
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

        return array_map(static fn (object $row): string => $row->lang_code, $rows);
    }

    /**
     * All rows for one language, ordered by slug, no pagination — feeds
     * `Admin\Translations::export()`.
     *
     * @return list<object>
     */
    public function allByLang(string $langCode): array
    {
        return $this->where('lang_code', $langCode)->orderBy('slug', 'ASC')->findAll();
    }

    /**
     * `slug => content` map for one language, ready to `json_encode()` for
     * the export download.
     *
     * @return array<string, string>
     */
    public function exportForLang(?string $langCode): array
    {
        if ($langCode === null || $langCode === '') {
            return [];
        }

        $map = [];

        foreach ($this->allByLang($langCode) as $row) {
            $map[$row->slug] = $row->content;
        }

        return $map;
    }
}
