<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Over `work_schedules` — the expected working day (start/end, lunch window,
 * lateness grace, which weekdays) that attendance is measured against.
 *
 * Company baseline: 08:30–18:00, 90-minute lunch from 12:00, Mon–Fri = 8h net.
 */
class WorkScheduleModel extends Model
{
    public const SORT_ALLOWLIST = ['id', 'name', 'start_time', 'end_time', 'is_default'];

    protected $table            = 'work_schedules';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'name', 'start_time', 'end_time', 'break_start', 'break_minutes',
        'grace_minutes', 'workdays', 'is_default',
    ];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Typed wrapper over `find()` (whose generic signature phpstan can't narrow
     * to `object` from `$returnType`).
     */
    public function findSchedule(int $id): ?object
    {
        return $this->find($id);
    }

    /**
     * @return list<object>
     */
    public function allSchedules(): array
    {
        return $this->orderBy('is_default', 'DESC')->orderBy('name', 'ASC')->findAll();
    }

    /**
     * Several schedules by id, keyed by id — the bulk counterpart to
     * `findSchedule()` for a report covering employees on different shifts.
     *
     * @param list<int> $ids
     *
     * @return array<int, object>
     */
    public function findManyById(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->whereIn('id', $ids)->findAll();

        return array_reduce($rows, static function (array $carry, object $row): array {
            $carry[(int) $row->id] = $row;

            return $carry;
        }, []);
    }

    /**
     * The fallback schedule for employees with no `work_schedule_id`. Falls back
     * to the lowest id if no row is flagged default, so a mis-edited flag can
     * never leave the whole company without a baseline.
     */
    public function defaultSchedule(): ?object
    {
        return $this->orderBy('is_default', 'DESC')->orderBy('id', 'ASC')->first();
    }

    /**
     * Promote one row to default and demote every other in the same statement
     * pair — "exactly one default" is an invariant, not a hope.
     */
    public function makeDefault(int $id): void
    {
        $this->where('id !=', $id)->set('is_default', 0)->update();
        $this->update($id, ['is_default' => 1]);
    }

    /**
     * Employees pointing at this schedule — a schedule with dependants must not
     * be deleted out from under them.
     */
    public function usageCount(int $id): int
    {
        return $this->db->table('users')
            ->where('work_schedule_id', $id)
            ->where('deleted_at', null)
            ->countAllResults();
    }
}
