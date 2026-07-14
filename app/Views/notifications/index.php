<?php
/**
 * Full notifications list for the current session user (admin or client —
 * this controller/view is shared, see App\Controllers\Notifications).
 * Messages are stored, untrusted text: server-rendered copies use esc(),
 * the Alpine-driven copies use x-text (never x-html).
 *
 * @var list<object> $items       Notifications::index() rows (id, user_id, task_id, message, is_read, created_at, updated_at)
 * @var int          $total
 * @var int          $page
 * @var int          $perPage
 * @var int          $unreadCount
 */
$this->extend('layouts/app');

$totalPages = max(1, (int) ceil($total / max($perPage, 1)));

$itemsForJs = array_map(static fn (object $item): array => [
    'id'         => (int) $item->id,
    'message'    => $item->message,
    'created_at' => $item->created_at,
    'is_read'    => (int) $item->is_read === 1,
], $items);
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-4xl" x-data="notificationsPage(<?= esc(json_encode($itemsForJs), 'attr') ?>, <?= (int) $unreadCount ?>)">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Notifications</h1>
            <p class="mt-1 text-sm text-[#787774]">
                <span x-text="unread"></span> unread of <?= esc((string) $total) ?> total.
            </p>
        </div>
        <button type="button" class="rounded-md border border-[#EAEAEA] px-3 py-1.5 text-sm font-medium hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="markingAll || unread === 0" @click="markAllRead()">
            Mark all as read
        </button>
    </div>

    <div class="mt-4 divide-y divide-[#EAEAEA] rounded-lg border border-[#EAEAEA] bg-white">
        <template x-for="item in items" :key="item.id">
            <div class="flex items-start justify-between gap-4 px-4 py-3" :class="!item.is_read ? 'bg-[#E1F3FE]/40' : ''">
                <div class="min-w-0 flex-1">
                    <p class="text-sm" :class="!item.is_read ? 'font-medium text-[#111111]' : 'text-[#787774]'" x-text="item.message"></p>
                    <p class="mt-1 text-xs text-[#787774]" x-text="item.created_at"></p>
                </div>
                <button type="button" x-show="!item.is_read" x-cloak
                        class="shrink-0 text-sm font-medium text-[#111111] hover:underline"
                        @click="markRead(item)">
                    Mark read
                </button>
            </div>
        </template>
        <p x-show="items.length === 0" x-cloak class="px-4 py-10 text-center text-sm text-[#787774]">
            You have no notifications yet.
        </p>
    </div>

    <?php if ($totalPages > 1) : ?>
        <div class="mt-4 flex items-center justify-between text-sm text-[#787774]">
            <p>Page <?= esc((string) $page) ?> of <?= esc((string) $totalPages) ?></p>
            <div class="flex items-center gap-2">
                <?php if ($page > 1) : ?>
                    <a href="?page=<?= esc((string) ($page - 1), 'attr') ?>" class="rounded-md border border-[#EAEAEA] px-3 py-1.5 hover:bg-gray-50">Prev</a>
                <?php endif ?>
                <?php if ($page < $totalPages) : ?>
                    <a href="?page=<?= esc((string) ($page + 1), 'attr') ?>" class="rounded-md border border-[#EAEAEA] px-3 py-1.5 hover:bg-gray-50">Next</a>
                <?php endif ?>
            </div>
        </div>
    <?php endif ?>

    <script>
        function notificationsPage(initialItems, initialUnread) {
            return {
                items: initialItems,
                unread: initialUnread,
                markingAll: false,

                async markRead(item) {
                    const json = await postForm('/notifications/mark-read', { id: item.id });
                    if (json && json.status === 'success') {
                        item.is_read = true;
                        this.unread = json.unread_count;
                    } else if (json) {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not mark as read.' });
                    }
                },

                async markAllRead() {
                    this.markingAll = true;
                    const json = await postForm('/notifications/mark-all-read', {});
                    this.markingAll = false;
                    if (json && json.status === 'success') {
                        this.items.forEach(i => { i.is_read = true; });
                        this.unread = json.unread_count;
                        this.$dispatch('toast', { type: 'success', text: json.message });
                    } else if (json) {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not mark all as read.' });
                    }
                },
            };
        }
    </script>
</div>
<?php $this->endSection() ?>
