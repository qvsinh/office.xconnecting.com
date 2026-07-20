<?php

namespace App\Controllers\Account;

use App\Controllers\BaseController;
use App\Models\ApiKeyModel;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Personal API access keys for the REST API (see
 * `kanban/processing/20260721-06-api-keys.md`). Every read/mutation is
 * scoped to `current_user_id()` — never a request-supplied id — mirroring
 * `Account`'s "own row only" convention. The plaintext token is shown once,
 * at `create()`, and is never stored or logged again
 * (`ApiKeyModel::createForUser()`).
 */
class ApiKeys extends BaseController
{
    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        helper('auth');
    }

    public function index(): string
    {
        return view('account/api_keys', [
            'keys' => model(ApiKeyModel::class)->forUser(current_user_id()),
        ]);
    }

    /**
     * Creates a key and returns the plaintext token exactly once, alongside
     * the (hash-free) row for the list UI.
     */
    public function create(): ResponseInterface
    {
        $rules = [
            'name'         => 'required|max_length[100]',
            'scopes'       => 'permit_empty|in_list[read,write]',
            'expires_days' => 'permit_empty|is_natural',
        ];

        if (! $this->validate($rules)) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $scopes = (string) $this->request->getPost('scopes');
        $scopes = $scopes !== '' ? $scopes : 'read';

        $expiresDays = (int) $this->request->getPost('expires_days');
        $expiresAt   = $expiresDays > 0 ? date('Y-m-d H:i:s', strtotime('+' . $expiresDays . ' days')) : null;

        $result = model(ApiKeyModel::class)->createForUser(
            current_user_id(),
            trim((string) $this->request->getPost('name')),
            $scopes,
            $expiresAt,
        );

        return $this->jsonSuccess('API key created.', [
            'token' => $result['plain'],
            'key'   => $result['row'],
        ]);
    }

    /**
     * Revokes a key, scoped to its owner via `ApiKeyModel::revoke()` — a
     * false return means the key doesn't exist or belongs to someone else,
     * so it's reported as a plain 404 (no existence leak, no IDOR).
     */
    public function revoke(): ResponseInterface
    {
        if (! $this->validate(['id' => 'required|is_natural_no_zero'])) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $id = (int) $this->request->getPost('id');

        if (! model(ApiKeyModel::class)->revoke($id, current_user_id())) {
            return $this->jsonFailed('Key not found.', 404);
        }

        return $this->jsonSuccess('API key revoked.');
    }
}
