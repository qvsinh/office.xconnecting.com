<?php
/**
 * Admin API history viewer: same filters as the client view plus a per-user
 * filter (email or id) and a User column. No delete-by-age here (kanban plan
 * §7 — user-only for now). `$filters` never carries the raw `user` input
 * back (Admin\ApiHistory resolves it to `user_id` before the view is
 * rendered — see "Backend dependency" in the frontend report), so this view
 * reads the raw value straight off the request to repopulate the field.
 *
 * @var list<array<string, mixed>>                                                                             $items   searchHistory() rows + user_name/user_email
 * @var int                                                                                                    $total
 * @var int                                                                                                    $page
 * @var int                                                                                                    $limit
 * @var string                                                                                                 $sortBy
 * @var string                                                                                                 $sort
 * @var array{keyword:string, service_name:string, service_id:string, env:string, status:string, user_id?:int} $filters
 * @var list<object>                                                                                           $users   id/name/email — for the user-filter datalist
 */
$this->extend('layouts/app');

$totalPages    = max(1, (int) ceil($total / max($limit, 1)));
$rawUserFilter = (string) (service('request')->getGet('user') ?? '');

$queryBase = [
    'keyword'      => $filters['keyword'] ?? '',
    'service_name' => $filters['service_name'] ?? '',
    'service_id'   => $filters['service_id'] ?? '',
    'env'          => $filters['env'] ?? '',
    'status'       => $filters['status'] ?? '',
    'user'         => $rawUserFilter,
    'limit'        => $limit,
];

$buildQuery = static fn (array $extra): string => '?' . http_build_query(
    array_filter($queryBase + $extra, static fn ($v): bool => $v !== '' && $v !== null),
);

$pageUrl = static fn (int $target): string => $buildQuery(['page' => $target, 'sort_by' => $sortBy, 'sort' => $sort]);
$sortUrl = static fn (string $col): string => $buildQuery([
    'sort_by' => $col,
    'sort'    => ($sortBy === $col && strtolower($sort) === 'asc') ? 'desc' : 'asc',
]);
$sortMark = static function (string $col) use ($sortBy, $sort): string {
    if ($sortBy !== $col) {
        return '';
    }

    return strtolower($sort) === 'asc' ? ' ▲' : ' ▼';
};

