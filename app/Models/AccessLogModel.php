<?php

namespace App\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Model;

/**
 * Over `access_logs` — login / check-in / check-out events with the device and
 * network context they happened from.
 *
 * Append-only by design: nothing in the app updates or deletes a row except the
 * `access_logs.purge` scheduled handler. An audit trail somebody can edit is not
 * an audit trail.
 */
class AccessLogModel extends Model
{
    public const EVENT_LOGIN    = 'login';
    public const EVENT_CHECKIN  = 'checkin';
    public const EVENT_CHECKOUT = 'checkout';
    public const EVENTS         = [self::EVENT_LOGIN, self::EVENT_CHECKIN, self::EVENT_CHECKOUT];

    /**
     * Sortable columns for the admin list; anything else falls back to
     * `created_at`. Never interpolate a caller-supplied column.
     */
    public const SORT_ALLOWLIST = ['created_at', 'event', 'ip', 'user_name'];

    protected $table            = 'access_logs';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'user_id', 'event', 'ip', 'user_agent', 'browser', 'platform',
        'device_type', 'screen_width', 'screen_height',
    ];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = ''; // append-only: a row is never updated

    /**
     * Typed wrapper over `find()`.
     */
    public function findLog(int $id): ?object
    {
        return $this->find($id);
    }

    /**
     * Admin list: newest first, joined to `users` for the display name.
     *
     * @param array{user_id?: int|string, event?: string, date_from?: string, date_to?: string, keyword?: string} $filters
     *
     * @return list<object>
     */
    public function searchAdmin(array $filters, string $sort = 'created_at', string $order = 'desc', int $limit = 25, int $offset = 0): array
    {
        $this->select('access_logs.*, users.name AS user_name, users.email AS user_email')
            ->join('users', 'users.id = access_logs.user_id', 'left');

        $this->applyAdminFilters($this->builder(), $filters);

        $sortColumn = in_array($sort, self::SORT_ALLOWLIST, true) ? $sort : 'created_at';
        $sortColumn = $sortColumn === 'user_name' ? 'users.name' : 'access_logs.' . $sortColumn;
        $sortOrder  = strtolower($order) === 'asc' ? 'ASC' : 'DESC';

        return $this->orderBy($sortColumn, $sortOrder)
            ->orderBy('access_logs.id', $sortOrder)
            ->findAll($limit, $offset);
    }

    /**
     * @param array{user_id?: int|string, event?: string, date_from?: string, date_to?: string, keyword?: string} $filters
     */
    public function countAdmin(array $filters): int
    {
        $this->applyAdminFilters($this->builder(), $filters);

        return $this->countAllResults();
    }

    /**
     * The most recent events for one employee — the "recent activity" panel on
     * the admin user detail page.
     *
     * @return list<object>
     */
    public function recentForUser(int $userId, int $limit = 10): array
    {
        return $this->where('user_id', $userId)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->findAll($limit);
    }

    /**
     * Distinct devices/addresses a user has been seen from, newest first — the
     * shape that actually answers "is somebody else using this account?".
     *
     * @return list<object>
     */
    public function devicesForUser(int $userId, int $limit = 20): array
    {
        return $this->select('ip, browser, platform, device_type, screen_width, screen_height, MAX(created_at) AS last_seen, COUNT(*) AS events')
            ->where('user_id', $userId)
            ->groupBy('ip, browser, platform, device_type, screen_width, screen_height')
            ->orderBy('last_seen', 'DESC')
            ->findAll($limit);
    }

    /**
     * The one place the admin filters become WHERE clauses, shared by
     * `searchAdmin()`/`countAdmin()` so the rows and the total can't drift.
     *
     * @param array{user_id?: int|string, event?: string, date_from?: string, date_to?: string, keyword?: string} $filters
     */
    private function applyAdminFilters(BaseBuilder $builder, array $filters): void
    {
        $userId = (int) ($filters['user_id'] ?? 0);
        if ($userId > 0) {
            $builder->where('access_logs.user_id', $userId);
        }

        $event = (string) ($filters['event'] ?? '');
        if (in_array($event, self::EVENTS, true)) {
            $builder->where('access_logs.event', $event);
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $builder->where('access_logs.created_at >=', $dateFrom . ' 00:00:00');
        }

        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== '') {
            $builder->where('access_logs.created_at <=', $dateTo . ' 23:59:59');
        }

        // One box that searches the things an admin actually types: an address,
        // or part of a browser/OS name.
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword === '') {
            return;
        }

        $builder->groupStart()
            ->like('access_logs.ip', $keyword)
            ->orLike('access_logs.browser', $keyword)
            ->orLike('access_logs.platform', $keyword)
            ->orLike('users.name', $keyword)
            ->groupEnd();
    }
}
