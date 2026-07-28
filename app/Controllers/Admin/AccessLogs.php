<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\AccessLogModel;
use App\Models\UserModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Access log: login / check-in / check-out with the network and device context
 * each event came from.
 *
 * Gated by `authFilter` + `adminFilter` — deliberately **not** the
 * `attendance.manage` permission. A department head needs to correct their
 * team's hours; that is a different thing from reading everyone's IP addresses
 * and devices, which is personal data and stays with true administrators.
 *
 * Read-only: the app never edits or deletes a row here except the
 * `access_logs.purge` scheduled job. An audit trail somebody can edit is not one.
 */
class AccessLogs extends BaseController
{
    public function index(): string
    {
        $page    = max(1, (int) $this->request->getGet('page'));
        $limit   = $this->resolveLimit();
        $offset  = ($page - 1) * $limit;
        $sort    = (string) $this->request->getGet('sort');
        $order   = (string) $this->request->getGet('order');
        $filters = [
            'user_id'   => (int) $this->request->getGet('user_id'),
            'event'     => (string) $this->request->getGet('event'),
            'date_from' => trim((string) $this->request->getGet('date_from')),
            'date_to'   => trim((string) $this->request->getGet('date_to')),
            'keyword'   => trim((string) $this->request->getGet('keyword')),
        ];

        $model = model(AccessLogModel::class);

        return view('admin/access-logs/index', [
            'items'     => $model->searchAdmin($filters, $sort !== '' ? $sort : 'created_at', $order !== '' ? $order : 'desc', $limit, $offset),
            'total'     => $model->countAdmin($filters),
            'page'      => $page,
            'limit'     => $limit,
            'sort'      => $sort,
            'order'     => $order,
            'filters'   => $filters,
            'employees' => model(UserModel::class)->activeRoster(),
        ]);
    }

    /**
     * One employee's access history: the devices/addresses they have been seen
     * from, then the raw event list.
     *
     * This is the view that answers "is somebody else using this account?", so
     * it leads with distinct devices rather than a flat chronological dump.
     */
    public function user(int $id): string
    {
        $employee = model(UserModel::class)->findUser($id);

        if ($employee === null) {
            throw PageNotFoundException::forPageNotFound('Employee not found.');
        }

        $model = model(AccessLogModel::class);

        return view('admin/access-logs/user', [
            'employee' => $employee,
            'devices'  => $model->devicesForUser($id),
            'items'    => $model->searchAdmin(['user_id' => $id], 'created_at', 'desc', 50),
            'total'    => $model->countAdmin(['user_id' => $id]),
        ]);
    }

    /**
     * The full row, including the raw user-agent header, for the detail modal.
     */
    public function show(int $id): ResponseInterface
    {
        $row = model(AccessLogModel::class)->findLog($id);

        if ($row === null) {
            return $this->jsonFailed('Access log entry not found.', 404);
        }

        return $this->jsonSuccess('OK', [
            'entry' => [
                'id'          => (int) $row->id,
                'event'       => $row->event,
                'created_at'  => $row->created_at,
                'ip'          => $row->ip,
                'browser'     => $row->browser,
                'platform'    => $row->platform,
                'device_type' => $row->device_type,
                'screen'      => $row->screen_width === null ? null : $row->screen_width . ' × ' . $row->screen_height,
                'user_agent'  => $row->user_agent,
            ],
        ]);
    }
}
