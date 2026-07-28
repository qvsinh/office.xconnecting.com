<?php
/**
 * Company holidays for one year. A missing attendance row on a listed date reads
 * as `holiday` instead of `absent`.
 *
 * Bulk paste rather than one-at-a-time: Vietnamese public holidays are lunar and
 * announced annually, so the whole year goes in at once.
 *
 * Hallmark · component: year-list + bulk-add · genre: modern-minimal
 * states: default · hover · focus · active · disabled · loading · error · success
 *
 * @var list<object> $items holidays in $year, ascending
 * @var int          $year
 */
$this->extend('layouts/app');

helper('attendance');

$currentYear = (int) substr(attendance_today(), 0, 4);
$dayName     = static fn (string $date): string => date('D', strtotime($date));
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-3xl" x-data="holidaysAdmin()">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Holidays</h1>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">
                Company non-working days. Attendance on these dates is never counted as absence.
            </p>
        </div>
        <button type="button" @click="open = true" class="btn-primary whitespace-nowrap">+ Add holidays</button>
    </div>

    <form method="get" class="mt-6 flex flex-wrap items-end gap-3 rounded-lg border border-[#EAEAEA] bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
        <div>
            <label for="year" class="form-label">Year</label>
            <select id="year" name="year" class="form-select" onchange="this.form.submit()">
                <?php for ($option = $currentYear - 2; $option <= $currentYear + 2; $option++) : ?>
                    <option value="<?= esc((string) $option, 'attr') ?>" <?= $option === $year ? 'selected' : '' ?>><?= esc((string) $option) ?></option>
                <?php endfor ?>
            </select>
        </div>
        <button type="submit" class="btn-secondary">Show</button>
    </form>

    <div class="mt-6 overflow-x-auto rounded-lg border border-[#EAEAEA] bg-white dark:border-gray-700 dark:bg-gray-800">
        <table class="min-w-full divide-y divide-[#EAEAEA] text-sm dark:divide-gray-700">
            <thead>
                <tr class="text-left text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">
                    <th scope="col" class="px-4 py-3">Date</th>
                    <th scope="col" class="px-4 py-3">Day</th>
                    <th scope="col" class="px-4 py-3">Name</th>
                    <th scope="col" class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#EAEAEA] dark:divide-gray-700">
                <?php foreach ($items as $item) : ?>
                    <tr>
                        <td class="whitespace-nowrap px-4 py-3 font-medium tabular-nums"><?= esc($item->holiday_date) ?></td>
                        <td class="whitespace-nowrap px-4 py-3 text-[#787774] dark:text-gray-400"><?= esc($dayName((string) $item->holiday_date)) ?></td>
                        <td class="px-4 py-3"><?= esc($item->name) ?></td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            <button type="button" class="text-sm font-medium text-[#9F2F2D] hover:underline dark:text-red-400"
                                    @click="confirmDelete(<?= (int) $item->id ?>, <?= esc(json_encode($item->holiday_date . ' — ' . $item->name), 'attr') ?>)">Delete</button>
                        </td>
                    </tr>
                <?php endforeach ?>
                <?php if ($items === []) : ?>
                    <tr>
                        <td colspan="4" class="px-4 py-10 text-center text-sm text-[#787774] dark:text-gray-400">
                            No holidays recorded for <?= esc((string) $year) ?>. Without them, a public holiday counts as absence.
                        </td>
                    </tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>

    <p class="mt-4 text-sm text-[#787774] dark:text-gray-400">
        <?= esc((string) count($items)) ?> holiday<?= count($items) === 1 ? '' : 's' ?> in <?= esc((string) $year) ?>.
    </p>

    <!-- Bulk add modal -->
    <div x-show="open" x-cloak class="fixed inset-0 z-40 flex items-center justify-center px-4"
         @keydown.escape.window="open = false">
        <div class="fixed inset-0 bg-black/40" @click="open = false"></div>
        <div x-show="open" x-transition class="modal-panel relative max-w-lg"
             x-trap.noscroll="open" role="dialog" aria-modal="true" aria-labelledby="holiday-modal-title">
            <button type="button" class="absolute right-4 top-4 rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:text-gray-500 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                    @click="open = false" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <h2 id="holiday-modal-title" class="pr-8 text-lg font-semibold text-gray-800 dark:text-white/90">Add holidays</h2>

            <form @submit.prevent="submit" class="mt-4 space-y-4">
                <div>
                    <label for="holiday_bulk" class="form-label">One per line: date then name</label>
                    <textarea id="holiday_bulk" x-model="bulk" rows="8" required class="form-textarea font-mono text-sm"
                              placeholder="2026-01-01 New Year's Day&#10;2026-02-17 Tết Nguyên Đán&#10;2026-04-30 Reunification Day&#10;2026-09-02 National Day"></textarea>
                    <p class="mt-1 text-xs text-[#787774] dark:text-gray-400">
                        Dates already listed are skipped, not duplicated. Up to 100 lines at a time.
                    </p>
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" class="btn-secondary" @click="open = false">Cancel</button>
                    <button type="submit" :disabled="saving" class="btn-primary">
                        <span x-show="!saving">Add</span>
                        <span x-show="saving" x-cloak>Adding…</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function holidaysAdmin() {
            return {
                open: false,
                saving: false,
                bulk: '',

                async submit() {
                    this.saving = true;
                    const json = await postForm('/admin/attendance/holidays/create', { bulk: this.bulk });
                    this.saving = false;
                    if (!json) return;
                    if (json.status === 'success') {
                        this.open = false;
                        this.bulk = '';
                        this.$dispatch('toast', { type: 'success', text: json.message });
                        window.location.reload();
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not add the holidays.' });
                    }
                },

                confirmDelete(id, label) {
                    this.$dispatch('confirm', {
                        title: 'Delete holiday',
                        body: 'This will remove "' + label + '". That date will count as a normal working day again.',
                        variant: 'danger',
                        confirmLabel: 'Delete',
                        onConfirm: async () => {
                            const json = await postForm('/admin/attendance/holidays/delete', { id });
                            if (!json) return;
                            if (json.status === 'success') {
                                this.$dispatch('toast', { type: 'success', text: json.message });
                                window.location.reload();
                            } else {
                                this.$dispatch('toast', { type: 'error', text: json.message || 'Could not delete the holiday.' });
                            }
                        },
                    });
                },
            };
        }
    </script>
</div>
<?php $this->endSection() ?>
