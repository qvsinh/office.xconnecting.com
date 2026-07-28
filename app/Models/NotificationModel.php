<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Over the `notifications` table — per-user notification feed/list, read by
 * `Notifications` controller (shared by admin + client, both scoped to
 * `session('userWorking')->id`). Row creation is out of scope for this card
 * (comes with the scheduled-tasks card); every method here is read/mark-read
 * only, always owner-scoped, never trusting a caller-supplied `user_id`.
 */
class NotificationModel extends Model
{
    protected $table            = 'notifications';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    // `task_id` dropped with the tasks module; the column still exists on the
    // table but nothing writes it, so it stays out of the allowlist.
    protected $allowedFields = ['user_id', 'message', 'is_read'];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Paginated, newest-first list for the full `/notifications` page.
     * Secondary `id DESC` sort keeps pagination stable when two rows share a
     * `created_at` second.
     *
     * @return list<object>
     */
    public function listForUser(int $userId, int $limit, int $offset): array
    {
        return $this->where('user_id', $userId)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->findAll($limit, $offset);
    }

    /**
     * Total row count for the user (pagination).
     */
    public function countForUser(int $userId): int
    {
        return $this->where('user_id', $userId)->countAllResults();
    }

    /**
     * Unread count for the badge.
     */
    public function unreadCountForUser(int $userId): int
    {
        return $this->where('user_id', $userId)->where('is_read', 0)->countAllResults();
    }

    /**
     * Most recent `$limit` rows for the dropdown feed — same ordering as
     * `listForUser()`, just capped and offset-free.
     *
     * @return list<object>
     */
    public function recentForUser(int $userId, int $limit): array
    {
        return $this->listForUser($userId, $limit, 0);
    }

    /**
     * Mark one row read, scoped to its owner. Returns false (no mutation) if
     * the row is missing or belongs to someone else — the two cases are
     * indistinguishable to the caller (no existence leak, no IDOR).
     */
    public function markRead(int $id, int $userId): bool
    {
        $row = $this->where('id', $id)->where('user_id', $userId)->first();

        if ($row === null) {
            return false;
        }

        return (bool) $this->update($id, ['is_read' => 1]);
    }

    /**
     * Mark every unread row for the user read. Returns the number of rows
     * affected. Fetches the matching ids first and updates by primary key so
     * `Model::update()` (not a raw builder update) still stamps
     * `updated_at` for each row.
     */
    public function markAllRead(int $userId): int
    {
        $ids = array_column($this->where('user_id', $userId)->where('is_read', 0)->findAll(), 'id');

        if ($ids === []) {
            return 0;
        }

        $this->update($ids, ['is_read' => 1]);

        return count($ids);
    }
}
