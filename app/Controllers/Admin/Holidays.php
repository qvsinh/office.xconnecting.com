<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\HolidayModel;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\I18n\Time;
use Throwable;

/**
 * Company holiday list (`holidays`) — so an empty attendance row on Tết reads as
 * `holiday`, not `absent`. Gated by `authFilter` + `permission:attendance.manage`.
 *
 * Bulk add exists because Vietnamese public holidays are lunar and announced
 * annually: an admin pastes the whole year in one go rather than clicking twelve
 * times.
 */
class Holidays extends BaseController
{
    /**
     * Upper bound on one bulk paste — enough for any year's holiday list plus
     * compensating days, small enough that a pasted spreadsheet can't blow up
     * the request.
     */
    private const MAX_BULK_LINES = 100;

    public function index(): string
    {
        helper('attendance');

        $year = (int) ($this->request->getGet('year') ?: substr(attendance_today(), 0, 4));

        return view('admin/holidays/index', [
            'items' => model(HolidayModel::class)->forYear($year),
            'year'  => $year,
        ]);
    }

    /**
     * Add one or many holidays. The textarea accepts `YYYY-MM-DD  Name` per line;
     * dates that already exist are reported rather than silently swallowed or
     * failing the whole batch on the unique key.
     */
    public function create(): ResponseInterface
    {
        $parsed = $this->parseBulk((string) $this->request->getPost('bulk'));

        if (is_string($parsed)) {
            return $this->jsonFailed($parsed, 400);
        }

        $model    = model(HolidayModel::class);
        $existing = $model->existingDates(array_keys($parsed));
        $toInsert = [];
        $now      = date('Y-m-d H:i:s');

        foreach ($parsed as $date => $name) {
            if (in_array($date, $existing, true)) {
                continue;
            }

            $toInsert[] = [
                'holiday_date' => $date,
                'name'         => $name,
                'created_at'   => $now,
            ];
        }

        if ($toInsert !== []) {
            $model->insertBatch($toInsert);
        }

        $added   = count($toInsert);
        $skipped = count($existing);

        $message = "{$added} holiday(s) added.";

        if ($skipped > 0) {
            $message .= " {$skipped} already existed and were left unchanged.";
        }

        return $this->jsonSuccess($message, ['added' => $added, 'skipped' => $skipped]);
    }

    public function delete(): ResponseInterface
    {
        $model = model(HolidayModel::class);
        $id    = (int) $this->request->getPost('id');

        if ($model->findHoliday($id) === null) {
            return $this->jsonFailed('Holiday not found.', 404);
        }

        $model->delete($id);

        return $this->jsonSuccess('Holiday has been deleted successfully.');
    }

    /**
     * Parse the bulk textarea into `['Y-m-d' => 'Name']`.
     *
     * Every line must carry a real calendar date — `2026-02-30` is rejected
     * rather than quietly stored, because a bogus holiday would mark a working
     * day off for the whole company.
     *
     * @return array<string, string>|string the parsed map, or an error message
     */
    private function parseBulk(string $bulk): array|string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $bulk) ?: []), static fn (string $l): bool => $l !== ''));

        if ($lines === []) {
            return 'Enter at least one holiday.';
        }

        if (count($lines) > self::MAX_BULK_LINES) {
            return 'Too many lines — add at most ' . self::MAX_BULK_LINES . ' holidays at a time.';
        }

        $parsed = [];

        foreach ($lines as $line) {
            // `YYYY-MM-DD` then the rest of the line as the name.
            if (preg_match('/^(\d{4}-\d{2}-\d{2})[\s,;\-]*(.*)$/', $line, $matches) !== 1) {
                return "Could not read \"{$line}\" — use: 2026-09-02 National Day";
            }

            [, $date, $name] = $matches;

            if (! $this->isRealDate($date)) {
                return "\"{$date}\" is not a real date.";
            }

            $name = trim($name);

            if ($name === '') {
                return "Give \"{$date}\" a name.";
            }

            $parsed[$date] = mb_substr($name, 0, 100);
        }

        return $parsed;
    }

    private function isRealDate(string $date): bool
    {
        try {
            return Time::parse($date, attendance_tz())->toDateString() === $date;
        } catch (Throwable) {
            return false;
        }
    }
}
