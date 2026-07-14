<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\SiteVariableModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Admin site settings (`site_variable` key/value config): list/search/paginate,
 * create/edit/delete. Gated by `authFilter` + `adminFilter` at the route (see
 * Config/Routes.php). Mirrors `Admin\Servers` exactly.
 */
class Settings extends BaseController
{
    public function index(): string
    {
        $page    = max(1, (int) $this->request->getGet('page'));
        $limit   = $this->resolveLimit();
        $offset  = ($page - 1) * $limit;
        $sort    = (string) $this->request->getGet('sort');
        $order   = (string) $this->request->getGet('order');
        $filters = [
            'keyword' => trim((string) $this->request->getGet('keyword')),
            'type'    => (string) $this->request->getGet('type'),
        ];

        $siteVariableModel = model(SiteVariableModel::class);

        return view('admin/settings/index', [
            'items'   => $siteVariableModel->searchAdmin($filters, $sort !== '' ? $sort : 'name', $order !== '' ? $order : 'asc', $limit, $offset),
            'total'   => $siteVariableModel->countAdmin($filters),
            'page'    => $page,
            'limit'   => $limit,
            'sort'    => $sort,
            'order'   => $order,
            'filters' => $filters,
        ]);
    }

    public function create(): ResponseInterface
    {
        if (! $this->validate($this->settingValidationRules(false))) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        model(SiteVariableModel::class)->insert([
            'name'        => trim((string) $this->request->getPost('name')),
            'value'       => trim((string) $this->request->getPost('value')),
            'description' => trim((string) $this->request->getPost('description')),
        ]);

        return $this->jsonSuccess('Setting has been created successfully.');
    }

    /**
     * JSON site variable record for the edit modal (AJAX-fetched).
     */
    public function edit(int $id): ResponseInterface
    {
        $variable = model(SiteVariableModel::class)->findVariable($id);

        if (! $variable) {
            return $this->jsonFailed('Setting not found.', 404);
        }

        return $this->jsonSuccess('OK', ['setting' => [
            'id'          => (int) $variable->id,
            'name'        => $variable->name,
            'value'       => $variable->value,
            'type'        => $variable->type,
            'description' => $variable->description,
        ]]);
    }

    public function update(): ResponseInterface
    {
        if (! $this->validate($this->settingValidationRules(true))) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $id                = (int) $this->request->getPost('id');
        $siteVariableModel = model(SiteVariableModel::class);

        if (! $siteVariableModel->findVariable($id)) {
            return $this->jsonFailed('Setting not found.', 404);
        }

        $siteVariableModel->update($id, [
            'name'        => trim((string) $this->request->getPost('name')),
            'value'       => trim((string) $this->request->getPost('value')),
            'description' => trim((string) $this->request->getPost('description')),
        ]);

        return $this->jsonSuccess('Setting has been updated successfully.');
    }

    public function delete(): ResponseInterface
    {
        if (! $this->validate(['id' => 'required|is_natural_no_zero'])) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $id                = (int) $this->request->getPost('id');
        $siteVariableModel = model(SiteVariableModel::class);

        if (! $siteVariableModel->findVariable($id)) {
            return $this->jsonFailed('Setting not found.', 404);
        }

        $siteVariableModel->delete($id);

        return $this->jsonSuccess('Setting has been deleted.');
    }

    /**
     * @return array<string, string>
     */
    private function settingValidationRules(bool $isUpdate): array
    {
        $uniqueRule = $isUpdate
            ? 'is_unique[site_variable.name,id,{id}]'
            : 'is_unique[site_variable.name]';

        $rules = [
            'name'        => 'required|max_length[100]|' . $uniqueRule,
            'value'       => 'permit_empty|max_length[255]',
            'description' => 'permit_empty',
        ];

        if ($isUpdate) {
            $rules['id'] = 'required|is_natural_no_zero';
        }

        return $rules;
    }
}
