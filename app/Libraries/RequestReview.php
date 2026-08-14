<?php

namespace App\Libraries;

use App\Models\LeaveRequestModel;
use App\Models\NotificationModel;

/**
 * Deciding a leave / late-arrival request — the whole decision, in one place.
 *
 * There are now two doors into it: the approval queue in the admin UI, and the
 * one-click link in the administrator's email. Every rule below has to hold at
 * both, and a copy in each controller would drift the first time one of them
 * changed:
 *
 *  - a decided request cannot be decided again (the guard is inside the UPDATE's
 *    WHERE clause, so a second manager clicking at the same moment loses cleanly
 *    rather than overwriting the first decision);
 *  - a period closed for payroll refuses the decision outright;
 *  - the employee is told, in the app *and* by email.
 *
 * `$reviewerId` is nullable because an emailed link has no session: when the
 * administrator's address matches an account we attribute the decision to them,
 * and when it does not we would rather record "decided, reviewer unknown" than
 * invent an approver.
 */
class RequestReview
{
    /**
     * Apply a decision.
     *
     * @param string $status LeaveRequestModel::STATUS_APPROVED | STATUS_REJECTED
     *
     * @return string|null null on success, otherwise the reason to show the caller
     */
    public function apply(object $row, string $status, ?int $reviewerId, ?string $note): ?string
    {
        helper('attendance');

        if (! in_array($status, [LeaveRequestModel::STATUS_APPROVED, LeaveRequestModel::STATUS_REJECTED], true)) {
            return 'That is not a decision.';
        }

        if ($row->status !== LeaveRequestModel::STATUS_PENDING) {
            return 'That request has already been ' . $row->status . '.';
        }

        $endDate = (string) ($row->end_date ?? $row->start_date);

        if (attendance_period_locked((string) $row->start_date) || attendance_period_locked($endDate)) {
            return 'That period is closed for payroll (locked through ' . attendance_locked_through() . ').';
        }

        if (! model(LeaveRequestModel::class)->review((int) $row->id, $status, $reviewerId, $note)) {
            return 'That request was just reviewed by someone else.';
        }

        $this->tellTheEmployee($row, $status, $note);

        return null;
    }

    /**
     * Both channels, deliberately: the bell reaches them while they are on the
     * site, the mail reaches them when they are not — and a decision they hear
     * about late is barely better than one they never hear about.
     */
    private function tellTheEmployee(object $row, string $status, ?string $note): void
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

        (new RequestNotifier())->decided($row, $status, $note);
    }
}
