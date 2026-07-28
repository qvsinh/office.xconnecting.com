<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\WorkScheduleModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Work-schedule CRUD (`work_schedules`): the expected working day attendance is
 * measured against — start/end, lunch window, lateness tolerance, which
 * weekdays. Gated by `authFilter` + `permission:attendance.manage`.
 *
 * Company baseline (seeded): 08:30–18:00, 90-minute lunch from 12:00, Mon–Fri.
 */
class WorkSchedules extends BaseController
{
    /**
     * ISO-8601 weekday numbers, 1 = Monday — the only values `workdays` accepts.
     */
    private const WEEKDAYS = ['1', '2', '3', '4', '5', '6', '7'];

    public function index(): string
    {
        $model = model(WorkScheduleModel::class);
        $items = $model->allSchedules();
        $usage = [];

        foreach ($items as $item) {
            $usage[(int) $item->id] = $model->usageCount((int) $item->id);
        }

        return view('admin/work-schedules/index', [
            'items' => $items,
            'usage' => $usage,
        ]);
    }

    public function create(): ResponseInterface
    {
        if (! $this->validate($this->rules(false))) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $workdays = $this->resolveWorkdays();

        if (is_string($workdays)) {
            return $this->jsonFailed($workdays, 400);
        }

        $times = $this->validateTimes();

        if (is_string($times)) {
            return $this->jsonFailed($times, 400);
        }

        $model = model(WorkScheduleModel::class);
        $id    = (int) $model->insert($times + $workdays + [
            'name'          => trim((string) $this->request->getPost('name')),
            'grace_minutes' => (int) $this->request->getPost('grace_minutes'),
        ], true);

        // First schedule on a fresh install becomes the default automatically —
        // an employee roster with no default schedule has nothing to measure
        // against, which is worse than a possibly-wrong default.
        if ($this->request->getPost('is_default') === '1' || $model->defaultSchedule() === null) {
            $model->makeDefault($id);
        }

        return $this->jsonSuccess('Work schedule has been created successfully.');
    }

    /**
     * JSON schedule record for the edit modal (AJAX-fetched).
     */
    public function edit(int $id): ResponseInterface
    {
        $row = model(WorkScheduleModel::class)->findSchedule($id);

        if ($row === null) {
            return $this->jsonFailed('Work schedule not found.', 404);
        }

        return $this->jsonSuccess('OK', [
            'schedule' => [
                'id'            => (int) $row->id,
                'name'          => $row->name,
                'start_time'    => substr((string) $row->start_time, 0, 5),
                'end_time'      => substr((string) $row->end_time, 0, 5),
                'break_start'   => substr((string) $row->break_start, 0, 5),
                'break_minutes' => (int) $row->break_minutes,
                'grace_minutes' => (int) $row->grace_minutes,
                'workdays'      => explode(',', (string) $row->workdays),
                'is_default'    => (int) $row->is_default === 1,
            ],
        ]);
    }

    public function update(): ResponseInterface
    {
        if (! $this->validate($this->rules(true))) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $model = model(WorkScheduleModel::class);
        $id    = (int) $this->request->getPost('id');

        if ($model->findSchedule($id) === null) {
            return $this->jsonFailed('Work schedule not found.', 404);
        }

        $workdays = $this->resolveWorkdays();

        if (is_string($workdays)) {
            return $this->jsonFailed($workdays, 400);
        }

        $times = $this->validateTimes();

        if (is_string($times)) {
            return $this->jsonFailed($times, 400);
        }

        $model->update($id, $times + $workdays + [
            'name'          => trim((string) $this->request->getPost('name')),
            'grace_minutes' => (int) $this->request->getPost('grace_minutes'),
        ]);

        if ($this->request->getPost('is_default') === '1') {
            $model->makeDefault($id);
        }

        return $this->jsonSuccess('Work schedule has been updated successfully.');
    }

