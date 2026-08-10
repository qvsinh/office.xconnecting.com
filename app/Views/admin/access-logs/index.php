<?php
/**
 * Access log: every login / check-in / check-out with where and on what.
 *
 * Hallmark · component: filter-table + detail-modal · genre: modern-minimal
 * states: default · hover · focus · active · loading · error
 * (read-only page — no mutations, so no success state; an unknown value renders
 * as an em dash rather than a guess)
 *
 * @var list<object>                                                                       $items     searchAdmin() rows (+ user_name/user_email)
 * @var int                                                                                $total
 * @var int                                                                                $page
 * @var int                                                                                $limit
 * @var string                                                                             $sort
 * @var string                                                                             $order
 * @var array{user_id:int, event:string, date_from:string, date_to:string, keyword:string} $filters
 * @var list<object>                                                                       $employees
 */

use App\Models\AccessLogModel;

$this->extend('layouts/app');

helper('attendance');

$totalPages = max(1, (int) ceil($total / max($limit, 1)));

$queryBase = [
    'user_id'   => $filters['user_id'] ?: '',
    'event'     => $filters['event'],
    'date_from' => $filters['date_from'],
    'date_to'   => $filters['date_to'],
    'keyword'   => $filters['keyword'],
    'sort'      => $sort,
    'order'     => $order,
    'limit'     => $limit,
];

$buildQuery = static fn (array $extra): string => '?' . http_build_query(
    array_filter($queryBase + $extra, static fn ($v): bool => $v !== '' && $v !== null),
);

$pageUrl = static fn (int $target): string => $buildQuery(['page' => $target]);
$sortUrl = static fn (string $col): string => $buildQuery([
    'sort'  => $col,
    'order' => ($sort === $col && strtolower($order) === 'asc') ? 'desc' : 'asc',
]);
$sortMark = static function (string $col) use ($sort, $order): string {
    if ($sort !== $col) {
        return '';
    }

    return strtolower($order) === 'asc' ? ' ▲' : ' ▼';
};

