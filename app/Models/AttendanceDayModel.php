<?php

namespace App\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Model;
use RuntimeException;

/**
 * Over `attendance_days` — one row per user per working day (see
 * kanban/processing/20260722-01-attendance-checkin-checkout.md).
 *
 * All DATETIME values here are UTC. `work_date` is the Vietnam calendar date,
 * supplied by callers via `attendance_today()` — this model never guesses the
 * business day from the server clock.
 *
 * There is no stored `work_seconds`: worked time is derived (card 01b), so an
 * admin correction can't leave a stale total behind.
 */
class AttendanceDayModel extends Model
{
    /**
     * Sortable columns for the management list (`searchAdmin()`); anything else
     * falls back to `work_date`. Never interpolate a caller-supplied column.
     */
    public const SORT_ALLOWLIST = ['work_date', 'checkin_at', 'checkout_at', 'user_name'];

    protected $table            = 'attendance_days';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'user_id', 'work_date', 'checkin_at', 'checkout_at', 'checkin_ip', 'checkout_ip',
        'missing_checkout', 'note', 'edited_by', 'edited_at',
        'original_checkin_at', 'original_checkout_at', 'checkout_reminded_at',
    ];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Typed wrapper over `find()` (whose generic signature phpstan can't narrow
     * to `object` from `$returnType`).
     */
    public function findDay(int $id): ?object
    {
        return $this->find($id);
    }

    /**
     * The employee's row for a given Vietnam calendar date, or null.
     */
    public function dayFor(int $userId, string $workDate): ?object
    {
        return $this->where('user_id', $userId)->where('work_date', $workDate)->first();
    }

    /**
     * The employee's row for today (Vietnam calendar), or null.
     */
    public function todayFor(int $userId): ?object
    {
        helper('attendance');

        return $this->dayFor($userId, attendance_today());
    }

    /**
     * The employee's own history, newest first.
     *
     * @return list<object>
     */
    public function historyFor(int $userId, int $limit = 25, int $offset = 0): array
    {
        return $this->where('user_id', $userId)
            ->orderBy('work_date', 'DESC')
            ->findAll($limit, $offset);
    }

    public function countForUser(int $userId): int
    {
        return $this->where('user_id', $userId)->countAllResults();
    }

    /**
     * Record an arrival. The UNIQUE(user_id, work_date) key is the real
     * one-per-day guard — the controller's pre-check only exists to produce a
     * friendly message, so a duplicate here surfaces as a DB error the caller
     * translates into "already checked in".
     */
    public function checkin(int $userId, string $ip, string $workDate, string $nowUtc): object
    {
        $this->insert([
            'user_id'    => $userId,
            'work_date'  => $workDate,
            'checkin_at' => $nowUtc,
            'checkin_ip' => $ip,
        ]);

        $row = $this->dayFor($userId, $workDate);

        if ($row === null) {
            // The insert reported success but the row is not readable — a broken
            // connection or a replica lag, not a duplicate. Fail loudly rather
            // than hand the controller a fabricated "checked in" response.
            throw new RuntimeException('Attendance row could not be read back after check-in.');
        }

        return $row;
    }

    /**
     * Manager-created row for a day the employee never recorded. Kept in the
     * model so the duplicate-key `DatabaseException` (UNIQUE(user_id,
     * work_date)) is raised from the same layer the controller catches it in,
     * and so no controller writes to this table directly.
     *
     * @throws DatabaseException on a duplicate user+date
     */
    public function createManual(int $userId, string $workDate): int
    {
        return (int) $this->insert([
            'user_id'   => $userId,
            'work_date' => $workDate,
        ], true);
    }

    /**
     * Record a departure. Re-checking out overwrites the previous time (last
     * one wins) so an accidental lunchtime check-out is self-healing instead of
     * locking the employee out of the rest of the day. Clears the
     * missing-checkout flag the nightly job may have set.
     */
    public function checkout(int $id, string $ip, string $nowUtc): ?object
    {
        $this->update($id, [
            'checkout_at'      => $nowUtc,
            'checkout_ip'      => $ip,
            'missing_checkout' => 0,
        ]);

        return $this->findDay($id);
    }

    /**
     * The single place a manager's correction is written.
     *
     * Back-fills `original_checkin_at`/`original_checkout_at` only on the first
     * correction (`edited_at IS NULL`), so the value the employee actually
     * recorded stays recoverable no matter how many times it is later adjusted.
     * Attendance feeds payroll; a silent edit is not acceptable.
     *
     * ponytail: one method so no caller can bypass the audit trail. Two columns
     * rather than a history table — add attendance_edits only if a full audit
     * log is ever required.
     *
     * @param array{checkin_at?: string|null, checkout_at?: string|null, note?: string|null} $data
     */
    public function applyCorrection(int $id, array $data, int $editorId, string $nowUtc): bool
    {
        $row = $this->findDay($id);

        if ($row === null) {
            return false;
        }

        $update = [
            'edited_by' => $editorId,
            'edited_at' => $nowUtc,
        ];

        foreach (['checkin_at', 'checkout_at', 'note'] as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }

        if ($row->edited_at === null) {
            $update['original_checkin_at']  = $row->checkin_at;
            $update['original_checkout_at'] = $row->checkout_at;
        }

        // A corrected check-out clears the "forgot to check out" flag.
        if (array_key_exists('checkout_at', $update) && $update['checkout_at'] !== null && $update['checkout_at'] !== '') {
            $update['missing_checkout'] = 0;
        }

        return (bool) $this->update($id, $update);
    }

    /**
     * Management list search: keyword-free, filtered by user and/or date range,
     * joined to `users` for the display name. `id` is a secondary sort key in
     * the same direction so ties resolve deterministically across pages.
     *
     * @param array{user_id?: int|string, date_from?: string, date_to?: string, missing_checkout?: int|string} $filters
     *
     * @return list<object>
     */
    public function searchAdmin(array $filters, string $sort = 'work_date', string $order = 'desc', int $limit = 25, int $offset = 0): array
    {
        $this->select('attendance_days.*, users.name AS user_name, users.email AS user_email, editors.name AS editor_name')
            ->join('users', 'users.id = attendance_days.user_id', 'left')
            ->join('users AS editors', 'editors.id = attendance_days.edited_by', 'left');

        $this->applyAdminFilters($this->builder(), $filters);

        $sortColumn = in_array($sort, self::SORT_ALLOWLIST, true) ? $sort : 'work_date';
        $sortColumn = $sortColumn === 'user_name' ? 'users.name' : 'attendance_days.' . $sortColumn;
        $sortOrder  = strtolower($order) === 'asc' ? 'ASC' : 'DESC';

        $this->orderBy($sortColumn, $sortOrder)->orderBy('attendance_days.id', $sortOrder);

        return $this->findAll($limit, $offset);
    }

    /**
     * @param array{user_id?: int|string, date_from?: string, date_to?: string, missing_checkout?: int|string} $filters
     */
    public function countAdmin(array $filters): int
    {
        $this->applyAdminFilters($this->builder(), $filters);

        return $this->countAllResults();
    }

    /**
     * Rows for a set of users over a date range — the bulk loader the today
     * board and the monthly report use, so neither ever queries per employee
     * or per day.
     *
     * @param list<int> $userIds
     *
     * @return list<object>
     */
    public function forUsersInRange(array $userIds, string $from, string $to): array
    {
        if ($userIds === []) {
            return [];
        }

        return $this->whereIn('user_id', $userIds)
            ->where('work_date >=', $from)
            ->where('work_date <=', $to)
            ->orderBy('work_date', 'ASC')
            ->findAll();
    }

    /**
     * Rows on `$workDate` that have an arrival but no departure and have not
     * been flagged yet — the nightly `attendance.flag_missing_checkout` job's
     * work list.
     *
     * @return list<object>
     */
    public function openRowsOn(string $workDate): array
    {
        return $this->where('work_date', $workDate)
            ->where('checkin_at IS NOT NULL')
            ->where('checkout_at', null)
            ->where('missing_checkout', 0)
            ->findAll();
    }

    /**
     * Rows on `$workDate` where the employee is still checked in and has not been
     * reminded yet — the `attendance.remind_checkout` work list (card 01d).
     *
     * The `checkout_reminded_at IS NULL` clause is what makes the handler safe
     * under minute-by-minute cron.
     *
     * @return list<object>
     */
    public function needingCheckoutReminder(string $workDate): array
    {
        return $this->select('attendance_days.*, users.name AS user_name')
            ->join('users', 'users.id = attendance_days.user_id', 'left')
            ->where('attendance_days.work_date', $workDate)
            ->where('attendance_days.checkin_at IS NOT NULL')
            ->where('attendance_days.checkout_at', null)
            ->where('attendance_days.checkout_reminded_at', null)
            // Nobody off the clock gets nudged: a person exempted while a row was
            // still open would otherwise be reminded daily about attendance that
            // is not measured for them. NULL-safe so a row whose user vanished
            // still surfaces rather than being silently dropped.
            ->where('COALESCE(users.attendance_exempt, 0) =', 0)
            ->findAll();
    }

    /**
     * Stamp the reminder so the same person is never told twice.
     *
     * @param list<int> $ids
     */
    public function markCheckoutReminded(array $ids, string $nowUtc): int
    {
        if ($ids === []) {
            return 0;
        }

        $this->whereIn('id', $ids)->set('checkout_reminded_at', $nowUtc)->update();

        return count($ids);
    }

    /**
     * Days still flagged as missing a check-out in a date range and still
     * uncorrected — they block the monthly report, so managers get a weekly nudge.
     *
     * @return list<object>
     */
    public function uncorrectedMissingCheckouts(string $from, string $to): array
    {
        return $this->select('attendance_days.*, users.name AS user_name')
            ->join('users', 'users.id = attendance_days.user_id', 'left')
            ->where('attendance_days.missing_checkout', 1)
            ->where('attendance_days.checkout_at', null)
            ->where('attendance_days.work_date >=', $from)
            ->where('attendance_days.work_date <=', $to)
            // Same rule as the check-out reminder: exempt employees are left off
            // the attendance machinery. Their stale rows are still visible on
            // /admin/attendance, which is where a manager corrects data.
            ->where('COALESCE(users.attendance_exempt, 0) =', 0)
            ->orderBy('attendance_days.work_date', 'ASC')
            ->findAll();
    }

    /**
     * Flag the given rows as missing a check-out. Deliberately does **not**
     * invent a checkout time: fabricating payroll data is worse than an
     * incomplete row a human has to look at.
     *
     * @param list<int> $ids
     */
    public function flagMissingCheckout(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $this->whereIn('id', $ids)->set('missing_checkout', 1)->update();

        return count($ids);
    }

    /**
     * The one place the management filters become WHERE clauses — shared by
     * `searchAdmin()`/`countAdmin()` so the paginated rows and the total can't
     * drift apart.
     *
     * @param array{user_id?: int|string, date_from?: string, date_to?: string, missing_checkout?: int|string} $filters
     */
    private function applyAdminFilters(BaseBuilder $builder, array $filters): void
    {
        $userId = (int) ($filters['user_id'] ?? 0);
        if ($userId > 0) {
            $builder->where('attendance_days.user_id', $userId);
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $builder->where('attendance_days.work_date >=', $dateFrom);
        }

        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== '') {
            $builder->where('attendance_days.work_date <=', $dateTo);
        }

        if ((string) ($filters['missing_checkout'] ?? '') === '1') {
            $builder->where('attendance_days.missing_checkout', 1);
        }
    }
}
