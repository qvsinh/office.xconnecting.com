<?php

namespace App\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Model;

/**
 * Over the existing `users` table (see legacy proxy.services.vn schema).
 * `returnType='object'` so `session('userWorking')->id` etc. keep working
 * exactly as the legacy app expects.
 */
class UserModel extends Model
{
    /**
     * Sortable columns for the admin user list (`searchAdmin()`); anything
     * else falls back to `login_at`.
     */
    public const SORT_ALLOWLIST = ['id', 'name', 'email', 'company', 'zone', 'status', 'login_at', 'created_at'];

    /**
     * Columns searched by a `keyword` filter term in `searchAdmin()`.
     */
    private const KEYWORD_FIELDS = ['name', 'email', 'company'];

    /**
     * Columns returned by `searchAdmin()` — explicitly excludes
     * `password`/`google2fa_secret` so the admin list result set never
     * carries a secret into the view layer, even by accident.
     */
    private const LIST_COLUMNS = 'id, name, email, company, zone, status, attendance_exempt, login_at, created_at';

    protected $table            = 'users';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = true;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'name',
        'email',
        'password',
        'company',
        'phone_number',
        'zone',
        'role_id',
        'status',
        'two_factor_method',
        'google2fa_secret',
        'language',
        'timezone',
        'login_at',
        'note',
        // Which `work_schedules` row measures this employee's attendance;
        // NULL falls back to the company default (attendance card 01b).
        'work_schedule_id',
        // Per-employee annual leave quota; NULL falls back to the site setting.
        'annual_leave_days',
        // 1 = not on the clock: no check-in/out, left off the attendance screens.
        'attendance_exempt',
        'created_at',
    ];
    protected $useTimestamps = false;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';

    /**
     * Look up an active (non-soft-deleted) user by email. Soft-deleted rows
     * are excluded automatically because `useSoftDeletes` is enabled.
     */
    public function findActiveByEmail(string $email): ?object
    {
        return $this->where('email', $email)->first();
    }

    /**
     * Stamp `login_at` on successful authentication.
     */
    public function stampLogin(int $id): void
    {
        $this->update($id, ['login_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Typed wrapper over `find()` (whose generic signature phpstan can't
     * narrow to `object` from `$returnType`) for callers that need property
     * access on the result.
     */
    public function findUser(int $id): ?object
    {
        return $this->find($id);
    }

    /**
     * Persists an enrolled Google Authenticator (TOTP) secret and switches
     * the account over to it — the one place `google2fa_secret` is written
     * on enrollment (`Account::verifyGoogle2fa()`).
     */
    public function enableGoogle2fa(int $id, string $secret): void
    {
        $this->update($id, [
            'google2fa_secret'  => $secret,
            'two_factor_method' => 'google2fa',
        ]);
    }

    /**
     * Clears the TOTP secret and reverts to password-only login
     * (`Account::disable2fa()`).
     */
    public function disableTwoFactor(int $id): void
    {
        $this->update($id, [
            'google2fa_secret'  => null,
            'two_factor_method' => 'none',
        ]);
    }

    /**
     * Active users not already a member of the given group, `id, name,
     * email` only — backs the add-member picker on `Admin\Groups::members()`.
     *
     * @return list<object>
     */
    public function activeUsersNotInGroup(int $groupId): array
    {
        return $this->select('id, name, email')
            ->where('status', 1)
            ->whereNotIn('id', static fn (BaseBuilder $builder) => $builder->select('user_id')->from('group_members')->where('group_id', $groupId))
            ->orderBy('name', 'ASC')
            ->findAll();
    }

    /**
     * Admin list search (`Admin\Users::index()`): keyword LIKE across
     * name/email/company, optional `zone`/`status` filters. `id` is always a
     * secondary sort key in the same direction so ties (e.g. many rows
     * sharing a NULL `login_at`) resolve deterministically across pages.
     * Soft-deleted rows are excluded automatically by `useSoftDeletes`.
     *
     * @param array{keyword?: string, zone?: string, status?: int|string} $filters
     *
     * @return list<object>
     */
    public function searchAdmin(array $filters, string $sort = 'login_at', string $order = 'desc', int $limit = 25, int $offset = 0): array
    {
        $this->select(self::LIST_COLUMNS);
        $this->applyAdminFilters($this->builder(), $filters);

        $sortColumn = in_array($sort, self::SORT_ALLOWLIST, true) ? $sort : 'login_at';
        $sortOrder  = strtolower($order) === 'asc' ? 'ASC' : 'DESC';

        $this->orderBy($sortColumn, $sortOrder);
        if ($sortColumn !== 'id') {
            $this->orderBy('id', $sortOrder);
        }

        return $this->findAll($limit, $offset);
    }

    /**
     * @param array{keyword?: string, zone?: string, status?: int|string} $filters
     */
    public function countAdmin(array $filters): int
    {
        $this->applyAdminFilters($this->builder(), $filters);

        return $this->countAllResults();
    }

    /**
     * Every active account: `status = 1`, non-soft-deleted, both zones.
     *
     * This is the "pick a person" set — the employee dropdowns on the leave-request
     * queue and the access log, where somebody exempt from attendance still takes
     * leave and still signs in. For anything that measures attendance use
     * {@see attendanceRoster()}.
     *
     * Bounded so a large tenant can't OOM a page.
     *
     * @return list<object>
     */
    public function activeRoster(int $limit = 500): array
    {
        return $this->rosterQuery()->findAll($limit);
    }

    /**
     * The employee roster attendance is measured over: active accounts minus the
     * ones flagged `attendance_exempt`.
     *
     * The exempt ones are not absent, they are simply not on the clock — a
     * director, a contractor, an account that only receives reports. Counting them
     * turned the today board and the monthly report into a wall of red for people
     * nobody expected to see there, and had the reminder job nudging them daily.
     *
     * One definition, used by the today board, the dashboard, the monthly report
     * and the reminders. Absence being the *absence* of a row means every one of
     * those screens starts from this list, so a second copy of the rule would
     * eventually disagree with this one.
     *
     * @return list<object>
     */
    public function attendanceRoster(int $limit = 500): array
    {
        return $this->rosterQuery()->where('attendance_exempt', 0)->findAll($limit);
    }

    /**
     * Shared shape of both rosters — same columns, same order, one place.
     */
    private function rosterQuery(): static
    {
        return $this->select('id, name, email, work_schedule_id')
            ->where('status', 1)
            ->orderBy('name', 'ASC');
    }

    /**
     * Active users who hold `$permission` — the digest recipients (card 01d).
     *
     * Mirrors `user_can()`'s semantics exactly: an admin-zone user is an implicit
     * super-user and always qualifies, everyone else needs a role that grants the
     * named permission. Two rules in two places would eventually disagree about
     * who is a manager.
     *
     * @return list<object>
     */
    public function withPermission(string $permission): array
    {
        return $this->select('users.id, users.name, users.email')
            ->where('users.status', 1)
            ->groupStart()
            ->where('users.zone', 'admin')
            ->orWhereIn('users.role_id', static fn (BaseBuilder $builder) => $builder
                ->select('role_permissions.role_id')
                ->from('role_permissions')
                ->join('permissions', 'permissions.id = role_permissions.permission_id')
                ->where('permissions.name', $permission))
            ->groupEnd()
            ->orderBy('users.name', 'ASC')
            ->findAll();
    }

    /**
     * `userId => work_schedule_id` for many employees in one query — the bulk
     * lookup `AttendanceCalendar::warmSchedules()` needs so a monthly report
     * does not read one `users` row per person.
     *
     * @param list<int> $ids
     *
     * @return array<int, int|null>
     */
    public function scheduleAssignments(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->select('id, work_schedule_id')->whereIn('id', $ids)->findAll();

        return array_reduce($rows, static function (array $carry, object $row): array {
            $carry[(int) $row->id] = ($row->work_schedule_id ?? null) === null ? null : (int) $row->work_schedule_id;

            return $carry;
        }, []);
    }

    /**
     * Count of active (`status=1`), non-soft-deleted `zone=admin` users —
     * backs the last-admin lockout guard in `Admin\Users::update()` so an
     * admin can never demote/deactivate the last remaining admin.
     */
    public function countActiveAdmins(): int
    {
        return $this->where('zone', 'admin')->where('status', 1)->countAllResults();
    }

    /**
     * The one place `keyword`/`zone`/`status` get translated into WHERE
     * clauses for the admin list — shared by `searchAdmin()`/`countAdmin()`
     * so the paginated rows and the total can't drift apart.
     *
     * @param array{keyword?: string, zone?: string, status?: int|string} $filters
     */
    private function applyAdminFilters(BaseBuilder $builder, array $filters): void
    {
        $zone = (string) ($filters['zone'] ?? '');
        if (in_array($zone, ['admin', 'user'], true)) {
            $builder->where('zone', $zone);
        }

        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, ['0', '1'], true)) {
            $builder->where('status', (int) $status);
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword === '') {
            return;
        }

        $builder->groupStart();

        foreach (self::KEYWORD_FIELDS as $i => $field) {
            $i === 0 ? $builder->like($field, $keyword) : $builder->orLike($field, $keyword);
        }
        $builder->groupEnd();
    }
}
