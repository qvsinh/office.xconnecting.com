<?php
/**
 * Manage members of a single user group: current members (remove via the
 * shared confirm modal) + an "add member" picker from a server-rendered
 * <select> of available (not-yet-member) active users. Mirrors the
 * TailAdmin card/table patterns from `admin/settings/index.php`.
 *
 * @var object      $group           id/name/slug/description
 * @var list<object> $members        id/name/email — current group members
 * @var list<object> $availableUsers id/name/email — users not yet in the group
 */
$this->extend('layouts/app');
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-5xl" x-data="groupMembers(<?= (int) $group->id ?>)">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <a href="/admin/groups" class="text-sm font-medium text-[#787774] hover:text-[#111111] dark:text-gray-400 dark:hover:text-gray-100">&larr; Back to groups</a>
            <h1 class="mt-1 text-xl font-semibold tracking-tight"><?= esc($group->name ?? '') ?></h1>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">Manage members of this group.</p>
        </div>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Current members -->
        <div class="lg:col-span-2 overflow-x-auto rounded-lg border border-[#EAEAEA] bg-white dark:border-gray-700 dark:bg-gray-800">
            <table class="min-w-full divide-y divide-[#EAEAEA] text-sm dark:divide-gray-700">
                <thead>
                    <tr class="text-left text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Email</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#EAEAEA] dark:divide-gray-700">
                    <template x-for="member in members" :key="member.id">
                        <tr>
                            <td class="px-4 py-3 font-medium" x-text="member.name"></td>
                            <td class="px-4 py-3 text-[#787774] dark:text-gray-400" x-text="member.email"></td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                <button type="button" class="text-sm font-medium text-[#9F2F2D] hover:underline dark:text-red-400"
                                        @click="confirmRemove(member)">Remove</button>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="members.length === 0">
                        <td colspan="3" class="px-4 py-10 text-center text-sm text-[#787774] dark:text-gray-400">This group has no members yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Add member -->
        <div class="rounded-lg border border-[#EAEAEA] bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Add member</h2>
            <form @submit.prevent="submitAdd" class="mt-4 space-y-4">
                <div>
                    <label for="add_user_id" class="form-label">User</label>
                    <select id="add_user_id" x-model="addUserId" required class="form-select">
                        <option value="">Select a user…</option>
                        <template x-for="u in available" :key="u.id">
                            <option :value="u.id" x-text="u.name + ' (' + u.email + ')'"></option>
                        </template>
                    </select>
                    <p x-show="available.length === 0" x-cloak class="mt-1 text-xs text-[#787774] dark:text-gray-400">No more users available to add.</p>
                </div>
                <button type="submit" :disabled="adding || !addUserId" class="btn-primary w-full">
                    <span x-show="!adding">Add to group</span>
                    <span x-show="adding" x-cloak>Adding…</span>
                </button>
            </form>
        </div>
    </div>
</div>

<script>
    function groupMembers(groupId) {
        return {
            groupId: groupId,
            members: <?= json_encode(array_map(static fn ($m) => [
                'id'    => (int) $m->id,
                'name'  => $m->name ?? '',
                'email' => $m->email ?? '',
            ], $members)) ?>,
            available: <?= json_encode(array_map(static fn ($u) => [
                'id'    => (int) $u->id,
                'name'  => $u->name ?? '',
                'email' => $u->email ?? '',
            ], $availableUsers)) ?>,
            adding: false,
            addUserId: '',

            async submitAdd() {
                if (!this.addUserId) return;
                this.adding = true;
                const json = await postForm('/admin/groups/' + this.groupId + '/members/add', { user_id: this.addUserId });
                this.adding = false;
                if (!json) return;
                if (json.status === 'success') {
                    const added = this.available.find(u => String(u.id) === String(this.addUserId));
                    if (added) {
                        this.members.push(added);
                        this.available = this.available.filter(u => String(u.id) !== String(this.addUserId));
                    }
                    this.addUserId = '';
                    this.$dispatch('toast', { type: 'success', text: json.message });
                } else {
                    this.$dispatch('toast', { type: 'error', text: json.message || 'Could not add member.' });
                }
            },

            confirmRemove(member) {
                this.$dispatch('confirm', {
                    title: 'Remove member',
                    body: 'Remove "' + member.name + '" from this group?',
                    variant: 'danger',
                    confirmLabel: 'Remove',
                    onConfirm: async () => {
                        const json = await postForm('/admin/groups/' + this.groupId + '/members/remove', { user_id: member.id });
                        if (!json) return;
                        if (json.status === 'success') {
                            this.members = this.members.filter(m => m.id !== member.id);
                            this.available.push(member);
                            this.$dispatch('toast', { type: 'success', text: json.message });
                        } else {
                            this.$dispatch('toast', { type: 'error', text: json.message || 'Could not remove member.' });
                        }
                    },
                });
            },
        };
    }
</script>
<?php $this->endSection() ?>
