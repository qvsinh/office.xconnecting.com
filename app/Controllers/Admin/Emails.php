<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\EmailModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Admin sent-email log (`emails` table) — **read-only**. Gated by
 * `authFilter` + `adminFilter` at the route (see Config/Routes.php). No
 * create/update/delete: the send pipeline that populates this table doesn't
 * exist yet (out of scope, see kanban/processing/20260714-admin-emails.md §7).
 *
 * `id` is a VARCHAR token (not an int) — routes use `(:segment)`, not `(:num)`.
 */
class Emails extends BaseController
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

        $emailModel = model(EmailModel::class);

        return view('admin/emails/index', [
            'items'   => $emailModel->searchAdmin($filters, $sort !== '' ? $sort : 'created_at', $order !== '' ? $order : 'desc', $limit, $offset),
            'total'   => $emailModel->countAdmin($filters),
            'page'    => $page,
            'limit'   => $limit,
            'sort'    => $sort,
            'order'   => $order,
            'filters' => $filters,
        ]);
    }

    /**
     * JSON record for the sent-email detail modal (AJAX-fetched). Returns raw
     * fields — the modal markup itself is `frontend-coder`'s territory.
     */
    public function show(string $id): ResponseInterface
    {
        $email = model(EmailModel::class)->findEmail($id);

        if (! $email) {
            return $this->jsonFailed('Email not found.', 404);
        }

        return $this->jsonSuccess('OK', ['email' => [
            'id'            => $email->id,
            'subject'       => $email->subject,
            'content'       => $email->content,
            'from_email'    => $email->from_email,
            'to_email'      => $email->to_email,
            'status'        => (int) $email->status,
            'name'          => $email->name,
            'error_message' => $email->error_message,
            'created_at'    => $email->created_at,
        ]]);
    }

    private function resolveLimit(): int
    {
        $limitGet = (int) $this->request->getGet('limit');

        if ($limitGet > 0) {
            return min($limitGet, 100); // clamp: unbounded limit would let one request pull the whole table (OOM/DoS)
        }

        $default = (int) getenv('app.rpp_default');

        return $default > 0 ? min($default, 100) : 25;
    }
}
