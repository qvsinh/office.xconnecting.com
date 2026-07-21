<?php
/**
 * Single workspace: header (visibility badge, edit/delete/leave), then
 * Alpine tabs — Overview (description, owner/created meta, projects list)
 * and Members (table + add-member/add-group forms when `$canManage`).
 * Mutating actions reload the page on success (mirrors `admin/groups/index.php`).
 *
 * @var object       $workspace       id/name/slug/description/visibility/owner_id/created_at
 * @var string|null  $role            caller's role in this workspace
 * @var list<object> $members         id/name/email/role
 * @var bool         $canManage
 * @var list<object> $availableUsers  id/name/email — not yet a member
 * @var list<object> $availableGroups id/name
 * @var list<object> $projects        id/name/description/status
 */
$this->extend('layouts/app');

$roleBadge = static fn (string $role): string => match ($role) {
    'owner'  => 'bg-[#FBF3DB] text-[#956400] dark:bg-yellow-900/30 dark:text-yellow-300',
    'admin'  => 'bg-[#E1F3FE] text-[#1F6C9F] dark:bg-blue-900/30 dark:text-blue-300',
    'member' => 'bg-[#EDF3EC] text-[#346538] dark:bg-green-900/30 dark:text-green-300',
    default  => 'bg-[#F7F6F3] text-[#787774] dark:bg-gray-700 dark:text-gray-300',
};

$visBadge = ($workspace->visibility ?? 'private') === 'company'
    ? 'bg-[#E1F3FE] text-[#1F6C9F] dark:bg-blue-900/30 dark:text-blue-300'
    : 'bg-[#F7F6F3] text-[#787774] dark:bg-gray-700 dark:text-gray-300';

$ownerName  = '—';
$ownerCount = 0;

