<?php

namespace App\Controllers;

use App\Models\NotificationModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Per-user notification list/feed/mark-read, shared by admin + client (both
 * extend `layouts/app` and sit behind `authFilter`) — no `Admin\`/`Client\`
 * split since the behaviour is identical. Every read/mutation is derived
 * from `session('userWorking')`; the owning `user_id` is never accepted from
 * request input, and `markRead()` is owner-scoped in the model (no IDOR).
 * Notification *creation* is out of scope here (scheduled-tasks card).
 */
class Notifications extends BaseController
{
    /**
     * Recent feed size for the dropdown (`feed()`).
     */
    private const FEED_LIMIT = 10;

    /**
     * Full list page size (`/notifications`).
     */
    private const PAGE_SIZE = 15;

    public function index(): string
    {
        $me     = session('userWorking');
        $userId = (int) $me->id;
        $model  = model(NotificationModel::class);
        $page   = max(1, (int) $this->request->getGet('page'));
        $offset = ($page - 1) * self::PAGE_SIZE;

        return view('notifications/index', [
            'items'       => $model->listForUser($userId, self::PAGE_SIZE, $offset),
            'total'       => $model->countForUser($userId),
            'page'        => $page,
            'perPage'     => self::PAGE_SIZE,
            'unreadCount' => $model->unreadCountForUser($userId),
        ]);
    }

    /**
     * Recent items + unread count for the topbar dropdown. Returns the raw
     * stored `message`/`created_at` — the view binds them via Alpine
     * `x-text` (never `x-html`), so escaping is the frontend's job.
     */
    public function feed(): ResponseInterface
    {
        $me     = session('userWorking');
        $userId = (int) $me->id;
        $model  = model(NotificationModel::class);

        return $this->jsonSuccess('OK', [
            'items'        => $model->recentForUser($userId, self::FEED_LIMIT),
            'unread_count' => $model->unreadCountForUser($userId),
        ]);
    }

    /**
     * Badge count only — cheap poll target for the initial page load.
     */
    public function unreadCount(): ResponseInterface
    {
        $me = session('userWorking');

        return $this->jsonSuccess('OK', [
            'unread_count' => model(NotificationModel::class)->unreadCountForUser((int) $me->id),
        ]);
    }

    public function markRead(): ResponseInterface
    {
        $me     = session('userWorking');
        $userId = (int) $me->id;
        $id     = (int) $this->request->getPost('id');
        $model  = model(NotificationModel::class);

        if ($id <= 0 || ! $model->markRead($id, $userId)) {
            return $this->jsonFailed('Notification not found.', 404);
        }

        return $this->jsonSuccess('Notification marked read.', [
            'unread_count' => $model->unreadCountForUser($userId),
        ]);
    }

    public function markAllRead(): ResponseInterface
    {
        $me     = session('userWorking');
        $userId = (int) $me->id;
        $count  = model(NotificationModel::class)->markAllRead($userId);

        return $this->jsonSuccess("{$count} notification(s) marked read.", ['unread_count' => 0]);
    }
}