    public function delete(): ResponseInterface
    {
        $model = model(WorkScheduleModel::class);
        $id    = (int) $this->request->getPost('id');
        $row   = $model->findSchedule($id);

        if ($row === null) {
            return $this->jsonFailed('Work schedule not found.', 404);
        }

        $usage = $model->usageCount($id);

        if ($usage > 0) {
            return $this->jsonFailed("{$usage} employee(s) still use this schedule. Reassign them first.", 409);
        }

        // Deleting the only schedule would leave every employee unmeasurable.
        if (count($model->allSchedules()) === 1) {
            return $this->jsonFailed('This is the only work schedule — create another one before deleting it.', 409);
        }

        $model->delete($id);

        // Never leave the company without a default.
        if ((int) $row->is_default === 1) {
            $fallback = $model->defaultSchedule();

            if ($fallback !== null) {
                $model->makeDefault((int) $fallback->id);
            }
        }

        return $this->jsonSuccess('Work schedule has been deleted successfully.');
    }

    /**
     * `workdays` arrives as a CSV string, not an array: `postForm()` (the shared
     * layout AJAX helper) cannot send array fields — see the note in CLAUDE.md
     * and `Board::reorderColumns`.
     *
     * Returns `['workdays' => csv]` on success or an error message — a plain
     * string return for both would be indistinguishable at the call site (and
     * was: the success CSV was being surfaced to the user as a validation
     * error, and the time checks below it never ran).
     *
     * @return array{workdays: string}|string
     */
    private function resolveWorkdays(): array|string
    {
        $raw   = trim((string) $this->request->getPost('workdays'));
        $parts = array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)), static fn (string $p): bool => $p !== '')));

        if ($parts === []) {
            return 'Pick at least one working day.';
        }

        foreach ($parts as $part) {
            if (! in_array($part, self::WEEKDAYS, true)) {
                return 'Working days must be weekday numbers 1 (Monday) to 7 (Sunday).';
            }
        }

        sort($parts);

        return ['workdays' => implode(',', $parts)];
    }

    /**
     * The time fields, with the ordering rules the arithmetic depends on: a day
     * that ends before it starts, or a lunch break that falls outside the working
     * window, would silently produce nonsense hours.
     *
     * @return array{start_time: string, end_time: string, break_start: string, break_minutes: int}|string
     */
    private function validateTimes(): array|string
    {
        $start        = (string) $this->request->getPost('start_time');
        $end          = (string) $this->request->getPost('end_time');
        $breakStart   = (string) $this->request->getPost('break_start');
        $breakMinutes = (int) $this->request->getPost('break_minutes');

        if ($end <= $start) {
            return 'End time must be later than start time.';
        }

        if ($breakMinutes > 0) {
            $breakEndMinute = $this->minuteOfDay($breakStart) + $breakMinutes;

            if ($this->minuteOfDay($breakStart) < $this->minuteOfDay($start) || $breakEndMinute > $this->minuteOfDay($end)) {
                return 'The lunch break must fall inside the working window.';
            }

            if ($breakMinutes >= $this->minuteOfDay($end) - $this->minuteOfDay($start)) {
                return 'The lunch break cannot be as long as the working day.';
            }
        }

        return [
            'start_time'    => $start . ':00',
            'end_time'      => $end . ':00',
            'break_start'   => $breakStart . ':00',
            'break_minutes' => $breakMinutes,
        ];
    }

    private function minuteOfDay(string $time): int
    {
        [$hours, $minutes] = array_pad(array_map('intval', explode(':', $time)), 2, 0);

        return $hours * 60 + $minutes;
    }

    /**
     * @return array<string, string>
     */
    private function rules(bool $isUpdate): array
    {
        $timePattern = 'required|regex_match[/^([01][0-9]|2[0-3]):[0-5][0-9]$/]';

        $rules = [
            'name'          => 'required|max_length[100]',
            'start_time'    => $timePattern,
            'end_time'      => $timePattern,
            'break_start'   => $timePattern,
            'break_minutes' => 'required|is_natural|less_than_equal_to[600]',
            'grace_minutes' => 'permit_empty|is_natural|less_than_equal_to[240]',
            'workdays'      => 'required',
        ];

        if ($isUpdate) {
            $rules['id'] = 'required|is_natural_no_zero';
        }

        return $rules;
    }
}