$itemsForJs = array_map(static fn (array $item): array => [
    'id'           => (int) $item['id'],
    'user_id'      => (int) $item['user_id'],
    'user_name'    => $item['user_name'] ?? null,
    'user_email'   => $item['user_email'] ?? null,
    'service_name' => $item['service_name'],
    'service_id'   => $item['service_id'],
    'command'      => $item['command'],
    'domain'       => $item['domain'],
    'status'       => (int) $item['status'],
    'created_at'   => $item['created_at'],
], $items);
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-7xl" x-data="apiHistoryAdmin(<?= esc(json_encode($itemsForJs), 'attr') ?>, <?= (int) $total ?>)">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">API history</h1>
            <p class="mt-1 text-sm text-[#787774]">CNR / JPRS proxy call log across every user. Deletes are soft — rows are recoverable at the database level only.</p>
        </div>
    </div>

    <form method="get" class="mt-6 flex flex-wrap items-end gap-3 rounded-lg border border-[#EAEAEA] bg-white p-4">
        <div class="min-w-[200px] flex-1">
            <label for="keyword" class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-[#787774]">Keyword</label>
            <input type="text" id="keyword" name="keyword" value="<?= esc($filters['keyword'] ?? '', 'attr') ?>"
                   placeholder="Service, command, domain, url, params, response"
                   class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111]">
        </div>
        <div class="min-w-[200px]">
            <label for="user" class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-[#787774]">User</label>
            <input type="text" id="user" name="user" list="user-options" value="<?= esc($rawUserFilter, 'attr') ?>"
                   placeholder="Email or user id"
                   class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111]">
            <datalist id="user-options">
                <?php foreach ($users as $u) : ?>
                    <option value="<?= esc($u->email, 'attr') ?>"><?= esc($u->name) ?></option>
                <?php endforeach ?>
            </datalist>
        </div>
        <div class="min-w-[140px]">
            <label for="service_name" class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-[#787774]">Service</label>
            <input type="text" id="service_name" name="service_name" value="<?= esc($filters['service_name'] ?? '', 'attr') ?>"
                   class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111]">
        </div>
        <div class="min-w-[110px]">
            <label for="service_id" class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-[#787774]">Service ID</label>
            <input type="text" id="service_id" name="service_id" value="<?= esc($filters['service_id'] ?? '', 'attr') ?>"
                   class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111]">
        </div>
        <div>
            <label for="env" class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-[#787774]">Env</label>
            <select id="env" name="env" class="rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111]">
                <option value="">Any</option>
                <?php foreach (['LIVE', 'TESTING'] as $envOpt) : ?>
                    <option value="<?= esc($envOpt, 'attr') ?>" <?= ($filters['env'] ?? '') === $envOpt ? 'selected' : '' ?>><?= esc($envOpt) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div>
            <label for="status" class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-[#787774]">Status</label>
            <select id="status" name="status" class="rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111]">
                <option value="">Any</option>
                <option value="1" <?= ($filters['status'] ?? '') === '1' ? 'selected' : '' ?>>Success</option>
                <option value="0" <?= ($filters['status'] ?? '') === '0' ? 'selected' : '' ?>>Failed</option>
            </select>
        </div>
        <div>
            <label for="limit" class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-[#787774]">Per page</label>
            <select id="limit" name="limit" class="rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111]">
                <?php foreach ([25, 50, 100] as $opt) : ?>
                    <option value="<?= esc((string) $opt, 'attr') ?>" <?= $limit === $opt ? 'selected' : '' ?>><?= esc((string) $opt) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <button type="submit" class="rounded-md border border-[#EAEAEA] px-4 py-2 text-sm font-medium hover:bg-gray-50">Search</button>
        <a href="?limit=<?= esc((string) $limit, 'attr') ?>" class="text-sm text-[#787774] hover:text-[#111111]">Clear</a>
    </form>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-[#EAEAEA] bg-white p-4">
        <p class="text-sm text-[#787774]"><?= esc((string) $total) ?> log<?= $total === 1 ? '' : 's' ?> match your current filters.</p>
        <button type="button" @click="confirmDeleteAll()" :disabled="deletingAll"
                class="rounded-md border border-[#9F2F2D] px-3 py-1.5 text-sm font-medium text-[#9F2F2D] hover:bg-[#FDEBEC] disabled:cursor-not-allowed disabled:opacity-50">
            Delete all (current filters)
        </button>
    </div>

    <div class="mt-4 overflow-x-auto rounded-lg border border-[#EAEAEA] bg-white">
        <table class="min-w-full divide-y divide-[#EAEAEA] text-sm">
            <thead>
                <tr class="text-left text-xs font-medium uppercase tracking-wide text-[#787774]">
                    <th class="px-4 py-3">User</th>
                    <th class="px-4 py-3"><a href="<?= esc($sortUrl('service_name'), 'attr') ?>" class="hover:text-[#111111]">Service<?= esc($sortMark('service_name')) ?></a></th>
                    <th class="px-4 py-3"><a href="<?= esc($sortUrl('service_id'), 'attr') ?>" class="hover:text-[#111111]">Service ID<?= esc($sortMark('service_id')) ?></a></th>
                    <th class="px-4 py-3"><a href="<?= esc($sortUrl('command'), 'attr') ?>" class="hover:text-[#111111]">Command<?= esc($sortMark('command')) ?></a></th>
                    <th class="px-4 py-3"><a href="<?= esc($sortUrl('domain'), 'attr') ?>" class="hover:text-[#111111]">Domain<?= esc($sortMark('domain')) ?></a></th>
                    <th class="px-4 py-3"><a href="<?= esc($sortUrl('status'), 'attr') ?>" class="hover:text-[#111111]">Status<?= esc($sortMark('status')) ?></a></th>
                    <th class="px-4 py-3"><a href="<?= esc($sortUrl('created_at'), 'attr') ?>" class="hover:text-[#111111]">Created<?= esc($sortMark('created_at')) ?></a></th>
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#EAEAEA]">
                <template x-for="item in items" :key="item.id">
                    <tr>
                        <td class="px-4 py-3">
                            <p class="font-medium" x-text="item.user_name || ('User #' + item.user_id)"></p>
                            <p class="text-xs text-[#787774]" x-text="item.user_email || ''"></p>
                        </td>
                        <td class="px-4 py-3" x-text="item.service_name || '—'"></td>
                        <td class="px-4 py-3" x-text="item.service_id ?? '—'"></td>
                        <td class="px-4 py-3 font-mono text-xs" x-text="item.command || '—'"></td>
                        <td class="px-4 py-3" x-text="item.domain || '—'"></td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium uppercase tracking-wide"
                                  :class="item.status === 1 ? 'bg-[#EDF3EC] text-[#346538]' : 'bg-[#FDEBEC] text-[#9F2F2D]'"
                                  x-text="item.status === 1 ? 'Success' : 'Failed'"></span>
                        </td>
                        <td class="px-4 py-3 text-xs text-[#787774]" x-text="item.created_at"></td>
                        <td class="px-4 py-3 text-right">
                            <button type="button" class="mr-3 text-sm font-medium text-[#111111] hover:underline" @click="openPreview(item.id)">View</button>
                            <button type="button" class="text-sm font-medium text-[#9F2F2D] hover:underline" @click="confirmDelete(item)">Delete</button>
                        </td>
                    </tr>
                </template>
                <tr x-show="items.length === 0" x-cloak>
                    <td colspan="8" class="px-4 py-10 text-center text-sm text-[#787774]">No log entries match your filters.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex items-center justify-between text-sm text-[#787774]">
        <p><?= esc((string) $total) ?> log<?= $total === 1 ? '' : 's' ?> total</p>
        <div class="flex items-center gap-2">
            <?php if ($page > 1) : ?>
                <a href="<?= esc($pageUrl($page - 1), 'attr') ?>" class="rounded-md border border-[#EAEAEA] px-3 py-1.5 hover:bg-gray-50">Prev</a>
            <?php endif ?>
            <span>Page <?= esc((string) $page) ?> of <?= esc((string) $totalPages) ?></span>
            <?php if ($page < $totalPages) : ?>
                <a href="<?= esc($pageUrl($page + 1), 'attr') ?>" class="rounded-md border border-[#EAEAEA] px-3 py-1.5 hover:bg-gray-50">Next</a>
            <?php endif ?>
        </div>
    </div>

    <?= $this->include('api_history/_preview_modal') ?>

    <script>
        function apiHistoryAdmin(initialItems, total) {
            return {
                items: initialItems,
                total,
                deletingAll: false,
                previewOpen: false,
                previewLoading: false,
                previewRow: null,

                prettyPrint(raw) {
                    if (raw === null || raw === undefined || raw === '') return '(empty)';
                    try {
                        return JSON.stringify(JSON.parse(raw), null, 2);
                    } catch (e) {
                        return String(raw);
                    }
                },

                async openPreview(id) {
                    this.previewOpen = true;
                    this.previewLoading = true;
                    this.previewRow = null;

                    let json;
                    try {
                        const res = await fetch('/admin/api-history/preview?id=' + encodeURIComponent(id), {
                            headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        });
                        json = await res.json();
                    } catch (e) {
                        this.previewOpen = false;
                        this.$dispatch('toast', { type: 'error', text: 'Network error. Please try again.' });
                        return;
                    }

                    this.previewLoading = false;
                    if (json.status !== 'success') {
                        this.previewOpen = false;
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Record not found.' });
                        return;
                    }
                    this.previewRow = json.row;
                },
                closePreview() {
                    this.previewOpen = false;
                    this.previewRow = null;
                },

                confirmDelete(item) {
                    this.$dispatch('confirm', {
                        title: 'Delete log entry',
                        body: 'This will remove this log entry for ' + (item.user_name || ('user #' + item.user_id)) + ' (soft-deleted, recoverable only at the database level).',
                        variant: 'danger',
                        confirmLabel: 'Delete',
                        onConfirm: () => this.doDelete(item),
                    });
                },
                async doDelete(item) {
                    const json = await postForm('/admin/api-history/delete', { id: item.id });
                    if (json && json.status === 'success') {
                        this.items = this.items.filter(i => i.id !== item.id);
                        this.total = Math.max(0, this.total - 1);
                        this.$dispatch('toast', { type: 'success', text: json.message });
                    } else if (json) {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not delete.' });
                    }
                },

                confirmDeleteAll() {
                    const count = this.total;
                    this.$dispatch('confirm', {
                        title: 'Delete all matching log entries',
                        body: 'This will remove ' + count + ' log entr' + (count === 1 ? 'y' : 'ies') + ' matching your current filters, across every user (soft-deleted, recoverable only at the database level).',
                        variant: 'danger',
                        confirmLabel: 'Delete all',
                        onConfirm: () => this.doDeleteAll(),
                    });
                },
                async doDeleteAll() {
                    this.deletingAll = true;
                    const json = await postForm('/admin/api-history/delete-all', currentFilterParams());
                    this.deletingAll = false;
                    if (json && json.status === 'success') {
                        this.$dispatch('toast', { type: 'success', text: json.message });
                        window.location.reload();
                    } else if (json) {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not delete.' });
                    }
                },
            };
        }

        // Reads the current query string (not component state) so delete-all
        // always targets exactly what the visible list is filtered by.
        function currentFilterParams() {
            const params = new URLSearchParams(window.location.search);
            return {
                keyword: params.get('keyword') || '',
                user: params.get('user') || '',
                service_name: params.get('service_name') || '',
                service_id: params.get('service_id') || '',
                env: params.get('env') || '',
                status: params.get('status') || '',
            };
        }
    </script>
</div>
<?php $this->endSection() ?>
