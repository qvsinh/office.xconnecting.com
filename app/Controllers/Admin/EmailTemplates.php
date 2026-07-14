<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\EmailTemplateModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Admin email templates (`email_templates` table, keyed by
 * `(name, lang_code)`): list/search/paginate, create/edit/delete (soft).
 * Gated by `authFilter` + `adminFilter` at the route (see Config/Routes.php).
 * Mirrors `Admin\Translations`.
 *
 * `(name, lang_code)` is a composite unique key — CI4's `is_unique` rule
 * can't span two columns, so `create()`/`update()` check
 * `EmailTemplateModel::existsForNameLang()` manually before writing; the DB
 * `UNIQUE(name, lang_code)` key (`lang_UN`) is the backstop against a race.
 *
 * `content` is trusted admin-authored HTML, stored verbatim (no escaping on
 * store — escaping is a per-context view concern). It is only ever data:
 * never written to disk, never `eval()`'d, and only rendered inside an
 * isolated preview surface by the frontend (never interpolated into the
 * admin chrome's DOM). The legacy `Email_templates::update()` wrote this
 * same content into an executable `.php` view file — an RCE vector that is
 * deliberately not ported here.
 */
class EmailTemplates extends BaseController
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

        $emailTemplateModel = model(EmailTemplateModel::class);

        return view('admin/email_templates/index', [
            'items'     => $emailTemplateModel->searchAdmin($filters, $sort !== '' ? $sort : 'id', $order !== '' ? $order : 'desc', $limit, $offset),
            'total'     => $emailTemplateModel->countAdmin($filters),
            'page'      => $page,
            'limit'     => $limit,
            'sort'      => $sort,
            'order'     => $order,
            'filters'   => $filters,
            'langCodes' => $emailTemplateModel->distinctLangCodes(),
        ]);
    }

    public function create(): ResponseInterface
    {
        if (! $this->validate($this->templateValidationRules())) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $name     = trim((string) $this->request->getPost('name'));
        $langCode = trim((string) $this->request->getPost('lang_code'));

        $emailTemplateModel = model(EmailTemplateModel::class);
        if ($emailTemplateModel->existsForNameLang($name, $langCode)) {
            return $this->jsonFailed('A template for this name and language already exists.');
        }

        $emailTemplateModel->insert([
            'name'      => $name,
            'lang_code' => $langCode,
            'subject'   => trim((string) $this->request->getPost('subject')),
            'content'   => (string) $this->request->getPost('content'),
            'active'    => $this->activeFromPost(),
        ]);

        return $this->jsonSuccess('Email template has been created successfully.');
    }

    /**
     * JSON template record for the edit modal (AJAX-fetched).
     */
    public function edit(int $id): ResponseInterface
    {
        $template = model(EmailTemplateModel::class)->findTemplate($id);

        if (! $template) {
            return $this->jsonFailed('Email template not found.', 404);
        }

        return $this->jsonSuccess('OK', ['template' => [
            'id'        => (int) $template->id,
            'name'      => $template->name,
            'lang_code' => $template->lang_code,
            'subject'   => $template->subject,
            'content'   => $template->content,
            'active'    => (int) $template->active,
        ]]);
    }

    public function update(): ResponseInterface
    {
        $rules       = $this->templateValidationRules();
        $rules['id'] = 'required|is_natural_no_zero';

        if (! $this->validate($rules)) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $id                 = (int) $this->request->getPost('id');
        $emailTemplateModel = model(EmailTemplateModel::class);

        if (! $emailTemplateModel->findTemplate($id)) {
            return $this->jsonFailed('Email template not found.', 404);
        }

        $name     = trim((string) $this->request->getPost('name'));
        $langCode = trim((string) $this->request->getPost('lang_code'));

        if ($emailTemplateModel->existsForNameLang($name, $langCode, $id)) {
            return $this->jsonFailed('A template for this name and language already exists.');
        }

        $emailTemplateModel->update($id, [
            'name'      => $name,
            'lang_code' => $langCode,
            'subject'   => trim((string) $this->request->getPost('subject')),
            'content'   => (string) $this->request->getPost('content'),
            'active'    => $this->activeFromPost(),
        ]);

        return $this->jsonSuccess('Email template has been updated successfully.');
    }

    public function delete(): ResponseInterface
    {
        if (! $this->validate(['id' => 'required|is_natural_no_zero'])) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $id                 = (int) $this->request->getPost('id');
        $emailTemplateModel = model(EmailTemplateModel::class);

        if (! $emailTemplateModel->findTemplate($id)) {
            return $this->jsonFailed('Email template not found.', 404);
        }

        $emailTemplateModel->delete($id);

        return $this->jsonSuccess('Email template has been deleted.');
    }

    /**
     * @return array<string, string>
     */
    private function templateValidationRules(): array
    {
        return [
            // Permissive max_length[5] rather than a hardcoded in_list — the
            // legacy app only ships `en`/`ja` view dirs, but there's no
            // supported-language config to source an allowlist from yet
            // (kanban plan §7 open question). Tightening this later is a
            // one-line change once that config exists.
            'name'      => 'required|max_length[255]',
            'lang_code' => 'required|max_length[5]',
            'subject'   => 'required|max_length[255]',
            'content'   => 'permit_empty',
            'active'    => 'permit_empty|in_list[0,1]',
        ];
    }

    private function activeFromPost(): int
    {
        $active = $this->request->getPost('active');

        return $active === null || $active === '' ? 1 : (int) $active;
    }
}