$deviceIcons = [
    'mobile'  => 'Mobile',
    'desktop' => 'Desktop',
    'robot'   => 'Bot',
];
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-7xl" x-data="accessLogs()">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Access log</h1>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">
                Logins and attendance actions, with the address and device each came from. Times in Vietnam time (UTC+7).
            </p>
        </div>
    </div>

    <div class="mt-4 rounded-lg border border-[#EAEAEA] bg-white p-4 text-sm text-[#787774] dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400">
        <p>
            <strong class="text-[#111111] dark:text-gray-100">How much to trust this.</strong>
            The browser, operating system and screen size are reported by the visitor's own device and can be
            faked — read them as "which machine was this, probably", never as proof. The address is only the
            visitor's real one while <code class="rounded bg-black/5 px-1 dark:bg-white/10">Config\App::$proxyIPs</code>
            matches how this site is served.
        </p>
    </div>

    <form method="get" class="mt-6 flex flex-wrap items-end gap-3 rounded-lg border border-[#EAEAEA] bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
        <div class="min-w-[200px] flex-1">
            <label for="keyword" class="form-label">Search</label>
            <input type="text" id="keyword" name="keyword" value="<?= esc($filters['keyword'], 'attr') ?>"
                   placeholder="Address, browser, OS or name" class="form-input">
        </div>
        <div class="min-w-[180px]">
            <label for="user_id" class="form-label">Employee</label>
            <select id="user_id" name="user_id" class="form-select">
                <option value="">Everyone</option>
                <?php foreach ($employees as $employee) : ?>
                    <option value="<?= esc((string) $employee->id, 'attr') ?>" <?= $filters['user_id'] === (int) $employee->id ? 'selected' : '' ?>>
                        <?= esc((string) ($employee->name ?? $employee->email)) ?>
                    </option>
                <?php endforeach ?>
            </select>
        </div>
        <div>
            <label for="event" class="form-label">Event</label>
            <select id="event" name="event" class="form-select">
                <option value="">All</option>
                <?php foreach (AccessLogModel::EVENTS as $event) : ?>
                    <option value="<?= esc($event, 'attr') ?>" <?= $filters['event'] === $event ? 'selected' : '' ?>><?= esc(ucfirst($event)) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div>
            <label for="date_from" class="form-label">From</label>
            <input type="date" id="date_from" name="date_from" value="<?= esc($filters['date_from'], 'attr') ?>" class="form-input">
        </div>
        <div>
            <label for="date_to" class="form-label">To</label>
            <input type="date" id="date_to" name="date_to" value="<?= esc($filters['date_to'], 'attr') ?>" class="form-input">
        </div>
        <button type="submit" class="btn-secondary">Filter</button>
        <a href="/admin/access-logs" class="btn-secondary">Clear</a>
    </form>

    <div class="mt-6 overflow-x-auto rounded-lg border border-[#EAEAEA] bg-white dark:border-gray-700 dark:bg-gray-800">
        <table class="min-w-full divide-y divide-[#EAEAEA] text-sm dark:divide-gray-700">
            <thead>
                <tr class="text-left text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">
                    <th scope="col" class="px-4 py-3"><a href="<?= esc($sortUrl('created_at'), 'attr') ?>" class="hover:text-[#111111] dark:hover:text-gray-100">When<?= esc($sortMark('created_at')) ?></a></th>
                    <th scope="col" class="px-4 py-3"><a href="<?= esc($sortUrl('user_name'), 'attr') ?>" class="hover:text-[#111111] dark:hover:text-gray-100">Employee<?= esc($sortMark('user_name')) ?></a></th>
                    <th scope="col" class="px-4 py-3"><a href="<?= esc($sortUrl('event'), 'attr') ?>" class="hover:text-[#111111] dark:hover:text-gray-100">Event<?= esc($sortMark('event')) ?></a></th>
                    <th scope="col" class="px-4 py-3"><a href="<?= esc($sortUrl('ip'), 'attr') ?>" class="hover:text-[#111111] dark:hover:text-gray-100">Address<?= esc($sortMark('ip')) ?></a></th>
                    <th scope="col" class="px-4 py-3">Device</th>
                    <th scope="col" class="px-4 py-3">Browser</th>
                    <th scope="col" class="px-4 py-3">OS</th>
                    <th scope="col" class="px-4 py-3">Screen</th>
                    <th scope="col" class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#EAEAEA] dark:divide-gray-700">
                <?php foreach ($items as $item) : ?>
                    <tr>
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums">
                            <?= esc((string) (attendance_local($item->created_at)?->format('Y-m-d H:i') ?? '—')) ?>
                        </td>
                        <td class="px-4 py-3 font-medium">
                            <a href="/admin/access-logs/user/<?= (int) $item->user_id ?>" class="hover:underline">
                                <?= esc((string) ($item->user_name ?? $item->user_email ?? '—')) ?>
                            </a>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3"><?= view('admin/access-logs/_event_pill', ['event' => $item->event]) ?></td>
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums"><?= esc((string) ($item->ip ?? '—')) ?></td>
                        <td class="whitespace-nowrap px-4 py-3"><?= esc($deviceIcons[$item->device_type] ?? '—') ?></td>
                        <td class="whitespace-nowrap px-4 py-3"><?= esc((string) ($item->browser ?? '—')) ?></td>
                        <td class="whitespace-nowrap px-4 py-3"><?= esc((string) ($item->platform ?? '—')) ?></td>
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums">
                            <?= $item->screen_width === null ? '—' : esc($item->screen_width . ' × ' . $item->screen_height) ?>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            <button type="button" class="text-sm font-medium text-[#111111] hover:underline dark:text-gray-100"
                                    @click="openDetail(<?= (int) $item->id ?>)">Details</button>
                        </td>
                    </tr>
                <?php endforeach ?>
                <?php if ($items === []) : ?>
                    <tr>
                        <td colspan="9" class="px-4 py-10 text-center text-sm text-[#787774] dark:text-gray-400">No access events match these filters.</td>
                    </tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex items-center justify-between text-sm text-[#787774] dark:text-gray-400">
        <p><?= esc((string) $total) ?> event<?= $total === 1 ? '' : 's' ?></p>
        <div class="flex items-center gap-2">
            <?php if ($page > 1) : ?>
                <a href="<?= esc($pageUrl($page - 1), 'attr') ?>" class="rounded-md border border-[#EAEAEA] px-3 py-1.5 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-700">Prev</a>
            <?php endif ?>
            <span>Page <?= esc((string) $page) ?> of <?= esc((string) $totalPages) ?></span>
            <?php if ($page < $totalPages) : ?>
                <a href="<?= esc($pageUrl($page + 1), 'attr') ?>" class="rounded-md border border-[#EAEAEA] px-3 py-1.5 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-700">Next</a>
            <?php endif ?>
        </div>
    </div>

    <!-- Detail modal: the raw user-agent header, which is too long for a column. -->
    <div x-show="open" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center px-4"
         @keydown.escape.window="open = false">
        <div class="fixed inset-0 bg-black/40" @click="open = false"></div>
        <div x-show="open" x-transition class="modal-panel relative max-w-lg"
             x-trap.noscroll="open" role="dialog" aria-modal="true" aria-labelledby="access-log-detail-title">
            <button type="button" class="absolute right-4 top-4 rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:text-gray-500 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                    @click="open = false" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <h2 id="access-log-detail-title" class="pr-8 text-lg font-semibold text-gray-800 dark:text-white/90">Access details</h2>
            <p x-show="loading" x-cloak class="mt-4 text-sm text-[#787774] dark:text-gray-400">Loading…</p>

            <dl x-show="!loading" class="mt-4 space-y-3 text-sm">
                <template x-for="field in fields" :key="field.label">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400" x-text="field.label"></dt>
                        <dd class="mt-0.5 break-all" x-text="field.value ?? '—'"></dd>
                    </div>
                </template>
            </dl>

            <div class="flex justify-end pt-4">
                <button type="button" class="btn-secondary" @click="open = false">Close</button>
            </div>
        </div>
    </div>

    <script>
        function accessLogs() {
            return {
                open: false,
                loading: false,
                fields: [],

                async openDetail(id) {
                    this.open = true;
                    this.loading = true;
                    this.fields = [];

                    let json;
                    try {
                        json = await fetchLatest('access-logs.show', '/admin/access-logs/show/' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    } catch (e) {
                        this.open = false;
                        this.$dispatch('toast', { type: 'error', text: 'Network error. Please try again.' });
                        return;
                    }

                    // A newer open superseded this response: it is not on screen any more.
                    if (json === null) return;

                    this.loading = false;

                    if (json.status !== 'success') {
                        this.open = false;
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Entry not found.' });
                        return;
                    }

                    const e = json.entry;
                    this.fields = [
                        { label: 'Event', value: e.event },
                        { label: 'When (UTC)', value: e.created_at },
                        { label: 'Address', value: e.ip },
                        { label: 'Device', value: e.device_type },
                        { label: 'Browser', value: e.browser },
                        { label: 'Operating system', value: e.platform },
                        { label: 'Screen', value: e.screen },
                        { label: 'Raw user agent', value: e.user_agent },
                    ];
                },
            };
        }
    </script>
</div>
<?php $this->endSection() ?>
