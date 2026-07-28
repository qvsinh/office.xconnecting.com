<?php
/**
 * One employee's access history.
 *
 * Leads with **distinct devices** rather than a chronological dump, because the
 * question this page exists to answer is "is somebody else using this account?"
 * — and that is a question about how many different machines appear, not about
 * the order events happened in.
 *
 * Hallmark · component: device-list + event-table · genre: modern-minimal
 * states: default · hover · focus · active (read-only page)
 *
 * @var object       $employee
 * @var list<object> $devices  ip/browser/platform/device_type/screen/last_seen/events
 * @var list<object> $items    the 50 most recent events
 * @var int          $total
 */
$this->extend('layouts/app');

helper('attendance');
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-5xl">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight"><?= esc((string) ($employee->name ?? $employee->email)) ?></h1>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">
                Access history · <?= esc((string) $employee->email) ?> · <?= esc((string) $total) ?> event<?= $total === 1 ? '' : 's' ?> recorded
            </p>
        </div>
        <a href="/admin/access-logs" class="btn-secondary whitespace-nowrap">All employees</a>
    </div>

    <h2 class="mt-8 text-base font-semibold tracking-tight">Devices seen</h2>
    <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">
        One row per distinct address + device. Several unfamiliar rows appearing at once is the signal worth acting on.
    </p>

    <div class="mt-3 overflow-x-auto rounded-lg border border-[#EAEAEA] bg-white dark:border-gray-700 dark:bg-gray-800">
        <table class="min-w-full divide-y divide-[#EAEAEA] text-sm dark:divide-gray-700">
            <thead>
                <tr class="text-left text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">
                    <th scope="col" class="px-4 py-3">Address</th>
                    <th scope="col" class="px-4 py-3">Device</th>
                    <th scope="col" class="px-4 py-3">Browser</th>
                    <th scope="col" class="px-4 py-3">OS</th>
                    <th scope="col" class="px-4 py-3">Screen</th>
                    <th scope="col" class="px-4 py-3 text-right">Events</th>
                    <th scope="col" class="px-4 py-3">Last seen</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#EAEAEA] dark:divide-gray-700">
                <?php foreach ($devices as $device) : ?>
                    <tr>
                        <td class="whitespace-nowrap px-4 py-3 font-medium tabular-nums"><?= esc((string) ($device->ip ?? '—')) ?></td>
                        <td class="whitespace-nowrap px-4 py-3"><?= esc((string) ($device->device_type ?? '—')) ?></td>
                        <td class="whitespace-nowrap px-4 py-3"><?= esc((string) ($device->browser ?? '—')) ?></td>
                        <td class="whitespace-nowrap px-4 py-3"><?= esc((string) ($device->platform ?? '—')) ?></td>
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums">
                            <?= $device->screen_width === null ? '—' : esc($device->screen_width . ' × ' . $device->screen_height) ?>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums"><?= esc((string) $device->events) ?></td>
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums text-[#787774] dark:text-gray-400">
                            <?= esc((string) (attendance_local($device->last_seen)?->format('Y-m-d H:i') ?? '—')) ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                <?php if ($devices === []) : ?>
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-sm text-[#787774] dark:text-gray-400">
                            Nothing recorded for this employee yet.
                        </td>
                    </tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>

    <h2 class="mt-10 text-base font-semibold tracking-tight">Recent events</h2>
    <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">The 50 most recent. Times in Vietnam time (UTC+7).</p>

    <div class="mt-3 overflow-x-auto rounded-lg border border-[#EAEAEA] bg-white dark:border-gray-700 dark:bg-gray-800">
        <table class="min-w-full divide-y divide-[#EAEAEA] text-sm dark:divide-gray-700">
            <thead>
                <tr class="text-left text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">
                    <th scope="col" class="px-4 py-3">When</th>
                    <th scope="col" class="px-4 py-3">Event</th>
                    <th scope="col" class="px-4 py-3">Address</th>
                    <th scope="col" class="px-4 py-3">Device</th>
                    <th scope="col" class="px-4 py-3">Browser</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#EAEAEA] dark:divide-gray-700">
                <?php foreach ($items as $item) : ?>
                    <tr>
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums">
                            <?= esc((string) (attendance_local($item->created_at)?->format('Y-m-d H:i') ?? '—')) ?>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3"><?= view('admin/access-logs/_event_pill', ['event' => $item->event]) ?></td>
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums"><?= esc((string) ($item->ip ?? '—')) ?></td>
                        <td class="whitespace-nowrap px-4 py-3"><?= esc((string) ($item->device_type ?? '—')) ?></td>
                        <td class="whitespace-nowrap px-4 py-3"><?= esc((string) ($item->browser ?? '—')) ?></td>
                    </tr>
                <?php endforeach ?>
                <?php if ($items === []) : ?>
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-sm text-[#787774] dark:text-gray-400">No events recorded.</td>
                    </tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>
</div>
<?php $this->endSection() ?>
