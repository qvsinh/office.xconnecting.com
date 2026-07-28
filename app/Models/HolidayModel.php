<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Over `holidays` — company non-working days, so a missing attendance row on a
 * public holiday reads as `holiday` rather than `absent`.
 */
class HolidayModel extends Model
{
    protected $table            = 'holidays';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = ['holiday_date', 'name'];
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';

    /**
     * Typed wrapper over `find()`.
     */
    public function findHoliday(int $id): ?object
    {
        return $this->find($id);
    }

    /**
     * @return list<object>
     */
    public function forYear(int $year): array
    {
        return $this->where('holiday_date >=', sprintf('%04d-01-01', $year))
            ->where('holiday_date <=', sprintf('%04d-12-31', $year))
            ->orderBy('holiday_date', 'ASC')
            ->findAll();
    }

    /**
     * `['Y-m-d' => 'name']` for a date range — the shape `AttendanceCalendar`
     * wants, loaded once per request rather than one query per day.
     *
     * @return array<string, string>
     */
    public function mapForRange(string $from, string $to): array
    {
        $rows = $this->where('holiday_date >=', $from)
            ->where('holiday_date <=', $to)
            ->findAll();

        return array_reduce($rows, static function (array $carry, object $row): array {
            $carry[(string) $row->holiday_date] = (string) $row->name;

            return $carry;
        }, []);
    }

    /**
     * Dates already present, so a bulk paste can report duplicates instead of
     * failing the whole batch on the unique key.
     *
     * @param list<string> $dates
     *
     * @return list<string>
     */
    public function existingDates(array $dates): array
    {
        if ($dates === []) {
            return [];
        }

        $rows = $this->select('holiday_date')->whereIn('holiday_date', $dates)->findAll();

        return array_map(static fn (object $row): string => (string) $row->holiday_date, $rows);
    }
}
