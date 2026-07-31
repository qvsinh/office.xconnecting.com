<?php
/**
 * Employee self-service attendance: today's check-in/check-out card plus the
 * personal history table. Reuses the shared layout, the existing button/form
 * classes and the shared toast component — no new tokens, no new libraries.
 *
 * Hallmark · component: attendance-card + history-table · genre: modern-minimal
 * states: default · hover · focus · active · disabled · loading · error · success
 * (disabled covers "off the office network" and "already recorded"; error is
 * surfaced via the shared toast + the inline alert; no fabricated values —
 * an unknown duration renders as an em dash, never as a guess.)
 *
 * All times arrive pre-formatted in Vietnam time from the controller; the view
 * does no timezone maths.
 *
 * @var object|null           $today         today's attendance_days row (UTC columns)
 * @var string                $todayDate     Vietnam calendar date (Y-m-d)
 * @var list<object>          $history       newest-first rows for this user
 * @var int                   $total
 * @var int                   $page
 * @var int                   $perPage
 * @var bool                  $ipAllowed     may this address record attendance
 * @var bool                  $ipEnforced    is the office-IP restriction switched on
 * @var bool                  $impersonating is an admin currently acting as this user
 * @var array<string, object> $evaluations AttendanceCalendar results keyed by work_date
 */
$this->extend('layouts/app');

helper('attendance');

$totalPages = max(1, (int) ceil($total / max($perPage, 1)));

// State for the Alpine card, NOT display text: `null` means "hasn't happened",
// and that is what decides which button is shown. Never use
// `attendance_local_time()` here — its `'—'` fallback is a *display* string and
// is truthy in JavaScript, which hides "Check in" and offers "Check out" to
// somebody who has not arrived yet. The dash is added at render time below.
$initial = [
    'checkin_at'  => attendance_local($today->checkin_at ?? null)?->format('H:i'),
    'checkout_at' => attendance_local($today->checkout_at ?? null)?->format('H:i'),
    'worked'      => attendance_format_duration(attendance_worked_seconds($today->checkin_at ?? null, $today->checkout_at ?? null)),
];

