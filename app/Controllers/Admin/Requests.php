<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\LeaveBalance;
use App\Libraries\RequestReview;
use App\Models\LeaveRequestModel;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Leave / late-arrival approval queue (see
 * kanban/processing/20260722-02-leave-late-requests.md).
 *
 * Gated by `authFilter` + `permission:requests.approve`, so a department head can
 * approve without holding global admin rights.
 *
 * Approving is what makes the day read `on_leave` instead of `absent` on the
 * attendance sheet — `AttendanceCalendar` consumes approved rows directly, so
 * there is nothing to recompute here.
 */
class Requests extends BaseController
{
    public function index(): string
    {
        helper('attendance');

        $page    = max(1, (int) $this->request->getGet('page'));
        $limit   = $this->resolveLimit();
        $offset  = ($page - 1) * $limit;
        $filters = [
            'status'     => (string) $this->request->getGet('status'),
            'type'       => (string) $this->request->getGet('type'),
            'leave_kind' => (string) $this->request->getGet('leave_kind'),
            'user_id'    => (int) $this->request->getGet('user_id'),
        ];

        $model = model(LeaveRequestModel::class);
        $items = $model->searchAdmin($filters, 'start_date', 'desc', $limit, $offset);

        // The approver needs the cost and the remaining balance in view *before*
        // deciding — approving blind is how a quota gets silently overdrawn.
        $balance     = new LeaveBalance();
        $requestDays = [];
        $balances    = [];

        foreach ($items as $item) {
            $requestDays[(int) $item->id] = $balance->requestDays($item);
            $userId                       = (int) $item->user_id;

            $balances[$userId] ??= $balance->summary($userId);
        }

        return view('admin/requests/index', [
            'items'         => $items,
            'requestDays'   => $requestDays,
            'balances'      => $balances,
            'total'         => $model->countAdmin($filters),
            'pending'       => $model->countPending(),
            'page'          => $page,
            'limit'         => $limit,
            'filters'       => $filters,
            'employees'     => model(UserModel::class)->activeRoster(),
            'today'         => attendance_today(),
            'lockedThrough' => attendance_locked_through(),
        ]);
    }

    public function approve(int $id): ResponseInterface
    {
        return $this->review($id, LeaveRequestModel::STATUS_APPROVED);
    }

    public function reject(int $id): ResponseInterface
    {
        return $this->review($id, LeaveRequestModel::STATUS_REJECTED);
    }

    /**
     * One decision path for both outcomes, and the *same* path the emailed
     * one-click link takes — see `App\Libraries\RequestReview`, which owns the
     * lock check, the concurrency guard and telling the employee.
     */
    private function review(int $id, string $status): ResponseInterface
    {
        $row = model(LeaveRequestModel::class)->findRequest($id);

        if ($row === null) {
            return $this->jsonFailed('Request not found.', 404);
        }

        $note  = trim((string) $this->request->getPost('review_note')) ?: null;
        $error = (new RequestReview())->apply($row, $status, current_user_id(), $note);

        if ($error !== null) {
            return $this->jsonFailed($error, 409);
        }

        return $this->jsonSuccess('Request has been ' . $status . '.');
    }
}