foreach ($members as $m) {
    if (($m->role ?? '') === 'owner') {
        $ownerCount++;
        if ($ownerName === '—') {
            $ownerName = $m->name ?? '—';
        }
    }
}
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-6xl" x-data="workspaceShow(<?= (int) $workspace->id ?>)">

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="/workspaces" class="text-sm font-medium text-[#787774] hover:text-[#111111] dark:text-gray-400 dark:hover:text-gray-100">&larr; Back to workspaces</a>
            <div class="mt-1 flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-semibold tracking-tight"><?= esc($workspace->name ?? '') ?></h1>
                <span class="rounded-full px-2 py-0.5 text-xs font-medium uppercase tracking-wide <?= $visBadge ?>">
                    <?= esc((string) ($workspace->visibility ?? '')) ?>
                </span>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            <?php if ($canManage) : ?>
                <button type="button" class="btn-secondary" @click="openEdit()">Edit</button>
            <?php endif ?>
            <?php if ($role === 'owner') : ?>
                <button type="button" class="btn-danger" @click="confirmDelete()">Delete</button>
            <?php elseif ($role !== null) : ?>
                <button type="button" class="btn-secondary" @click="confirmLeave()">Leave</button>
            <?php endif ?>
        </div>
    </div>

    <!-- Tabs -->
    <div class="mt-6 border-b border-[#EAEAEA] dark:border-gray-700">
        <nav class="-mb-px flex flex-wrap gap-4">
            <button type="button" @click="tab = 'overview'" class="border-b-2 px-1 py-3 text-sm font-medium"
                    :class="tab === 'overview' ? 'border-brand-500 text-brand-600 dark:text-brand-400' : 'border-transparent text-[#787774] hover:text-[#111111] dark:text-gray-400 dark:hover:text-gray-100'">
                Overview
            </button>
            <button type="button" @click="tab = 'members'" class="border-b-2 px-1 py-3 text-sm font-medium"
                    :class="tab === 'members' ? 'border-brand-500 text-brand-600 dark:text-brand-400' : 'border-transparent text-[#787774] hover:text-[#111111] dark:text-gray-400 dark:hover:text-gray-100'">
                Members
            </button>
        </nav>
    </div>

    <!-- Overview -->
    <div x-show="tab === 'overview'" class="mt-6 space-y-6">
        <div class="rounded-lg border border-[#EAEAEA] bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
            <p class="text-sm text-[#787774] dark:text-gray-400"><?= esc((string) ($workspace->description ?? '') ?: 'No description.') ?></p>
            <dl class="mt-4 flex flex-wrap gap-6 text-sm">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-[#787774] dark:text-gray-400">Owner</dt>
                    <dd class="mt-0.5 font-medium text-gray-800 dark:text-white/90"><?= esc($ownerName) ?></dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-[#787774] dark:text-gray-400">Created</dt>
                    <dd class="mt-0.5 font-medium text-gray-800 dark:text-white/90"><?= esc((string) ($workspace->created_at ?? '—')) ?></dd>
                </div>
            </dl>
        </div>

        <div>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Projects</h2>
                <?php if ($canManage) : ?>
                    <button type="button" class="btn-secondary" @click="openCreateProject()">+ New project</button>
                <?php endif ?>
            </div>
            <?php if ($projects === []) : ?>
                <div class="mt-3 rounded-lg border border-dashed border-[#EAEAEA] bg-white px-6 py-10 text-center dark:border-gray-700 dark:bg-gray-800">
                    <p class="text-sm text-[#787774] dark:text-gray-400">No projects yet.</p>
                </div>
            <?php else : ?>
                <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <?php foreach ($projects as $p) : ?>
                        <a href="/projects/<?= (int) $p->id ?>" class="block rounded-lg border border-[#EAEAEA] bg-white p-4 hover:border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:hover:border-gray-600">
                            <div class="flex items-start justify-between gap-2">
                                <h3 class="font-medium text-gray-800 dark:text-white/90"><?= esc($p->name ?? '') ?></h3>
                                <span class="shrink-0 rounded-full bg-[#F7F6F3] px-2 py-0.5 text-xs font-medium uppercase tracking-wide text-[#787774] dark:bg-gray-700 dark:text-gray-300">
                                    <?= esc((string) ($p->status ?? '')) ?>
                                </span>
                            </div>
                            <p class="mt-1 line-clamp-2 text-sm text-[#787774] dark:text-gray-400"><?= esc((string) ($p->description ?? '') ?: 'No description.') ?></p>
                        </a>
                    <?php endforeach ?>
                </div>
            <?php endif ?>
        </div>
    </div>

    <!-- Members -->
    <div x-show="tab === 'members'" x-cloak class="mt-6 grid grid-cols-1 gap-6<?= $canManage ? ' lg:grid-cols-3' : '' ?>">
        <div class="<?= $canManage ? 'lg:col-span-2 ' : '' ?>overflow-x-auto rounded-lg border border-[#EAEAEA] bg-white dark:border-gray-700 dark:bg-gray-800">
            <table class="min-w-full divide-y divide-[#EAEAEA] text-sm dark:divide-gray-700">
                <thead>
                    <tr class="text-left text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Email</th>
                        <th class="px-4 py-3">Role</th>
                        <?php if ($canManage) : ?>
                            <th class="px-4 py-3 text-right">Actions</th>
                        <?php endif ?>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#EAEAEA] dark:divide-gray-700">
                    <?php foreach ($members as $m) :
                        $memberRole = (string) ($m->role ?? '');
                        $canRemove  = $canManage && ! ($memberRole === 'owner' && $ownerCount <= 1);
                        ?>
                        <tr>
                            <td class="px-4 py-3 font-medium"><?= esc($m->name ?? '') ?></td>
                            <td class="px-4 py-3 text-[#787774] dark:text-gray-400"><?= esc($m->email ?? '') ?></td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium uppercase tracking-wide <?= $roleBadge($memberRole) ?>">
                                    <?= esc($memberRole) ?>
                                </span>
                            </td>
                            <?php if ($canManage) : ?>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <?php if ($canRemove) : ?>
                                        <button type="button" class="text-sm font-medium text-[#9F2F2D] hover:underline dark:text-red-400"
                                                @click="confirmRemoveMember(<?= (int) $m->id ?>, <?= esc(json_encode($m->name ?? ''), 'attr') ?>)">
                                            Remove
                                        </button>
                                    <?php endif ?>
                                </td>
                            <?php endif ?>
                        </tr>
                    <?php endforeach ?>
                    <?php if ($members === []) : ?>
                        <tr>
                            <td colspan="<?= $canManage ? 4 : 3 ?>" class="px-4 py-10 text-center text-sm text-[#787774] dark:text-gray-400">No members yet.</td>
                        </tr>
                    <?php endif ?>
                </tbody>
            </table>
        </div>

        <?php if ($canManage) : ?>
            <div class="space-y-6">
                <div class="rounded-lg border border-[#EAEAEA] bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Add member</h2>
                    <form @submit.prevent="submitAddMember" class="mt-4 space-y-4">
                        <div>
                            <label for="add_user_id" class="form-label">User</label>
                            <select id="add_user_id" x-model="addUserId" required class="form-select">
                                <option value="">Select a user…</option>
                                <?php foreach ($availableUsers as $u) : ?>
                                    <option value="<?= (int) $u->id ?>"><?= esc(($u->name ?? '') . ' (' . ($u->email ?? '') . ')') ?></option>
                                <?php endforeach ?>
                            </select>
                            <?php if ($availableUsers === []) : ?>
                                <p class="mt-1 text-xs text-[#787774] dark:text-gray-400">No more users available to add.</p>
                            <?php endif ?>
                        </div>
                        <div>
                            <label for="add_user_role" class="form-label">Role</label>
                            <select id="add_user_role" x-model="addRole" class="form-select">
                                <option value="member">Member</option>
                                <option value="admin">Admin</option>
                                <option value="viewer">Viewer</option>
                            </select>
                        </div>
                        <button type="submit" :disabled="addingMember || !addUserId" class="btn-primary w-full">
                            <span x-show="!addingMember">Add member</span>
                            <span x-show="addingMember" x-cloak>Adding…</span>
                        </button>
                    </form>
                </div>

                <div class="rounded-lg border border-[#EAEAEA] bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Add group</h2>
                    <p class="mt-1 text-xs text-[#787774] dark:text-gray-400">Adds every member of the group as a workspace member.</p>
                    <form @submit.prevent="submitAddGroup" class="mt-4 space-y-4">
                        <div>
                            <label for="add_group_id" class="form-label">Group</label>
                            <select id="add_group_id" x-model="addGroupId" required class="form-select">
                                <option value="">Select a group…</option>
                                <?php foreach ($availableGroups as $g) : ?>
                                    <option value="<?= (int) $g->id ?>"><?= esc($g->name ?? '') ?></option>
                                <?php endforeach ?>
                            </select>
                            <?php if ($availableGroups === []) : ?>
                                <p class="mt-1 text-xs text-[#787774] dark:text-gray-400">No groups available.</p>
                            <?php endif ?>
                        </div>
                        <button type="submit" :disabled="addingGroup || !addGroupId" class="btn-primary w-full">
                            <span x-show="!addingGroup">Add group</span>
                            <span x-show="addingGroup" x-cloak>Adding…</span>
                        </button>
                    </form>
                </div>
            </div>
        <?php endif ?>
    </div>

    <!-- New project modal -->
    <?php if ($canManage) : ?>
        <div x-show="createProjectOpen" x-cloak class="fixed inset-0 z-40 flex items-center justify-center px-4"
             @keydown.escape.window="createProjectOpen = false">
            <div class="fixed inset-0 bg-black/40" @click="createProjectOpen = false"></div>
            <div x-show="createProjectOpen" x-transition
                 class="modal-panel relative max-w-md"
                 x-trap.noscroll="createProjectOpen" role="dialog" aria-modal="true" aria-labelledby="create-project-title">
                <button type="button" class="absolute right-4 top-4 rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:text-gray-500 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                        @click="createProjectOpen = false" aria-label="Close">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
                <h2 id="create-project-title" class="pr-8 text-lg font-semibold text-gray-800 dark:text-white/90">New project</h2>
                <form @submit.prevent="submitCreateProject" class="mt-4 space-y-4">
                    <div>
                        <label for="create_project_name" class="form-label">Name</label>
                        <input id="create_project_name" type="text" x-model="createProjectForm.name" required maxlength="150" class="form-input">
                    </div>
                    <div>
                        <label for="create_project_description" class="form-label">Description</label>
                        <textarea id="create_project_description" x-model="createProjectForm.description" rows="3" class="form-textarea"></textarea>
                    </div>
                    <div>
                        <label for="create_project_color" class="form-label">Color</label>
                        <input id="create_project_color" type="color" x-model="createProjectForm.color" class="form-input h-10 w-16 p-1">
                    </div>
                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" class="btn-secondary" @click="createProjectOpen = false">Cancel</button>
                        <button type="submit" :disabled="creatingProject" class="btn-primary">
                            <span x-show="!creatingProject">Create</span>
                            <span x-show="creatingProject" x-cloak>Creating…</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif ?>

    <!-- Edit modal -->
    <?php if ($canManage) : ?>
        <div x-show="editOpen" x-cloak class="fixed inset-0 z-40 flex items-center justify-center px-4"
             @keydown.escape.window="editOpen = false">
            <div class="fixed inset-0 bg-black/40" @click="editOpen = false"></div>
            <div x-show="editOpen" x-transition
                 class="modal-panel relative max-w-md"
                 x-trap.noscroll="editOpen" role="dialog" aria-modal="true" aria-labelledby="edit-workspace-title">
                <button type="button" class="absolute right-4 top-4 rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:text-gray-500 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                        @click="editOpen = false" aria-label="Close">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
                <h2 id="edit-workspace-title" class="pr-8 text-lg font-semibold text-gray-800 dark:text-white/90">Edit workspace</h2>
                <form @submit.prevent="submitEdit" class="mt-4 space-y-4">
                    <div>
                        <label for="edit_name" class="form-label">Name</label>
                        <input id="edit_name" type="text" x-model="editForm.name" required maxlength="150" class="form-input">
                    </div>
                    <div>
                        <label for="edit_description" class="form-label">Description</label>
                        <textarea id="edit_description" x-model="editForm.description" rows="3" class="form-textarea"></textarea>
                    </div>
                    <div>
                        <label for="edit_visibility" class="form-label">Visibility</label>
                        <select id="edit_visibility" x-model="editForm.visibility" class="form-select">
                            <option value="private">Private — invite only</option>
                            <option value="company">Company — anyone can discover & join</option>
                        </select>
                    </div>
                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" class="btn-secondary" @click="editOpen = false">Cancel</button>
                        <button type="submit" :disabled="editing" class="btn-primary">
                            <span x-show="!editing">Save</span>
                            <span x-show="editing" x-cloak>Saving…</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif ?>

    <script>
        function workspaceShow(workspaceId) {
            return {
                workspaceId: workspaceId,
                tab: 'overview',

                editOpen: false,
                editing: false,
                editForm: {
                    name: <?= json_encode($workspace->name ?? '') ?>,
                    description: <?= json_encode($workspace->description ?? '') ?>,
                    visibility: <?= json_encode($workspace->visibility ?? 'private') ?>,
                },

                addingMember: false,
                addUserId: '',
                addRole: 'member',
                addingGroup: false,
                addGroupId: '',

                creatingProject: false,
                createProjectOpen: false,
                createProjectForm: { name: '', description: '', color: '#465fff' },

                openEdit() {
                    this.editOpen = true;
                },

                openCreateProject() {
                    this.createProjectForm = { name: '', description: '', color: '#465fff' };
                    this.createProjectOpen = true;
                },

                async submitCreateProject() {
                    this.creatingProject = true;
                    const json = await postForm('/workspaces/' + this.workspaceId + '/projects/create', this.createProjectForm);
                    this.creatingProject = false;
                    if (!json) return;
                    if (json.status === 'success') {
                        this.$dispatch('toast', { type: 'success', text: json.message });
                        window.location.href = '/projects/' + json.id;
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not create the project.' });
                    }
                },

                async submitEdit() {
                    this.editing = true;
                    const json = await postForm('/workspaces/update/' + this.workspaceId, this.editForm);
                    this.editing = false;
                    if (!json) return;
                    if (json.status === 'success') {
                        this.$dispatch('toast', { type: 'success', text: json.message });
                        window.location.reload();
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not update the workspace.' });
                    }
                },

                confirmDelete() {
                    this.$dispatch('confirm', {
                        title: 'Delete workspace',
                        body: 'This will remove the workspace for everyone. This cannot be undone.',
                        variant: 'danger',
                        confirmLabel: 'Delete',
                        onConfirm: async () => {
                            const json = await postForm('/workspaces/delete/' + this.workspaceId, {});
                            if (!json) return;
                            if (json.status === 'success') {
                                this.$dispatch('toast', { type: 'success', text: json.message });
                                window.location.href = '/workspaces';
                            } else {
                                this.$dispatch('toast', { type: 'error', text: json.message || 'Could not delete the workspace.' });
                            }
                        },
                    });
                },

                confirmLeave() {
                    this.$dispatch('confirm', {
                        title: 'Leave workspace',
                        body: 'You will lose access to this workspace and its projects.',
                        variant: 'danger',
                        confirmLabel: 'Leave',
                        onConfirm: async () => {
                            const json = await postForm('/workspaces/' + this.workspaceId + '/leave', {});
                            if (!json) return;
                            if (json.status === 'success') {
                                this.$dispatch('toast', { type: 'success', text: json.message });
                                window.location.href = '/workspaces';
                            } else {
                                this.$dispatch('toast', { type: 'error', text: json.message || 'Could not leave the workspace.' });
                            }
                        },
                    });
                },

                async submitAddMember() {
                    if (!this.addUserId) return;
                    this.addingMember = true;
                    const json = await postForm('/workspaces/' + this.workspaceId + '/members/add', { user_id: this.addUserId, role: this.addRole });
                    this.addingMember = false;
                    if (!json) return;
                    if (json.status === 'success') {
                        this.$dispatch('toast', { type: 'success', text: json.message });
                        window.location.reload();
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not add member.' });
                    }
                },

                async submitAddGroup() {
                    if (!this.addGroupId) return;
                    this.addingGroup = true;
                    const json = await postForm('/workspaces/' + this.workspaceId + '/members/add-group', { group_id: this.addGroupId });
                    this.addingGroup = false;
                    if (!json) return;
                    if (json.status === 'success') {
                        this.$dispatch('toast', { type: 'success', text: json.message });
                        window.location.reload();
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not add group.' });
                    }
                },

                confirmRemoveMember(userId, name) {
                    this.$dispatch('confirm', {
                        title: 'Remove member',
                        body: 'Remove "' + name + '" from this workspace?',
                        variant: 'danger',
                        confirmLabel: 'Remove',
                        onConfirm: async () => {
                            const json = await postForm('/workspaces/' + this.workspaceId + '/members/remove', { user_id: userId });
                            if (!json) return;
                            if (json.status === 'success') {
                                this.$dispatch('toast', { type: 'success', text: json.message });
                                window.location.reload();
                            } else {
                                this.$dispatch('toast', { type: 'error', text: json.message || 'Could not remove member.' });
                            }
                        },
                    });
                },
            };
        }
    </script>
</div>
<?php $this->endSection() ?>
