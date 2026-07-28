<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\LeaveBalance;
use App\Models\LeaveRequestModel;
use App\Models\NotificationModel;
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
     * One decision path for both outcomes, so the lock check, the concurrency
     * guard and the employee notification can't diverge between them.
     */
    private function review(int $id, string $status): ResponseInterface
    {
        helper('attendance');

        $model = model(LeaveRequestModel::class);
        $row   = $model->findRequest($id);

        if ($row === null) {
            return $this->jsonFailed('Request not found.', 404);
        }

        if ($row->status !== LeaveRequestModel::STATUS_PENDING) {
            return $this->jsonFailed('That request has already been ' . $row->status . '.', 409);
        }

        $endDate = (string) ($row->end_date ?? $row->start_date);

        if (attendance_period_locked((string) $row->start_date) || attendance_period_locked($endDate)) {
            return $this->jsonFailed('That period is closed for payroll (locked through ' . attendance_locked_through() . ').', 409);
        }

        $note = trim((string) $this->request->getPost('review_note')) ?: null;

        // The pending check lives inside the UPDATE's WHERE clause, so a second
        // manager clicking at the same moment loses cleanly rather than
        // overwriting the first decision.
        if (! $model->review($id, $status, current_user_id(), $note)) {
            return $this->jsonFailed('That request was just reviewed by someone else.', 409);
        }

        $this->notifyEmployee($row, $status, $note);

        return $this->jsonSuccess('Request has been ' . $status . '.');
    }

    /**
     * Tell the employee. A decision they never hear about is barely a decision —
     * and they need to know before the date arrives.
     */
    private function notifyEmployee(object $row, string $status, ?string $note): void
    {
        $what = $row->type === LeaveRequestModel::TYPE_LEAVE ? 'Leave request' : 'Late-arrival request';
        $when = (string) $row->start_date;

        if (($row->end_date ?? null) !== null && (string) $row->end_date !== $when) {
            $when .= ' to ' . $row->end_date;
        }

        $message = "{$what} for {$when} was {$status}.";

        if ($note !== null) {
            $message .= ' Note: ' . $note;
        }

        model(NotificationModel::class)->insert([
            'user_id' => (int) $row->user_id,
            'message' => $message,
        ]);
    }
}
