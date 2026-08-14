<?php

namespace App\Libraries;

use App\Models\LeaveRequestModel;
use App\Models\UserModel;
use Throwable;

/**
 * Emails the office about a leave / late-arrival request.
 *
 * Three moments, because those are the three a person is waiting on:
 *
 *   submitted   -> the administrator, so a request does not sit unseen until
 *                  somebody happens to open `/admin/requests`
 *   withdrawn   -> the administrator, so a request they were asked to approve
 *                  stops being their problem the moment it stops existing
 *   decided     -> the employee, so they hear before the date arrives
 *
 * The employee's in-app notification on a decision is *kept* alongside the mail
 * (`Admin\Requests::notifyEmployee`): the site tells them when they are here, the
 * mail reaches them when they are not.
 *
 * One switch, `mail_notify_requests`, covers all three — an office that finds
 * this noisy wants silence in both directions, not half of it. The address is the
 * shared `mail_notify_admin_recipient`; there is no second copy of "where does
 * notification mail go".
 *
 * ## Never breaks the request
 *
 * Nothing here throws into the caller. An employee submitting leave at 17:59 must
 * not see an error because SES was slow, and an approval must be recorded even if
 * the mail about it fails — the decision is the fact, the mail is the courtesy.
 */
class RequestNotifier extends MailNotifier
{
    /**
     * Master switch for request mail, both directions.
     */
    public const SETTING_ENABLED = 'mail_notify_requests';

    public const EVENT_SUBMITTED = 'submitted';
    public const EVENT_WITHDRAWN = 'withdrawn';

    public function enabled(): bool
    {
        return $this->switchedOn(self::SETTING_ENABLED);
    }

    /**
     * A new request is waiting for a decision.
     */
    public function submitted(object $row): void
    {
        $this->notifyAdmin($row, self::EVENT_SUBMITTED);
    }

    /**
     * The employee withdrew it before anybody decided.
     */
    public function withdrawn(object $row): void
    {
        $this->notifyAdmin($row, self::EVENT_WITHDRAWN);
    }

    /**
     * A manager approved or rejected it — tell the person who asked.
     *
     * @param string $status LeaveRequestModel::STATUS_APPROVED | STATUS_REJECTED
     */
    public function decided(object $row, string $status, ?string $note = null): void
    {
        try {
            if (! $this->enabled()) {
                return;
            }

            if (! in_array($status, [LeaveRequestModel::STATUS_APPROVED, LeaveRequestModel::STATUS_REJECTED], true)) {
                return;
            }

            $facts = $this->facts($row);

            if ($facts === null || $facts['email'] === '') {
                return;
            }

            $facts['status'] = $status;
            $facts['note']   = $note;

            $this->dispatch(
                'employee',
                $facts['email'],
                sprintf('%s — %s %s', site_name(), $facts['what'], $status),
                'emails/request_decided',
                $facts,
                'requests.' . $status,
            );
        } catch (Throwable $e) {
            log_message('error', 'Request decision notification failed: {msg}', ['msg' => $e->getMessage()]);
        }
    }

    private function notifyAdmin(object $row, string $event): void
    {
        try {
            if (! $this->enabled()) {
                return;
            }

            $facts = $this->facts($row);

            if ($facts === null) {
                return;
            }

            $facts['event'] = $event;
            // A live one-click link only for the message that asks for a
            // decision. A withdrawal notice needs no buttons, and minting a
            // token there would leave a working approval link in an inbox for a
            // request that no longer wants one.
            $facts['decisionUrl'] = $event === self::EVENT_SUBMITTED
                ? site_url('request-decision/' . model(LeaveRequestModel::class)->mintDecisionToken((int) $row->id))
                : '';

            // The verb, not the event key: "Walker withdrawn annual leave" is not a
            // sentence, and the subject line is the only part most people read.
            $verb = $event === self::EVENT_SUBMITTED ? 'requested' : 'withdrew';

            // One token, shared by every administrator's copy: whoever acts first
            // decides, and the link dies with the decision. Minting per recipient
            // would leave all but the last one broken.
            $this->dispatchToAdmins(
                sprintf('[%s] %s %s %s', site_name(), $facts['employee'], $verb, strtolower($facts['what'])),
                'emails/request_admin',
                $facts,
                'requests.' . $event,
            );
        } catch (Throwable $e) {
            log_message('error', 'Request {event} notification failed: {msg}', [
                'event' => $event,
                'msg'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Everything both templates need, read once.
     *
     * The employee is looked up from the row rather than the session, so this
     * works the same from a controller, a command or a scheduled task.
     *
     * @return array<string, mixed>|null null when the requester no longer exists
     */
    private function facts(object $row): ?array
    {
        helper('attendance');

        $user = model(UserModel::class)->findUser((int) $row->user_id);

        if ($user === null) {
            return null;
        }

        $name = trim((string) ($user->name ?? '')) ?: (string) ($user->email ?? 'Unknown employee');

        return [
            'employee' => $name,
            'name'     => $name,
            'email'    => (string) ($user->email ?? ''),
            // "Annual leave", "Late arrival (09:30)" — the same wording the
            // request tables show, so a mail and the screen never disagree.
            'what'      => leave_request_label($row),
            'dates'     => leave_request_dates($row),
            'reason'    => trim((string) ($row->reason ?? '')),
            'submitted' => (string) ($row->created_at ?? ''),
            'status'    => (string) ($row->status ?? ''),
            'note'      => null,
            'event'     => '',
        ];
    }
}