// One reason string, one disabled flag — the button never has to re-derive why
// it is off, and the card and the banner can't disagree about it.
$blockedReason = null;
if ($impersonating) {
    $blockedReason = 'You are acting as another user. Stop acting as them to record your own attendance.';
} elseif (! $ipAllowed) {
    $blockedReason = 'You are not on the office network, so attendance cannot be recorded from here.';
}
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-5xl" x-data="attendanceCard(<?= esc(json_encode($initial), 'attr') ?>)">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">My attendance</h1>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">
                Check in when you arrive and check out when you leave. Times shown in Vietnam time (UTC+7).
            </p>
        </div>
        <p class="text-sm text-[#787774] dark:text-gray-400"><?= esc($todayDate) ?></p>
    </div>

    <?php if ($blockedReason !== null) : ?>
        <div class="mt-6 flex items-start gap-3 rounded-lg border border-[#E5C07B] bg-[#FDF6E7] p-4 text-sm text-[#7A5B14] dark:border-amber-700/60 dark:bg-amber-950/40 dark:text-amber-200"
             role="alert">
            <svg xmlns="http://www.w3.org/2000/svg" class="mt-0.5 h-5 w-5 flex-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" />
            </svg>
            <p><?= esc($blockedReason) ?></p>
        </div>
    <?php endif ?>

    <!-- Today's card. One element, four resting states: not started, checked in, complete, blocked. -->
    <div class="mt-6 rounded-lg border border-[#EAEAEA] bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">

            <dl class="grid grid-cols-2 gap-x-8 gap-y-4 sm:grid-cols-3">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Checked in</dt>
                    <dd class="mt-1 text-2xl font-semibold tabular-nums" x-text="day.checkin_at ?? '—'">—</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Checked out</dt>
                    <dd class="mt-1 text-2xl font-semibold tabular-nums" x-text="day.checkout_at ?? '—'">—</dd>
                </div>
                <div class="col-span-2 sm:col-span-1">
                    <dt class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Worked</dt>
                    <dd class="mt-1 text-2xl font-semibold tabular-nums" x-text="day.worked">—</dd>
                </div>
            </dl>

            <div class="flex flex-none flex-col items-stretch gap-2 sm:items-end">
                <?php if ($blockedReason !== null) : ?>
                    <button type="button" class="btn-primary whitespace-nowrap" disabled
                            title="<?= esc($blockedReason, 'attr') ?>">
                        Unavailable here
                    </button>
                <?php else : ?>
                    <button type="button" class="btn-primary whitespace-nowrap"
                            x-show="!day.checkin_at" x-cloak
                            :disabled="submitting" @click="checkin()">
                        <span x-show="!submitting">Check in</span>
                        <span x-show="submitting" x-cloak>Checking in…</span>
                    </button>

                    <button type="button" class="btn-primary whitespace-nowrap"
                            x-show="day.checkin_at" x-cloak
                            :disabled="submitting" @click="checkout()">
                        <span x-show="!submitting" x-text="day.checkout_at ? 'Update check-out' : 'Check out'">Check out</span>
                        <span x-show="submitting" x-cloak>Saving…</span>
                    </button>
                <?php endif ?>

                <p class="text-xs text-[#787774] dark:text-gray-400" x-show="day.checkout_at" x-cloak>
                    Checked out already — pressing again replaces the time.
                </p>
            </div>
        </div>

        <?php if ($ipEnforced && $ipAllowed) : ?>
            <p class="mt-4 border-t border-[#EAEAEA] pt-4 text-xs text-[#787774] dark:border-gray-700 dark:text-gray-400">
                Your address is on the office network allowlist.
            </p>
        <?php endif ?>
    </div>

    <?php if ($today !== null && (int) $today->missing_checkout === 1) : ?>
        <div class="mt-4 rounded-lg border border-[#E5C07B] bg-[#FDF6E7] p-4 text-sm text-[#7A5B14] dark:border-amber-700/60 dark:bg-amber-950/40 dark:text-amber-200" role="alert">
            No check-out was recorded for this day. Check out now, or ask your manager to correct it.
        </div>
    <?php endif ?>

    <h2 class="mt-10 text-base font-semibold tracking-tight">History</h2>

    <div class="mt-3 overflow-x-auto rounded-lg border border-[#EAEAEA] bg-white dark:border-gray-700 dark:bg-gray-800">
        <table class="min-w-full divide-y divide-[#EAEAEA] text-sm dark:divide-gray-700">
            <thead>
                <tr class="text-left text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">
                    <th scope="col" class="px-4 py-3">Date</th>
                    <th scope="col" class="px-4 py-3">Check-in</th>
                    <th scope="col" class="px-4 py-3">Check-out</th>
                    <th scope="col" class="hidden px-4 py-3 sm:table-cell">Worked</th>
                    <th scope="col" class="px-4 py-3">Status</th>
                    <th scope="col" class="hidden px-4 py-3 sm:table-cell">Note</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#EAEAEA] dark:divide-gray-700">
                <?php foreach ($history as $row) : ?>
                    <tr>
                        <td class="whitespace-nowrap px-4 py-3 font-medium"><?= esc($row->work_date) ?></td>
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums"><?= esc(attendance_local_time($row->checkin_at)) ?></td>
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums">
                            <?= esc(attendance_local_time($row->checkout_at)) ?>
                            <?php if ((int) $row->missing_checkout === 1) : ?>
                                <span class="ml-1 rounded-full border border-[#E5C07B] px-2 py-0.5 text-xs font-medium text-[#7A5B14] dark:border-amber-700/60 dark:text-amber-200">missing</span>
                            <?php endif ?>
                        </td>
                        <td class="hidden whitespace-nowrap px-4 py-3 tabular-nums sm:table-cell"><?= esc(attendance_format_duration($evaluations[$row->work_date]->worked_seconds ?? attendance_worked_seconds($row->checkin_at, $row->checkout_at))) ?></td>
                        <td class="whitespace-nowrap px-4 py-3"><?= view('attendance/_status_pill', ['day' => $evaluations[$row->work_date] ?? null]) ?></td>
                        <td class="hidden px-4 py-3 text-[#787774] sm:table-cell dark:text-gray-400">
                            <?= esc((string) ($row->note ?? '—')) ?>
                            <?php if ($row->edited_at !== null) : ?>
                                <span class="ml-1 rounded-full bg-[#F7F6F3] px-2 py-0.5 text-xs font-medium text-[#787774] dark:bg-gray-700 dark:text-gray-300">corrected</span>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                <?php if ($history === []) : ?>
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-sm text-[#787774] dark:text-gray-400">
                            No attendance recorded yet. Your first check-in will show up here.
                        </td>
                    </tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1) : ?>
        <div class="mt-4 flex items-center justify-between text-sm text-[#787774] dark:text-gray-400">
            <p><?= esc((string) $total) ?> day<?= $total === 1 ? '' : 's' ?> recorded</p>
            <div class="flex items-center gap-2">
                <?php if ($page > 1) : ?>
                    <a href="?page=<?= esc((string) ($page - 1), 'attr') ?>" class="rounded-md border border-[#EAEAEA] px-3 py-1.5 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-700">Prev</a>
                <?php endif ?>
                <span>Page <?= esc((string) $page) ?> of <?= esc((string) $totalPages) ?></span>
                <?php if ($page < $totalPages) : ?>
                    <a href="?page=<?= esc((string) ($page + 1), 'attr') ?>" class="rounded-md border border-[#EAEAEA] px-3 py-1.5 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-700">Next</a>
                <?php endif ?>
            </div>
        </div>
    <?php endif ?>

    <script>
        function attendanceCard(initial) {
            return {
                submitting: false,
                day: initial,

                async checkin() {
                    await this.record('/attendance/checkin', 'Could not check you in.');
                },

                async checkout() {
                    await this.record('/attendance/checkout', 'Could not check you out.');
                },

                // Both buttons share one path: the server is the only thing that
                // decides whether the action was allowed, and the card re-renders
                // from the row it returns rather than guessing locally.
                async record(url, fallbackMessage) {
                    if (this.submitting) return;
                    this.submitting = true;
                    // Screen size is the one piece of device context the server
                    // cannot see; the rest is read from the request headers.
                    const json = await postForm(url, { client_screen: screen.width + 'x' + screen.height });
                    this.submitting = false;
                    if (!json) return;

                    if (json.status === 'success' && json.day) {
                        this.day = json.day;
                        this.$dispatch('toast', { type: 'success', text: json.message });
                        return;
                    }

                    this.$dispatch('toast', { type: 'error', text: json.message || fallbackMessage });
                },
            };
        }
    </script>
</div>
<?php $this->endSection() ?>
