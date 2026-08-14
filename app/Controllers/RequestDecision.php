<?php

namespace App\Controllers;

use App\Libraries\RequestNotifier;
use App\Libraries\RequestReview;
use App\Models\LeaveRequestModel;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Approve or reject straight from the administrator's email.
 *
 * Signed out, off the office network, on a phone at the weekend: the link has to
 * work, so **the token in the URL is the credential**. That shapes everything
 * here:
 *
 *  - no `authFilter`, and the site's basic-auth gate skips this path (there is
 *    nobody to hand a second password to);
 *  - the token is a one-off, minted per request, stored only as a sha256 hash,
 *    and it dies the moment the request stops being pending;
 *  - **opening the link decides nothing.** A GET shows the request and asks; the
 *    decision needs the button on that page (POST + CSRF). Mail clients and
 *    security scanners follow links on their own — a GET that approved leave
 *    would be approved by a link preview, and the administrator would never know
 *    they had "clicked".
 *
 * `action` comes from the URL only to preselect which button the page leads
 * with; the confirm form posts it back and the decision is applied from that.
 */
class RequestDecision extends BaseController
{
    private const ACTIONS = [
        'approve' => LeaveRequestModel::STATUS_APPROVED,
        'reject'  => LeaveRequestModel::STATUS_REJECTED,
    ];

    /**
     * The confirmation page the emailed button opens.
     */
    public function show(string $token, string $action = 'approve'): ResponseInterface|string
    {
        $row = model(LeaveRequestModel::class)->findByDecisionToken($token);

        if ($row === null) {
            return $this->expired();
        }

        return $this->page($row, $token, $this->normaliseAction($action));
    }

    /**
     * The button on that page. Same validation again — the page being open
     * proves nothing about the token still being good when it is submitted.
     */
    public function confirm(string $token): ResponseInterface|string
    {
        $model = model(LeaveRequestModel::class);
        $row   = $model->findByDecisionToken($token);

        if ($row === null) {
            return $this->expired();
        }

        $action = $this->normaliseAction((string) $this->request->getPost('action'));
        $note   = trim((string) $this->request->getPost('review_note')) ?: null;

        $error = (new RequestReview())->apply($row, self::ACTIONS[$action], $this->reviewerId(), $note);

        if ($error !== null) {
            return $this->page($model->findRequest((int) $row->id) ?? $row, $token, $action, $error);
        }

        return view('requests/decision_done', [
            'row'    => $model->findRequest((int) $row->id),
            'action' => $action,
        ]);
    }

    /**
     * Who decided it?
     *
     * The link carries no session, so the best available answer is the account
     * behind the address the mail was sent to. When no configured address belongs
     * to anybody, the decision is recorded with no reviewer rather than attributed
     * to an account that did not make it.
     */
    private function reviewerId(): ?int
    {
        // Through the notifier, so the comma-separated setting is parsed in one
        // place. With several addresses configured we cannot know which of them
        // clicked, so the first that belongs to an account is the honest answer.
        foreach ((new RequestNotifier())->recipients() as $address) {
            $user = model(UserModel::class)->findActiveByEmail($address);

            if ($user !== null) {
                return (int) $user->id;
            }
        }

        return null;
    }

    private function normaliseAction(string $action): string
    {
        return isset(self::ACTIONS[$action]) ? $action : 'approve';
    }

    private function page(object $row, string $token, string $action, ?string $error = null): string
    {
        helper('attendance');

        $employee = model(UserModel::class)->findUser((int) $row->user_id);

        return view('requests/decision', [
            'row'      => $row,
            'employee' => $employee,
            'token'    => $token,
            'action'   => $action,
            'error'    => $error,
        ]);
    }

    /**
     * One page for every dead end — unknown token, spent token, expired token,
     * request already decided. Telling them apart would tell somebody probing
     * the endpoint whether a token ever existed.
     */
    private function expired(): ResponseInterface
    {
        return $this->response
            ->setStatusCode(410)
            ->setBody(view('requests/decision_expired'));
    }
}
