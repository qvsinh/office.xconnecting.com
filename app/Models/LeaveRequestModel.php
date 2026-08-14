<?php

namespace App\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\I18n\Time;
use CodeIgniter\Model;

/**
 * Over `leave_requests` — leave / late-arrival requests and their approval state.
 *
 * The `approvedFor*` methods are the contract `AttendanceCalendar` (card 01b)
 * consumes: an approved leave suppresses `absent`, an approved late request
 * suppresses `late`. Only `approved` rows ever reach the calendar — a pending or
 * cancelled request must change nothing about the attendance sheet.
 */
class LeaveRequestModel extends Model
{
    public const SORT_ALLOWLIST   = ['id', 'start_date', 'status', 'type', 'created_at', 'user_name'];
    public const STATUS_PENDING   = 'pending';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_REJECTED  = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';
    public const TYPE_LEAVE       = 'leave';
    public const TYPE_LATE        = 'late';
    public const KINDS            = ['annual', 'unpaid', 'sick'];
    public const PORTIONS         = ['full', 'morning', 'afternoon'];

    protected $table            = 'leave_requests';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'user_id', 'type', 'leave_kind', 'start_date', 'end_date', 'day_portion',
        'expected_time', 'reason', 'status', 'reviewed_by', 'reviewed_at', 'review_note',
        'decision_token_hash', 'decision_token_expires_at',
    ];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Typed wrapper over `find()`.
     */
    public function findRequest(int $id): ?object
    {
        return $this->find($id);
    }

    /**
     * @return list<object>
     */
    public function forUser(int $userId, int $limit = 25, int $offset = 0): array
    {
        return $this->where('user_id', $userId)
            ->orderBy('start_date', 'DESC')
            ->orderBy('id', 'DESC')
            ->findAll($limit, $offset);
    }

    public function countForUser(int $userId): int
    {
        return $this->where('user_id', $userId)->countAllResults();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createRequest(array $data): int
    {
        return (int) $this->insert($data + ['status' => self::STATUS_PENDING], true);
    }

    /**
     * Management list: filtered, joined to `users` for the display name, pending
     * first so the queue is the default view.
     *
     * @param array{status?: string, type?: string, leave_kind?: string, user_id?: int|string} $filters
     *
     * @return list<object>
     */
    public function searchAdmin(array $filters, string $sort = 'start_date', string $order = 'desc', int $limit = 25, int $offset = 0): array
    {
        $this->select('leave_requests.*, users.name AS user_name, users.email AS user_email, reviewers.name AS reviewer_name')
            ->join('users', 'users.id = leave_requests.user_id', 'left')
            ->join('users AS reviewers', 'reviewers.id = leave_requests.reviewed_by', 'left');

        $this->applyAdminFilters($this->builder(), $filters);

        $sortColumn = in_array($sort, self::SORT_ALLOWLIST, true) ? $sort : 'start_date';
        $sortColumn = $sortColumn === 'user_name' ? 'users.name' : 'leave_requests.' . $sortColumn;
        $sortOrder  = strtolower($order) === 'asc' ? 'ASC' : 'DESC';

        // Pending first regardless of the chosen sort: an unanswered request is
        // somebody waiting on a decision.
        $this->orderBy("leave_requests.status = 'pending'", 'DESC', false)
            ->orderBy($sortColumn, $sortOrder)
            ->orderBy('leave_requests.id', $sortOrder);

        return $this->findAll($limit, $offset);
    }

    /**
     * @param array{status?: string, type?: string, leave_kind?: string, user_id?: int|string} $filters
     */
    public function countAdmin(array $filters): int
    {
        $this->applyAdminFilters($this->builder(), $filters);

        return $this->countAllResults();
    }

    public function countPending(): int
    {
        return $this->where('status', self::STATUS_PENDING)->countAllResults();
    }

    /**
     * Record a decision. The `pending` check lives **inside the WHERE clause**,
     * so two managers clicking Approve at the same moment cannot both win — the
     * second update matches no rows and returns false.
     *
     * ponytail: the WHERE clause is the lock; no transaction needed.
     */
    public function review(int $id, string $status, ?int $reviewerId, ?string $note): bool
    {
        $this->builder()
            ->where('id', $id)
            ->where('status', self::STATUS_PENDING)
            ->update([
                'status'      => $status,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => date('Y-m-d H:i:s'),
                'review_note' => $note,
                'updated_at'  => date('Y-m-d H:i:s'),
                // The emailed link dies with the decision it was minted for.
                'decision_token_hash'       => null,
                'decision_token_expires_at' => null,
            ]);

        return $this->db->affectedRows() > 0;
    }

    /**
     * Mint the one-click decision link for the administrator's email and return
     * the plaintext token — the only time it exists outside that message.
     *
     * Shaped like a personal access token (`ApiKeyModel::createForUser`): the
     * database keeps a sha256 hash, never the token itself, so a database backup
     * cannot be replayed into an approval.
     *
     * The token is `<hashid of the request id>.<random secret>`. The hashid half
     * makes the URL unique per request and finds the row without exposing a
     * sequential id; the random half is what actually authorises — hashids is
     * reversible by design and would be a lock with the key painted on it.
     */
    public function mintDecisionToken(int $id, int $daysValid = 14): string
    {
        helper('app');

        $plain = get_hashids()->encode($id) . '.' . bin2hex(random_bytes(16));

        $this->builder()
            ->where('id', $id)
            ->update([
                'decision_token_hash' => hash('sha256', $plain),
                // Time::now(), not strtotime(): the app's clock is the one the
                // rest of attendance is measured on, and it is the only one a
                // test can move.
                'decision_token_expires_at' => Time::now()->addDays($daysValid)->format('Y-m-d H:i:s'),
                'updated_at'                => date('Y-m-d H:i:s'),
            ]);

        return $plain;
    }

    /**
     * Resolve an emailed token back to its request, or null.
     *
     * Rejects an unknown, spent or expired token, and never leaks *which* of
     * those it was — a caller telling those apart would be telling an attacker
     * whether a token ever existed.
     */
    public function findByDecisionToken(string $plain): ?object
    {
        helper('app');

        [$hashid] = array_pad(explode('.', $plain, 2), 2, '');
        $decoded  = get_hashids()->decode($hashid);

        if ($decoded === []) {
            return null;
        }

        $row = $this->find((int) $decoded[0]);

        if (! is_object($row) || ($row->decision_token_hash ?? null) === null) {
            return null;
        }

        if (! hash_equals((string) $row->decision_token_hash, hash('sha256', $plain))) {
            return null;
        }

        if (($row->decision_token_expires_at ?? null) !== null && $row->decision_token_expires_at < Time::now()->format('Y-m-d H:i:s')) {
            return null;
        }

        return $row;
    }

    /**
     * Retire the link without deciding anything — used when the employee
     * withdraws, so a stale mail in somebody's inbox stops working.
     */
    public function clearDecisionToken(int $id): void
    {
        $this->builder()->where('id', $id)->update([
            'decision_token_hash'       => null,
            'decision_token_expires_at' => null,
        ]);
    }

    /**
     * Employee withdrawing their own still-pending request. Owner-scoped in the
     * same statement (no IDOR) and status-scoped (no reviving a decided row).
     */
    public function cancelOwn(int $id, int $userId): bool
    {
        $this->builder()
            ->where('id', $id)
            ->where('user_id', $userId)
            ->where('status', self::STATUS_PENDING)
            ->update(['status' => self::STATUS_CANCELLED, 'updated_at' => date('Y-m-d H:i:s')]);

        return $this->db->affectedRows() > 0;
    }

    /**
     * Bulk loader for `AttendanceCalendar::evaluateSpan()` — one query for a whole
     * month across the whole roster, so the report never queries per employee.
     *
     * @param list<int> $userIds
     *
     * @return list<object>
     */
    public function approvedForUsersInRange(array $userIds, string $from, string $to): array
    {
        if ($userIds === []) {
            return [];
        }

        // Overlap test: the request starts on or before the range end, and ends
        // (defaulting to its start) on or after the range start.
        return $this->whereIn('user_id', $userIds)
            ->where('status', self::STATUS_APPROVED)
            ->where('start_date <=', $to)
            ->groupStart()
            ->where('end_date >=', $from)
            ->orGroupStart()
            ->where('end_date', null)
            ->where('start_date >=', $from)
            ->groupEnd()
            ->groupEnd()
            ->orderBy('start_date', 'ASC')
            ->findAll();
    }

    /**
     * Whether the employee already has a pending or approved request touching the
     * range — a double booking is a mistake worth catching at submit time.
     */
    public function overlapsExisting(int $userId, string $from, string $to, ?int $ignoreId = null): bool
    {
        $builder = $this->builder()
            ->where('user_id', $userId)
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_APPROVED])
            ->where('start_date <=', $to)
            ->groupStart()
            ->where('end_date >=', $from)
            ->orGroupStart()
            ->where('end_date', null)
            ->where('start_date >=', $from)
            ->groupEnd()
            ->groupEnd();

        if ($ignoreId !== null) {
            $builder->where('id !=', $ignoreId);
        }

        return $builder->countAllResults() > 0;
    }

    /**
     * Requests still awaiting a decision after `$hours` — the input to the
     * pending-requests digest (card 01d). Employees are waiting on an answer.
     *
     * @return list<object>
     */
    public function pendingOlderThan(int $hours): array
    {
        return $this->where('status', self::STATUS_PENDING)
            ->where('created_at <=', date('Y-m-d H:i:s', time() - $hours * 3600))
            ->orderBy('created_at', 'ASC')
            ->findAll();
    }

    /**
     * Approved annual-leave rows in a calendar year — the input to the balance
     * calculation (which weights them by workday, see `LeaveBalance`).
     *
     * @return list<object>
     */
    public function approvedAnnualInYear(int $userId, int $year): array
    {
        return $this->where('user_id', $userId)
            ->where('status', self::STATUS_APPROVED)
            ->where('type', self::TYPE_LEAVE)
            ->where('leave_kind', 'annual')
            ->where('start_date <=', sprintf('%04d-12-31', $year))
            ->groupStart()
            ->where('end_date >=', sprintf('%04d-01-01', $year))
            ->orWhere('end_date', null)
            ->groupEnd()
            ->orderBy('start_date', 'ASC')
            ->findAll();
    }

    /**
     * The one place the management filters become WHERE clauses, shared by
     * `searchAdmin()`/`countAdmin()` so rows and total can't drift.
     *
     * @param array{status?: string, type?: string, leave_kind?: string, user_id?: int|string} $filters
     */
    private function applyAdminFilters(BaseBuilder $builder, array $filters): void
    {
        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED], true)) {
            $builder->where('leave_requests.status', $status);
        }

        $type = (string) ($filters['type'] ?? '');
        if (in_array($type, [self::TYPE_LEAVE, self::TYPE_LATE], true)) {
            $builder->where('leave_requests.type', $type);
        }

        $kind = (string) ($filters['leave_kind'] ?? '');
        if (in_array($kind, self::KINDS, true)) {
            $builder->where('leave_requests.leave_kind', $kind);
        }

        $userId = (int) ($filters['user_id'] ?? 0);
        if ($userId > 0) {
            $builder->where('leave_requests.user_id', $userId);
        }
    }
}
