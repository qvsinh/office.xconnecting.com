<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\ServerModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Admin server management: list/search/paginate, create/edit/delete.
 * Gated by `authFilter` + `adminFilter` at the route (see Config/Routes.php).
 * No credentials on this table (see kanban/processing/20260714-admin-servers.md §7).
 */
class Servers extends BaseController
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
            'status'  => (string) $this->request->getGet('status'),
        ];

        $serverModel = model(ServerModel::class);

        return view('admin/servers/index', [
            'items'   => $serverModel->searchAdmin($filters, $sort !== '' ? $sort : 'status', $order !== '' ? $order : 'desc', $limit, $offset),
            'total'   => $serverModel->countAdmin($filters),
            'page'    => $page,
            'limit'   => $limit,
            'sort'    => $sort,
            'order'   => $order,
            'filters' => $filters,
        ]);
    }

    public function create(): ResponseInterface
    {
        if (! $this->validate($this->serverValidationRules(false))) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        model(ServerModel::class)->insert([
            'name'   => trim((string) $this->request->getPost('name')),
            'ip'     => trim((string) $this->request->getPost('ip')),
            'status' => (int) $this->request->getPost('status'),
        ]);

        return $this->jsonSuccess('Server has been created successfully.');
    }

    /**
     * JSON server record for the edit modal (AJAX-fetched).
     */
    public function edit(int $id): ResponseInterface
    {
        $server = model(ServerModel::class)->findServer($id);

        if (! $server) {
            return $this->jsonFailed('Server not found.', 404);
        }

        return $this->jsonSuccess('OK', ['server' => [
            'id'     => (int) $server->id,
            'name'   => $server->name,
            'ip'     => $server->ip,
            'status' => (int) $server->status,
        ]]);
    }

    public function update(): ResponseInterface
    {
        if (! $this->validate($this->serverValidationRules(true))) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $id          = (int) $this->request->getPost('id');
        $serverModel = model(ServerModel::class);

        if (! $serverModel->findServer($id)) {
            return $this->jsonFailed('Server not found.', 404);
        }

        $serverModel->update($id, [
            'name'   => trim((string) $this->request->getPost('name')),
            'ip'     => trim((string) $this->request->getPost('ip')),
            'status' => (int) $this->request->getPost('status'),
        ]);

        return $this->jsonSuccess('Server has been updated successfully.');
    }

    public function delete(): ResponseInterface
    {
        if (! $this->validate(['id' => 'required|is_natural_no_zero'])) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $id          = (int) $this->request->getPost('id');
        $serverModel = model(ServerModel::class);

        if (! $serverModel->findServer($id)) {
            return $this->jsonFailed('Server not found.', 404);
        }

        $serverModel->delete($id);

        return $this->jsonSuccess('Server has been deleted.');
    }

    /**
     * @return array<string, string>
     */
    private function serverValidationRules(bool $isUpdate): array
    {
        $rules = [
            'name' => 'required|max_length[255]',
            // Free-form host/IP (matches the legacy VARCHAR field, which may
            // hold hostnames) -> max_length, not valid_ip.
            'ip'     => 'required|max_length[255]',
            'status' => 'permit_empty|in_list[0,1]',
        ];

        if ($isUpdate) {
            $rules['id'] = 'required|is_natural_no_zero';
        }

        return $rules;
    }
}
