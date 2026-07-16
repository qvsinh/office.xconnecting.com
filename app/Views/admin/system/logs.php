<?php
/**
 * System logs viewer over `writable/logs/`. Preview content is untrusted
 * raw log text — rendered with `x-text`, never `x-html`. Delete / delete-all
 * go through the shared confirm modal + postForm(); download is a plain
 * link (browser handles the file response, no fetch needed).
 *
 * @var list<array{name:string, size:int, mtime:int}> $items
 * @var int                                           $total
 * @var int                                           $page
 * @var int                                           $limit
 */
$this->extend('layouts/app');

$totalPages = max(1, (int) ceil($total / max($limit, 1)));

$pageUrl = static fn (int $target): string => '?' . http_build_query(array_filter(
    ['page' => $target, 'limit' => $limit],
    static fn ($v): bool => $v !== '' && $v !== null,
));

$itemsForJs = array_map(static fn (array $item): array => [
    'name'  => $item['name'],
    'size'  => (int) $item['size'],
    'mtime' => (int) $item['mtime'],
], $items);
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-5xl" x-data="systemLogs(<?= esc(json_encode($itemsForJs), 'attr') ?>)">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight dark:text-gray-100">System logs</h1>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400"><code class="font-mono">writable/logs/</code> — preview, download, or delete log files.</p>
        </div>
        <button type="button" @click="confirmDeleteAll()" :disabled="items.length === 0 || deletingAll" class="btn-danger">
            Delete all
        </button>
    </div>

    <div class="mt-4 overflow-x-auto rounded-lg border border-[#EAEAEA] bg-white dark:border-gray-700 dark:bg-gray-800">
        <table class="min-w-full divide-y divide-[#EAEAEA] text-sm dark:divide-gray-700">
            <thead>
                <tr class="text-left text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">
                    <th class="px-4 py-3">Name</th>
                    <th class="px-4 py-3">Size</th>
                    <th class="px-4 py-3">Modified</th>
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#EAEAEA] dark:divide-gray-700">
                <template x-for="item in items" :key="item.name">
                    <tr>
                        <td class="px-4 py-3 font-mono text-xs dark:text-gray-100" x-text="item.name"></td>
                        <td class="px-4 py-3 text-xs text-[#787774] dark:text-gray-400" x-text="formatBytes(item.size)"></td>
                        <td class="px-4 py-3 text-xs text-[#787774] dark:text-gray-400" x-text="formatDate(item.mtime)"></td>
                        <td class="px-4 py-3 text-right">
                            <button type="button" class="mr-3 text-sm font-medium text-[#111111] hover:underline dark:text-gray-100" @click="openPreview(item)">Preview</button>
                            <a class="mr-3 text-sm font-medium text-[#111111] hover:underline dark:text-gray-100"
                               :href="'/admin/system/logs/download?file=' + encodeURIComponent(item.name)">Download</a>
                            <button type="button" class="text-sm font-medium text-[#9F2F2D] hover:underline dark:text-red-400" @click="confirmDelete(item)">Delete</button>
                        </td>
                    </tr>
                </template>
                <tr x-show="items.length === 0" x-cloak>
                    <td colspan="4" class="px-4 py-10 text-center text-sm text-[#787774] dark:text-gray-400">No log files found.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex items-center justify-between text-sm text-[#787774] dark:text-gray-400">
        <p><?= esc((string) $total) ?> file<?= $total === 1 ? '' : 's' ?> total</p>
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

    <!-- Preview modal: raw log tail is untrusted, hence x-text (never x-html) -->
    <div x-show="previewOpen" x-cloak class="fixed inset-0 z-40 flex items-center justify-center px-4"
         @keydown.escape.window="closePreview()">
        <div class="fixed inset-0 bg-black/40" x-show="previewOpen" x-transition.opacity @click="closePreview()"></div>
        <div x-show="previewOpen" x-transition
             class="modal-panel relative flex max-h-[80vh] max-w-3xl flex-col overflow-hidden p-0"
             x-trap.noscroll="previewOpen" role="dialog" aria-modal="true" aria-labelledby="log-preview-title">
            <div class="flex items-center justify-between border-b border-[#EAEAEA] px-6 py-4 dark:border-gray-700">
                <h2 id="log-preview-title" class="pr-8 font-mono text-sm font-semibold text-gray-800 dark:text-white/90" x-text="previewName"></h2>
            </div>
            <button type="button" class="absolute right-4 top-4 rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:text-gray-500 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                    @click="closePreview()" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <div class="overflow-y-auto p-6">
                <p x-show="previewLoading" x-cloak class="text-sm text-[#787774] dark:text-gray-400">Loading…</p>
                <pre x-show="!previewLoading" x-cloak class="whitespace-pre-wrap break-all font-mono text-xs text-[#111111] dark:text-gray-100" x-text="previewContent"></pre>
            </div>
        </div>
    </div>

    <script>
        function systemLogs(initialItems) {
            return {
                items: initialItems,
                deletingAll: false,
                previewOpen: false,
                previewLoading: false,
                previewName: '',
                previewContent: '',

                formatBytes(bytes) {
                    if (bytes < 1024) return bytes + ' B';
                    const units = ['KB', 'MB', 'GB'];
                    let value = bytes / 1024;
                    for (const unit of units) {
                        if (value < 1024 || unit === 'GB') return value.toFixed(1) + ' ' + unit;
                        value /= 1024;
                    }
                    return value.toFixed(1) + ' GB';
                },
                formatDate(unixTs) {
                    if (!unixTs) return '—';
                    return new Date(unixTs * 1000).toLocaleString();
                },

                async openPreview(item) {
                    this.previewOpen = true;
                    this.previewLoading = true;
                    this.previewName = item.name;
                    this.previewContent = '';

                    let json;
                    try {
                        const res = await fetch('/admin/system/logs/preview?file=' + encodeURIComponent(item.name), {
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
                        this.$dispatch('toast', { type: 'error', text: json.message || 'File not found.' });
                        return;
                    }
                    this.previewContent = json.content;
                },
                closePreview() {
                    this.previewOpen = false;
                    this.previewContent = '';
                },

                confirmDelete(item) {
                    this.$dispatch('confirm', {
                        title: 'Delete log file',
                        body: 'This will permanently delete ' + item.name + '. This cannot be undone.',
                        variant: 'danger',
                        confirmLabel: 'Delete',
                        onConfirm: () => this.doDelete(item),
                    });
                },
                async doDelete(item) {
                    const json = await postForm('/admin/system/logs/delete', { file: item.name });
                    if (json && json.status === 'success') {
                        this.items = this.items.filter(i => i.name !== item.name);
                        this.$dispatch('toast', { type: 'success', text: json.message });
                    } else if (json) {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not delete.' });
                    }
                },

                confirmDeleteAll() {
                    const count = this.items.length;
                    this.$dispatch('confirm', {
                        title: 'Delete all log files',
                        body: 'This will permanently delete all ' + count + ' log file' + (count === 1 ? '' : 's') + '. This cannot be undone.',
                        variant: 'danger',
                        confirmLabel: 'Delete all',
                        onConfirm: () => this.doDeleteAll(),
                    });
                },
                async doDeleteAll() {
                    this.deletingAll = true;
                    const json = await postForm('/admin/system/logs/delete-all', {});
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
    </script>
</div>
<?php $this->endSection() ?>
