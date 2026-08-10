<?php
/**
 * Work schedules: the expected working day attendance is measured against.
 * Small CRUD table + a shared Alpine modal, reusing the existing button/form
 * classes and the shared toast/confirm components.
 *
 * Hallmark · component: crud-table + schedule-modal · genre: modern-minimal
 * states: default · hover · focus · active · disabled · loading · error · success
 *
 * @var list<object>    $items schedules, default first
 * @var array<int, int> $usage employee count per schedule id
 */
$this->extend('layouts/app');

$dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

$hhmm = static fn (?string $time): string => substr((string) $time, 0, 5);

/**
 * Net paid hours the schedule implies — the number an admin actually wants to
 * see when editing hours, rather than having to subtract the break in their head.
 */
$netHours = static function (object $schedule) use ($hhmm): string {
    $minutes = static function (string $time): int {
        [$h, $m] = array_pad(array_map('intval', explode(':', $time)), 2, 0);

        return $h * 60 + $m;
    };

    $net = $minutes($hhmm($schedule->end_time)) - $minutes($hhmm($schedule->start_time)) - (int) $schedule->break_minutes;

    return sprintf('%dh %02dm', intdiv(max(0, $net), 60), max(0, $net) % 60);
};
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-5xl" x-data="workSchedules()">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Work schedules</h1>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">
                The expected working day: arrival, departure, lunch break and which weekdays count.
                Lateness, absence and paid hours are all measured against this.
            </p>
        </div>
        <button type="button" @click="openCreate()" class="btn-primary whitespace-nowrap">+ Add schedule</button>
    </div>

    <p class="mt-4 text-sm text-[#787774] dark:text-gray-400">
        Editing a schedule also changes how <strong>past</strong> days are reported, because status and hours are
        derived, not stored. Close a month on the report page before changing hours if its figures have already been paid.
    </p>

    <div class="mt-6 overflow-x-auto rounded-lg border border-[#EAEAEA] bg-white dark:border-gray-700 dark:bg-gray-800">
        <table class="min-w-full divide-y divide-[#EAEAEA] text-sm dark:divide-gray-700">
            <thead>
                <tr class="text-left text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">
                    <th scope="col" class="px-4 py-3">Name</th>
                    <th scope="col" class="px-4 py-3">Hours</th>
                    <th scope="col" class="px-4 py-3">Lunch</th>
                    <th scope="col" class="px-4 py-3">Net / day</th>
                    <th scope="col" class="px-4 py-3">Grace</th>
                    <th scope="col" class="px-4 py-3">Working days</th>
                    <th scope="col" class="px-4 py-3">Employees</th>
                    <th scope="col" class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#EAEAEA] dark:divide-gray-700">
                <?php foreach ($items as $item) : ?>
                    <tr>
                        <td class="px-4 py-3 font-medium">
                            <?= esc($item->name) ?>
                            <?php if ((int) $item->is_default === 1) : ?>
                                <span class="ml-1 rounded-full bg-[#F7F6F3] px-2 py-0.5 text-xs font-medium uppercase tracking-wide text-[#787774] dark:bg-gray-700 dark:text-gray-300">default</span>
                            <?php endif ?>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums"><?= esc($hhmm($item->start_time)) ?>–<?= esc($hhmm($item->end_time)) ?></td>
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums"><?= esc($hhmm($item->break_start)) ?> · <?= esc((string) $item->break_minutes) ?> min</td>
                        <td class="whitespace-nowrap px-4 py-3 font-medium tabular-nums"><?= esc($netHours($item)) ?></td>
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums"><?= (int) $item->grace_minutes === 0 ? 'strict' : esc((string) $item->grace_minutes) . ' min' ?></td>
                        <td class="px-4 py-3">
                            <?php
                                $days = array_filter(array_map('intval', explode(',', (string) $item->workdays)));
                    echo esc(implode(', ', array_map(static fn (int $d): string => $dayNames[$d] ?? (string) $d, $days)));
                    ?>
                        </td>
                        <td class="px-4 py-3 tabular-nums"><?= esc((string) ($usage[(int) $item->id] ?? 0)) ?></td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            <button type="button" class="mr-3 text-sm font-medium text-[#111111] hover:underline dark:text-gray-100"
                                    @click="openEdit(<?= (int) $item->id ?>)">Edit</button>
                            <button type="button" class="text-sm font-medium text-[#9F2F2D] hover:underline dark:text-red-400"
                                    @click="confirmDelete(<?= (int) $item->id ?>, <?= esc(json_encode($item->name), 'attr') ?>)">Delete</button>
                        </td>
                    </tr>
                <?php endforeach ?>
                <?php if ($items === []) : ?>
                    <tr>
                        <td colspan="8" class="px-4 py-10 text-center text-sm text-[#787774] dark:text-gray-400">
                            No work schedule yet — add one so attendance has a baseline to measure against.
                        </td>
                    </tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>

    <!-- Create / edit share one modal: identical fields, one submit path. -->
    <div x-show="open" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center px-4"
         @keydown.escape.window="open = false">
        <div class="fixed inset-0 bg-black/40" @click="open = false"></div>
        <div x-show="open" x-transition class="modal-panel relative max-w-lg"
             x-trap.noscroll="open" role="dialog" aria-modal="true" aria-labelledby="schedule-modal-title">
            <button type="button" class="absolute right-4 top-4 rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:text-gray-500 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                    @click="open = false" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <h2 id="schedule-modal-title" class="pr-8 text-lg font-semibold text-gray-800 dark:text-white/90"
                x-text="form.id ? 'Edit work schedule' : 'Add work schedule'">Add work schedule</h2>
            <p x-show="loading" x-cloak class="mt-4 text-sm text-[#787774] dark:text-gray-400">Loading…</p>

            <form x-show="!loading" @submit.prevent="submit" class="mt-4 space-y-4">
                <div>
                    <label for="schedule_name" class="form-label">Name</label>
                    <input id="schedule_name" type="text" x-model="form.name" required maxlength="100" class="form-input">
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="schedule_start" class="form-label">Start</label>
                        <input id="schedule_start" type="time" x-model="form.start_time" required class="form-input">
                    </div>
                    <div>
                        <label for="schedule_end" class="form-label">End</label>
                        <input id="schedule_end" type="time" x-model="form.end_time" required class="form-input">
                    </div>
                    <div>
                        <label for="schedule_break_start" class="form-label">Lunch starts</label>
                        <input id="schedule_break_start" type="time" x-model="form.break_start" required class="form-input">
                    </div>
                    <div>
                        <label for="schedule_break_minutes" class="form-label">Lunch (minutes)</label>
                        <input id="schedule_break_minutes" type="number" min="0" max="600" x-model="form.break_minutes" required class="form-input">
                    </div>
                </div>

                <p class="text-sm text-[#787774] dark:text-gray-400">
                    Net paid per day: <strong class="tabular-nums text-[#111111] dark:text-gray-100" x-text="netPerDay()">—</strong>
                </p>

                <div>
                    <label for="schedule_grace" class="form-label">Lateness tolerance (minutes)</label>
                    <input id="schedule_grace" type="number" min="0" max="240" x-model="form.grace_minutes" class="form-input">
                    <p class="mt-1 text-xs text-[#787774] dark:text-gray-400">
                        0 = strict. Tolerance decides whether a day is flagged late; the minutes reported always
                        count from the official start time.
                    </p>
                </div>

                <fieldset>
                    <legend class="form-label">Working days</legend>
                    <div class="mt-1 flex flex-wrap gap-3">
                        <?php foreach ($dayNames as $number => $label) : ?>
                            <label class="flex items-center gap-1.5 text-sm">
                                <input type="checkbox" value="<?= esc((string) $number, 'attr') ?>" x-model="form.workdays"
                                       class="h-4 w-4 rounded border-[#EAEAEA] dark:border-gray-600">
                                <?= esc($label) ?>
                            </label>
                        <?php endforeach ?>
                    </div>
                </fieldset>

                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" x-model="form.is_default" class="h-4 w-4 rounded border-[#EAEAEA] dark:border-gray-600">
                    Use as the default schedule for employees with none assigned
                </label>

                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" class="btn-secondary" @click="open = false">Cancel</button>
                    <button type="submit" :disabled="saving" class="btn-primary">
                        <span x-show="!saving">Save</span>
                        <span x-show="saving" x-cloak>Saving…</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function workSchedules() {
            return {
                open: false,
                loading: false,
                saving: false,
                // Alpine calls init() during component setup, before the tree's
                // directives are evaluated — see the note in CLAUDE.md.
                form: {},

                init() {
                    this.form = this.blank();
                },

                blank() {
                    return {
                        id: null, name: '', start_time: '08:30', end_time: '18:00',
                        break_start: '12:00', break_minutes: 90, grace_minutes: 0,
                        workdays: ['1', '2', '3', '4', '5'], is_default: false,
                    };
                },

                // Mirrors the server's expectedSeconds(): span minus the whole break.
                netPerDay() {
                    const toMinutes = (t) => {
                        const [h, m] = String(t || '0:0').split(':').map(Number);
                        return (h || 0) * 60 + (m || 0);
                    };
                    const net = toMinutes(this.form.end_time) - toMinutes(this.form.start_time) - Number(this.form.break_minutes || 0);
                    if (!(net > 0)) return '—';
                    return `${Math.floor(net / 60)}h ${String(net % 60).padStart(2, '0')}m`;
                },

                openCreate() {
                    this.form = this.blank();
                    this.open = true;
                },

                async openEdit(id) {
                    this.open = true;
                    this.loading = true;
                    let json;
                    try {
                        const res = await fetch('/admin/attendance/schedules/edit/' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                        json = await res.json();
                    } catch (e) {
                        this.open = false;
                        this.$dispatch('toast', { type: 'error', text: 'Network error. Please try again.' });
                        return;
                    }
                    this.loading = false;
                    if (json.status !== 'success') {
                        this.open = false;
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Schedule not found.' });
                        return;
                    }
                    this.form = { ...json.schedule, workdays: json.schedule.workdays.map(String) };
                },

                async submit() {
                    this.saving = true;
                    // postForm() cannot send array fields — send workdays as CSV
                    // and explode() server-side (see CLAUDE.md / Board::reorderColumns).
                    const payload = {
                        ...this.form,
                        workdays: this.form.workdays.join(','),
                        is_default: this.form.is_default ? '1' : '0',
                    };
                    const url = this.form.id ? '/admin/attendance/schedules/update' : '/admin/attendance/schedules/create';
                    const json = await postForm(url, payload);
                    this.saving = false;
                    if (!json) return;
                    if (json.status === 'success') {
                        this.open = false;
                        this.$dispatch('toast', { type: 'success', text: json.message });
                        window.location.reload();
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not save the schedule.' });
                    }
                },

                confirmDelete(id, name) {
                    this.$dispatch('confirm', {
                        title: 'Delete work schedule',
                        body: 'This will remove "' + name + '". Employees using it must be reassigned first.',
                        variant: 'danger',
                        confirmLabel: 'Delete',
                        onConfirm: async () => {
                            const json = await postForm('/admin/attendance/schedules/delete', { id });
                            if (!json) return;
                            if (json.status === 'success') {
                                this.$dispatch('toast', { type: 'success', text: json.message });
                                window.location.reload();
                            } else {
                                this.$dispatch('toast', { type: 'error', text: json.message || 'Could not delete the schedule.' });
                            }
                        },
                    });
                },
            };
        }
    </script>
</div>
<?php $this->endSection() ?>
