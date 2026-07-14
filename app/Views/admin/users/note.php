<?php
/**
 * Admin-only note editor for a single user, saved via
 * Admin\Users::updateNote(). Not visible to the user themselves.
 *
 * @var object $user users row — only id/name/note are used here
 */
$this->extend('layouts/app');
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-2xl" x-data="userNote()">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight dark:text-gray-100">Note — <?= esc($user->name) ?></h1>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">Visible to admins only.</p>
        </div>
        <a href="/admin/users/view/<?= (int) $user->id ?>" class="text-sm text-[#787774] hover:text-[#111111] dark:text-gray-400 dark:hover:text-gray-100">&larr; Back to user</a>
    </div>

    <form @submit.prevent="save" class="mt-6 space-y-4 rounded-lg border border-[#EAEAEA] bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
        <div>
            <label for="note" class="mb-1.5 block text-sm font-medium dark:text-gray-200">Admin note</label>
            <textarea id="note" x-model="note" rows="8"
                      class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-gray-400 dark:focus:ring-gray-400"></textarea>
        </div>
        <div class="flex justify-end gap-2">
            <a href="/admin/users/view/<?= (int) $user->id ?>" class="rounded-md border border-[#EAEAEA] px-3 py-1.5 text-sm hover:bg-gray-50 dark:border-gray-700 dark:text-gray-100 dark:hover:bg-gray-700">Cancel</a>
            <button type="submit" :disabled="saving"
                    class="rounded-md bg-[#111111] px-4 py-1.5 text-sm font-medium text-white hover:bg-[#333333] disabled:cursor-not-allowed disabled:opacity-60 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">
                <span x-show="!saving">Save note</span>
                <span x-show="saving" x-cloak>Saving…</span>
            </button>
        </div>
    </form>

    <script>
        function userNote() {
            return {
                userId: <?= (int) $user->id ?>,
                note: <?= esc(json_encode($user->note ?? ''), 'attr') ?>,
                saving: false,
                async save() {
                    this.saving = true;
                    const json = await postForm('/admin/users/note', { id: this.userId, note: this.note });
                    this.saving = false;
                    if (!json) return;
                    if (json.status === 'success') {
                        this.$dispatch('toast', { type: 'success', text: json.message });
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not save the note.' });
                    }
                },
            };
        }
    </script>
</div>
<?php $this->endSection() ?>
