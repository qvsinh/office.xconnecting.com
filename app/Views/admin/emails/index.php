<?php
/**
 * Admin sent-email log (`emails` table) — **read-only**: keyword + status
 * filter, paginate, and a "View" button that opens a detail modal fetched
 * from `/admin/emails/show/{id}`. No create/edit/delete controls anywhere on
 * this page (the send pipeline that writes rows doesn't exist yet).
 *
 * The message body is trusted-but-untrusted rendered HTML — never
 * interpolated into this page's DOM. It is shown only inside a sandboxed
 * `<iframe srcdoc>` (no `allow-scripts`), bound via Alpine's `:srcdoc`
 * attribute binding (never `x-html`).
 *
 * @var list<object>                         $items   searchAdmin() rows (id/name/subject/from_email/to_email/status/created_at)
 * @var int                                  $total
 * @var int                                  $page
 * @var int                                  $limit
 * @var string                               $sort
 * @var string                               $order
 * @var array{keyword:string, status:string} $filters
 */
$this->extend('layouts/app');

$totalPages = max(1, (int) ceil($total / max($limit, 1)));

$queryBase = [
    'keyword' => $filters['keyword'] ?? '',
    'status'  => $filters['status'] ?? '',
    'sort'    => $sort,
    'order'   => $order,
    'limit'   => $limit,
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

$statusLabels = [0 => 'Pending', 1 => 'Sent', 2 => 'Failed'];
$statusBadge  = static function (int $status) use ($statusLabels): string {
    $label = $statusLabels[$status] ?? 'Unknown';
    $tone  = match ($status) {
        1       => 'bg-[#EDF3EC] text-[#346538] dark:bg-green-900/30 dark:text-green-300',
        2       => 'bg-[#FDEBEC] text-[#9F2F2D] dark:bg-red-900/30 dark:text-red-300',
        default => 'bg-[#FBF3DB] text-[#956400] dark:bg-yellow-900/30 dark:text-yellow-300',
    };

    return '<span class="rounded-full px-2 py-0.5 text-xs font-medium uppercase tracking-wide ' . $tone . '">' . esc($label) . '</span>';
};
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-7xl" x-data="sentEmailsAdmin()">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Sent Emails</h1>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">Read-only log of messages sent by the platform.</p>
        </div>
    </div>

    <form method="get" class="mt-6 flex flex-wrap items-end gap-3 rounded-lg border border-[#EAEAEA] bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
        <div class="min-w-[220px] flex-1">
            <label for="keyword" class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Search</label>
            <input type="text" id="keyword" name="keyword" value="<?= esc($filters['keyword'] ?? '', 'attr') ?>"
                   placeholder="Recipient, subject or template name"
                   class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400">
        </div>
        <div>
            <label for="status" class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Status</label>
            <select id="status" name="status"
                    class="rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400">
                <option value="">All statuses</option>
                <?php foreach ($statusLabels as $value => $label) : ?>
                    <option value="<?= esc((string) $value, 'attr') ?>" <?= ($filters['status'] ?? '') === (string) $value ? 'selected' : '' ?>><?= esc($label) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div>
            <label for="limit" class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Per page</label>
            <select id="limit" name="limit" class="rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400">
                <?php foreach ([25, 50, 100] as $opt) : ?>
                    <option value="<?= esc((string) $opt, 'attr') ?>" <?= $limit === $opt ? 'selected' : '' ?>><?= esc((string) $opt) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <input type="hidden" name="sort" value="<?= esc($sort, 'attr') ?>">
        <input type="hidden" name="order" value="<?= esc($order, 'attr') ?>">
        <button type="submit" class="rounded-md border border-[#EAEAEA] px-4 py-2 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:text-gray-100 dark:hover:bg-gray-700">Search</button>
        <button type="button" @click="location.href='?limit=<?= esc((string) $limit, 'js') ?>'"
                class="rounded-md border border-[#EAEAEA] px-4 py-2 text-sm font-medium text-[#787774] hover:bg-gray-50 hover:text-[#111111] dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-100">Clear</button>
    </form>

    <div class="mt-6 overflow-x-auto rounded-lg border border-[#EAEAEA] bg-white dark:border-gray-700 dark:bg-gray-800">
        <table class="min-w-full divide-y divide-[#EAEAEA] text-sm dark:divide-gray-700">
            <thead>
                <tr class="text-left text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">
                    <th class="px-4 py-3"><a href="<?= esc($sortUrl('to_email'), 'attr') ?>" class="hover:text-[#111111] dark:hover:text-gray-100">To<?= esc($sortMark('to_email')) ?></a></th>
                    <th class="px-4 py-3">Subject</th>
                    <th class="px-4 py-3"><a href="<?= esc($sortUrl('status'), 'attr') ?>" class="hover:text-[#111111] dark:hover:text-gray-100">Status<?= esc($sortMark('status')) ?></a></th>
                    <th class="px-4 py-3"><a href="<?= esc($sortUrl('created_at'), 'attr') ?>" class="hover:text-[#111111] dark:hover:text-gray-100">Created<?= esc($sortMark('created_at')) ?></a></th>
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#EAEAEA] dark:divide-gray-700">
                <?php foreach ($items as $item) : ?>
                    <tr>
                        <td class="px-4 py-3 font-medium"><?= esc((string) ($item->to_email ?? '—')) ?></td>
                        <td class="max-w-sm truncate px-4 py-3 text-[#787774] dark:text-gray-400" title="<?= esc((string) ($item->subject ?? ''), 'attr') ?>"><?= esc((string) ($item->subject ?? '—')) ?></td>
                        <td class="px-4 py-3"><?= $statusBadge((int) ($item->status ?? 0)) ?></td>
                        <td class="px-4 py-3 text-xs text-[#787774] dark:text-gray-400"><?= esc((string) ($item->created_at ?? '—')) ?></td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            <button type="button" class="text-sm font-medium text-[#111111] hover:underline dark:text-gray-100" @click="openShow(<?= esc(json_encode((string) $item->id), 'attr') ?>)">View</button>
                        </td>
                    </tr>
                <?php endforeach ?>
                <?php if ($items === []) : ?>
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-sm text-[#787774] dark:text-gray-400">No sent emails match your search.</td>
                    </tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex items-center justify-between text-sm text-[#787774] dark:text-gray-400">
        <p><?= esc((string) $total) ?> email<?= $total === 1 ? '' : 's' ?> total</p>
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

    <!-- Detail modal (read-only) -->
    <div x-show="showOpen" x-cloak class="fixed inset-0 z-40 flex items-center justify-center px-4 py-8"
         @keydown.escape.window="showOpen = false">
        <div class="fixed inset-0 bg-black/40" @click="showOpen = false"></div>
        <div x-show="showOpen" x-transition
             class="relative max-h-full w-full max-w-2xl overflow-y-auto rounded-lg border border-[#EAEAEA] bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800"
             x-trap.noscroll="showOpen" role="dialog" aria-modal="true" aria-labelledby="show-email-title">
            <h2 id="show-email-title" class="text-base font-semibold dark:text-gray-100">Sent email</h2>
            <p x-show="showLoading" x-cloak class="mt-4 text-sm text-[#787774] dark:text-gray-400">Loading…</p>
            <div x-show="!showLoading" class="mt-4 space-y-3 text-sm">
                <dl class="grid grid-cols-1 gap-x-4 gap-y-2 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">From</dt>
                        <dd class="dark:text-gray-100" x-text="showItem.from_email"></dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">To</dt>
                        <dd class="dark:text-gray-100" x-text="showItem.to_email"></dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Subject</dt>
                        <dd class="dark:text-gray-100" x-text="showItem.subject"></dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Status</dt>
                        <dd class="dark:text-gray-100" x-text="statusLabel(showItem.status)"></dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Created</dt>
                        <dd class="dark:text-gray-100" x-text="showItem.created_at"></dd>
                    </div>
                </dl>
                <div x-show="showItem.error_message" x-cloak class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300">
                    <span x-text="showItem.error_message"></span>
                </div>
                <div>
                    <p class="mb-1.5 text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Body</p>
                    <!-- Sandboxed preview: srcdoc bound via Alpine attribute binding
                         (never x-html), no allow-scripts — the logged HTML renders
                         isolated and can never touch this page's DOM/scripts. -->
                    <iframe :srcdoc="showItem.content" sandbox=""
                            class="h-72 w-full rounded-md border border-[#EAEAEA] bg-white dark:border-gray-700"
                            title="Email body preview"></iframe>
                </div>
            </div>
            <div class="mt-6 flex justify-end">
                <button type="button" class="rounded-md border border-[#EAEAEA] px-3 py-1.5 text-sm hover:bg-gray-50 dark:border-gray-700 dark:text-gray-100 dark:hover:bg-gray-700" @click="showOpen = false">Close</button>
            </div>
        </div>
    </div>

    <script>
        function sentEmailsAdmin() {
            return {
                showOpen: false,
                showLoading: false,
                showItem: { from_email: '', to_email: '', subject: '', status: 0, created_at: '', error_message: '', content: '' },

                statusLabel(status) {
                    return { 0: 'Pending', 1: 'Sent', 2: 'Failed' }[status] ?? 'Unknown';
                },

                async openShow(id) {
                    this.showOpen = true;
                    this.showLoading = true;
                    let json;
                    try {
                        const res = await fetch('/admin/emails/show/' + encodeURIComponent(id), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                        json = await res.json();
                    } catch (e) {
                        this.showOpen = false;
                        this.$dispatch('toast', { type: 'error', text: 'Network error. Please try again.' });
                        return;
                    }
                    this.showLoading = false;
                    if (json.status !== 'success') {
                        this.showOpen = false;
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Email not found.' });
                        return;
                    }
                    this.showItem = json.email;
                },
            };
        }
    </script>
</div>
<?php $this->endSection() ?>
