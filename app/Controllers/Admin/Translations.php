<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\TranslationModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Admin UI translation strings (`translation` table, keyed by
 * `(lang_code, slug)`): list/search/paginate, create/edit/delete, plus a
 * JSON export per language. Gated by `authFilter` + `adminFilter` at the
 * route (see Config/Routes.php). Mirrors `Admin\Settings`.
 *
 * `(lang_code, slug)` is a composite unique key — CI4's `is_unique` rule
 * can't span two columns, so `create()`/`update()` check
 * `TranslationModel::existsForLangSlug()` manually before writing (scoped to
 * lang + location + slug, matching the table's unique key); the DB
 * `UNIQUE(lang_code, slug)` key is the backstop against a race.
 */
class Translations extends BaseController
{
    public function index(): string
    {
        $page    = max(1, (int) $this->request->getGet('page'));
        $limit   = $this->resolveLimit();
        $offset  = ($page - 1) * $limit;
        $sort    = (string) $this->request->getGet('sort');
        $order   = (string) $this->request->getGet('order');
        $filters = [
            'keyword'   => trim((string) $this->request->getGet('keyword')),
            'lang_code' => trim((string) $this->request->getGet('lang_code')),
        ];

        $translationModel = model(TranslationModel::class);

        return view('admin/translations/index', [
            'items'     => $translationModel->searchAdmin($filters, $sort !== '' ? $sort : 'lang_code', $order !== '' ? $order : 'asc', $limit, $offset),
            'total'     => $translationModel->countAdmin($filters),
            'page'      => $page,
            'limit'     => $limit,
            'sort'      => $sort,
            'order'     => $order,
            'filters'   => $filters,
            'langCodes' => $translationModel->distinctLangCodes(),
        ]);
    }

    public function create(): ResponseInterface
    {
        if (! $this->validate($this->translationValidationRules())) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $langCode = trim((string) $this->request->getPost('lang_code'));
        $slug     = trim((string) $this->request->getPost('slug'));
        $location = trim((string) $this->request->getPost('location'));

        $translationModel = model(TranslationModel::class);
        if ($translationModel->existsForLangSlug($langCode, $slug, null, $location)) {
            return $this->jsonFailed('A translation for this language and slug already exists.');
        }

        $translationModel->insert([
            'lang_code' => $langCode,
            'location'  => $location,
            'slug'      => $slug,
            'content'   => (string) $this->request->getPost('content'),
        ]);

        return $this->jsonSuccess('Translation has been created successfully.');
    }

    /**
     * JSON translation record for the edit modal (AJAX-fetched).
     */
    public function edit(int $id): ResponseInterface
    {
        $translation = model(TranslationModel::class)->findTranslation($id);

        if (! $translation) {
            return $this->jsonFailed('Translation not found.', 404);
        }

        return $this->jsonSuccess('OK', ['translation' => [
            'id'        => (int) $translation->id,
            'lang_code' => $translation->lang_code,
            'location'  => $translation->location,
            'slug'      => $translation->slug,
            'content'   => $translation->content,
        ]]);
    }

    public function update(): ResponseInterface
    {
        $rules       = $this->translationValidationRules();
        $rules['id'] = 'required|is_natural_no_zero';

        if (! $this->validate($rules)) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $id               = (int) $this->request->getPost('id');
        $translationModel = model(TranslationModel::class);

        if (! $translationModel->findTranslation($id)) {
            return $this->jsonFailed('Translation not found.', 404);
        }

        $langCode = trim((string) $this->request->getPost('lang_code'));
        $slug     = trim((string) $this->request->getPost('slug'));
        $location = trim((string) $this->request->getPost('location'));

        if ($translationModel->existsForLangSlug($langCode, $slug, $id, $location)) {
            return $this->jsonFailed('A translation for this language and slug already exists.');
        }

        $translationModel->update($id, [
            'lang_code' => $langCode,
            'location'  => $location,
            'slug'      => $slug,
            'content'   => (string) $this->request->getPost('content'),
        ]);

        return $this->jsonSuccess('Translation has been updated successfully.');
    }

    public function delete(): ResponseInterface
    {
        if (! $this->validate(['id' => 'required|is_natural_no_zero'])) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $id               = (int) $this->request->getPost('id');
        $translationModel = model(TranslationModel::class);

        if (! $translationModel->findTranslation($id)) {
            return $this->jsonFailed('Translation not found.', 404);
        }

        $translationModel->delete($id); // hard delete: no soft-delete column on `translation`

        return $this->jsonSuccess('Translation has been deleted.');
    }

    /**
     * Streams all strings for one language as a downloadable JSON file
     * (`slug => content`). JSON only — replaces the legacy
     * `export_to_files()`, which `echo`'d a raw PHP array literal built with
     * naive quote-escaping (an injection/eval hazard on import).
     *
     * Headers are set directly on `$this->response` (not via
     * `Response::download()`) so they're assertable in feature tests, which
     * don't invoke `DownloadResponse::send()`.
     */
    public function export(): ResponseInterface
    {
        if (! $this->validate(['lang_code' => 'required|max_length[5]|alpha_dash'])) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $langCode = trim((string) $this->request->getGet('lang_code'));
        $map      = model(TranslationModel::class)->exportForLang($langCode);

        return $this->response
            ->setContentType('application/json')
            ->setHeader('Content-Disposition', sprintf('attachment; filename="translations-%s.json"', $langCode))
            ->setBody(json_encode($map, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, string>
     */
    private function translationValidationRules(): array
    {
        return [
            'lang_code' => 'required|max_length[5]',
            'slug'      => 'required|max_length[255]',
            'content'   => 'required',
            'location'  => 'permit_empty|max_length[255]',
        ];
    }
}
