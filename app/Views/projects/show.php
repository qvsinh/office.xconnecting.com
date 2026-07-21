<?php
/**
 * Single project shell: header (workspace breadcrumb, status badge, color dot,
 * edit/delete), Alpine tabs — Overview (description, meta, members panel),
 * Board (kanban — `projects/board` partial, lazy-loaded on first open), and
 * Files (drag-or-click upload zone + file list).
 *
 * @var object       $project         id/name/slug/description/status/color/workspace_id/owner_id/created_at
 * @var object       $workspace       id/name
 * @var string|null  $role            caller's role in this project
 * @var bool         $canEdit
 * @var list<object> $members         id/name/email/role
 * @var list<object> $availableUsers  id/name/email — not yet a member
 * @var list<object> $files           id/original_name/mime/size/uploader_name/created_at
 */
$this->extend('layouts/app');

$roleBadge = static fn (string $role): string => match ($role) {
    'owner'  => 'bg-[#FBF3DB] text-[#956400] dark:bg-yellow-900/30 dark:text-yellow-300',
    'admin'  => 'bg-[#E1F3FE] text-[#1F6C9F] dark:bg-blue-900/30 dark:text-blue-300',
    'member' => 'bg-[#EDF3EC] text-[#346538] dark:bg-green-900/30 dark:text-green-300',
    default  => 'bg-[#F7F6F3] text-[#787774] dark:bg-gray-700 dark:text-gray-300',
};

$statusBadge = ($project->status ?? 'active') === 'archived'
    ? 'bg-[#F7F6F3] text-[#787774] dark:bg-gray-700 dark:text-gray-300'
    : 'bg-[#EDF3EC] text-[#346538] dark:bg-green-900/30 dark:text-green-300';

// Only render the color dot for a value that looks like a real hex color —
// never trust arbitrary text into a style attribute.
$colorHex = (string) ($project->color ?? '');
$colorHex = preg_match('/^#[0-9a-fA-F]{3,8}$/', $colorHex) ? $colorHex : null;

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
<div class="mx-auto max-w-6xl" x-data="projectShow(<?= (int) $project->id ?>, <?= (int) $workspace->id ?>)">

    <nav class="flex flex-wrap items-center gap-1 text-sm text-[#787774] dark:text-gray-400">
        <a href="/workspaces" class="hover:text-[#111111] dark:hover:text-gray-100">Workspaces</a>
        <span>/</span>
        <a href="/workspaces/<?= (int) $workspace->id ?>" class="hover:text-[#111111] dark:hover:text-gray-100"><?= esc($workspace->name ?? '') ?></a>
        <span>/</span>
        <span class="text-[#111111] dark:text-gray-100"><?= esc($project->name ?? '') ?></span>
    </nav>

    <div class="mt-2 flex flex-wrap items-start justify-between gap-3">
        <div class="flex items-center gap-2">
            <?php if ($colorHex !== null) : ?>
                <span class="h-3 w-3 shrink-0 rounded-full" style="background-color: <?= esc($colorHex, 'attr') ?>" aria-hidden="true"></span>
            <?php endif ?>
            <h1 class="text-xl font-semibold tracking-tight"><?= esc($project->name ?? '') ?></h1>
            <span class="rounded-full px-2 py-0.5 text-xs font-medium uppercase tracking-wide <?= $statusBadge ?>">
                <?= esc((string) ($project->status ?? 'active')) ?>
            </span>
        </div>
        <?php if ($canEdit) : ?>
            <div class="flex flex-wrap gap-2">
                <button type="button" class="btn-secondary" @click="openEdit()">Edit</button>
                <button type="button" class="btn-danger" @click="confirmDelete()">Delete</button>
            </div>
        <?php endif ?>
    </div>

    <!-- Tabs -->
    <div class="mt-6 border-b border-[#EAEAEA] dark:border-gray-700">
        <nav class="-mb-px flex flex-wrap gap-4">
            <button type="button" @click="tab = 'overview'" class="border-b-2 px-1 py-3 text-sm font-medium"
                    :class="tab === 'overview' ? 'border-brand-500 text-brand-600 dark:text-brand-400' : 'border-transparent text-[#787774] hover:text-[#111111] dark:text-gray-400 dark:hover:text-gray-100'">
                Overview
            </button>
            <button type="button" @click="tab = 'board'" class="border-b-2 px-1 py-3 text-sm font-medium"
                    :class="tab === 'board' ? 'border-brand-500 text-brand-600 dark:text-brand-400' : 'border-transparent text-[#787774] hover:text-[#111111] dark:text-gray-400 dark:hover:text-gray-100'">
                Board
            </button>
            <button type="button" @click="tab = 'files'" class="border-b-2 px-1 py-3 text-sm font-medium"
                    :class="tab === 'files' ? 'border-brand-500 text-brand-600 dark:text-brand-400' : 'border-transparent text-[#787774] hover:text-[#111111] dark:text-gray-400 dark:hover:text-gray-100'">
                Files
            </button>
        </nav>
    </div>

    <!-- Overview -->
    <div x-show="tab === 'overview'" class="mt-6 space-y-6">
        <div class="rounded-lg border border-[#EAEAEA] bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
            <p class="text-sm text-[#787774] dark:text-gray-400"><?= esc((string) ($project->description ?? '') ?: 'No description.') ?></p>
            <dl class="mt-4 flex flex-wrap gap-6 text-sm">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-[#787774] dark:text-gray-400">Owner</dt>
                    <dd class="mt-0.5 font-medium text-gray-800 dark:text-white/90"><?= esc($ownerName) ?></dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-[#787774] dark:text-gray-400">Status</dt>
                    <dd class="mt-0.5 font-medium text-gray-800 dark:text-white/90"><?= esc((string) ($project->status ?? 'active')) ?></dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-[#787774] dark:text-gray-400">Created</dt>
                    <dd class="mt-0.5 font-medium text-gray-800 dark:text-white/90"><?= esc((string) ($project->created_at ?? '—')) ?></dd>
                </div>
            </dl>
        </div>

        <!-- Members panel -->
        <div class="grid grid-cols-1 gap-6<?= $canEdit ? ' lg:grid-cols-3' : '' ?>">
            <div class="<?= $canEdit ? 'lg:col-span-2 ' : '' ?>overflow-x-auto rounded-lg border border-[#EAEAEA] bg-white dark:border-gray-700 dark:bg-gray-800">
                <table class="min-w-full divide-y divide-[#EAEAEA] text-sm dark:divide-gray-700">
                    <thead>
                        <tr class="text-left text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">
                            <th class="px-4 py-3">Name</th>
                            <th class="px-4 py-3">Email</th>
                            <th class="px-4 py-3">Role</th>
                            <?php if ($canEdit) : ?>
                                <th class="px-4 py-3 text-right">Actions</th>
                            <?php endif ?>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#EAEAEA] dark:divide-gray-700">
                        <?php foreach ($members as $m) :
                            $memberRole = (string) ($m->role ?? '');
                            $canRemove  = $canEdit && ! ($memberRole === 'owner' && $ownerCount <= 1);
                            ?>
                            <tr>
                                <td class="px-4 py-3 font-medium"><?= esc($m->name ?? '') ?></td>
                                <td class="px-4 py-3 text-[#787774] dark:text-gray-400"><?= esc($m->email ?? '') ?></td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium uppercase tracking-wide <?= $roleBadge($memberRole) ?>">
                                        <?= esc($memberRole) ?>
                                    </span>
                                </td>
                                <?php if ($canEdit) : ?>
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
                                <td colspan="<?= $canEdit ? 4 : 3 ?>" class="px-4 py-10 text-center text-sm text-[#787774] dark:text-gray-400">No members yet.</td>
                            </tr>
                        <?php endif ?>
                    </tbody>
                </table>
            </div>

            <?php if ($canEdit) : ?>
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
            <?php endif ?>
        </div>
    </div>

    <!-- Board -->
    <div x-show="tab === 'board'" x-cloak class="mt-6">
        <?= $this->include('projects/board') ?>
    </div>

    <!-- Files -->
    <div x-show="tab === 'files'" x-cloak class="mt-6 space-y-6">
        <div>
            <input type="file" class="hidden" x-ref="fileInput" @change="uploadFile($event.target.files[0]); $event.target.value = ''">
            <div class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed border-[#EAEAEA] bg-white px-6 py-10 text-center transition-colors hover:border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:hover:border-gray-600"
                 :class="uploading ? 'pointer-events-none opacity-60' : ''"
                 role="button" tabindex="0" aria-label="Upload a file"
                 @click="$refs.fileInput.click()"
                 @keydown.enter.prevent="$refs.fileInput.click()"
                 @keydown.space.prevent="$refs.fileInput.click()"
                 @dragover.prevent @dragenter.prevent
                 @drop.prevent="uploadFile($event.dataTransfer.files[0])">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-[#787774] dark:text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0l-4 4m4-4l4 4M4 16v3a2 2 0 002 2h12a2 2 0 002-2v-3" />
                </svg>
                <p class="text-sm font-medium text-gray-800 dark:text-white/90" x-show="!uploading">Drag a file here, or click to browse</p>
                <p class="text-sm font-medium text-gray-800 dark:text-white/90" x-show="uploading" x-cloak>Uploading…</p>
                <p class="text-xs text-[#787774] dark:text-gray-400">Max 10 MB. Docs, images and archives only.</p>
            </div>
        </div>

        <div class="overflow-hidden rounded-lg border border-[#EAEAEA] bg-white dark:border-gray-700 dark:bg-gray-800">
            <template x-if="files.length === 0">
                <p class="px-4 py-10 text-center text-sm text-[#787774] dark:text-gray-400">No files yet.</p>
            </template>
            <ul class="divide-y divide-[#EAEAEA] dark:divide-gray-700">
                <template x-for="file in files" :key="file.id">
                    <li class="flex flex-wrap items-center gap-3 px-4 py-3">
                        <span x-html="fileIcon(file.mime)" class="shrink-0 text-[#787774] dark:text-gray-400"></span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-gray-800 dark:text-white/90" x-text="file.original_name"></p>
                            <p class="text-xs text-[#787774] dark:text-gray-400">
                                <span x-text="formatBytes(file.size)"></span>
                                &middot; <span x-text="file.uploader_name"></span>
                                &middot; <span x-text="file.created_at"></span>
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-3">
                            <a :href="'/projects/files/download/' + file.id" class="text-sm font-medium text-[#111111] hover:underline dark:text-gray-100">Download</a>
                            <?php if ($canEdit) : ?>
                                <button type="button" class="text-sm font-medium text-[#9F2F2D] hover:underline dark:text-red-400" @click="confirmDeleteFile(file)">Delete</button>
                            <?php endif ?>
                        </div>
                    </li>
                </template>
            </ul>
        </div>
    </div>

    <!-- Edit modal -->
    <?php if ($canEdit) : ?>
        <div x-show="editOpen" x-cloak class="fixed inset-0 z-40 flex items-center justify-center px-4"
             @keydown.escape.window="editOpen = false">
            <div class="fixed inset-0 bg-black/40" @click="editOpen = false"></div>
            <div x-show="editOpen" x-transition
                 class="modal-panel relative max-w-md"
                 x-trap.noscroll="editOpen" role="dialog" aria-modal="true" aria-labelledby="edit-project-title">
                <button type="button" class="absolute right-4 top-4 rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:text-gray-500 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                        @click="editOpen = false" aria-label="Close">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
                <h2 id="edit-project-title" class="pr-8 text-lg font-semibold text-gray-800 dark:text-white/90">Edit project</h2>
                <form @submit.prevent="submitEdit" class="mt-4 space-y-4">
                    <div>
                        <label for="edit_name" class="form-label">Name</label>
                        <input id="edit_name" type="text" x-model="editForm.name" required maxlength="150" class="form-input">
                    </div>
                    <div>
                        <label for="edit_description" class="form-label">Description</label>
                        <textarea id="edit_description" x-model="editForm.description" rows="3" class="form-textarea"></textarea>
                    </div>
                    <div class="flex gap-4">
                        <div class="flex-1">
                            <label for="edit_status" class="form-label">Status</label>
                            <select id="edit_status" x-model="editForm.status" class="form-select">
                                <option value="active">Active</option>
                                <option value="archived">Archived</option>
                            </select>
                        </div>
                        <div>
                            <label for="edit_color" class="form-label">Color</label>
                            <input id="edit_color" type="color" x-model="editForm.color" class="form-input h-10 w-16 p-1">
                        </div>
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
        function projectShow(projectId, workspaceId) {
            return {
                projectId: projectId,
                workspaceId: workspaceId,
                tab: 'overview',

                files: <?= json_encode($files, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                uploading: false,

                editOpen: false,
                editing: false,
                editForm: {
                    name: <?= json_encode($project->name ?? '') ?>,
                    description: <?= json_encode($project->description ?? '') ?>,
                    status: <?= json_encode($project->status ?? 'active') ?>,
                    color: <?= json_encode($colorHex ?? '#465fff') ?>,
                },

                addingMember: false,
                addUserId: '',
                addRole: 'member',

                openEdit() {
                    this.editOpen = true;
                },

                async submitEdit() {
                    this.editing = true;
                    const json = await postForm('/projects/update/' + this.projectId, this.editForm);
                    this.editing = false;
                    if (!json) return;
                    if (json.status === 'success') {
                        this.$dispatch('toast', { type: 'success', text: json.message });
                        window.location.reload();
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not update the project.' });
                    }
                },

                confirmDelete() {
                    this.$dispatch('confirm', {
                        title: 'Delete project',
                        body: 'This will remove the project, its members and files. This cannot be undone.',
                        variant: 'danger',
                        confirmLabel: 'Delete',
                        onConfirm: async () => {
                            const json = await postForm('/projects/delete/' + this.projectId, {});
                            if (!json) return;
                            if (json.status === 'success') {
                                this.$dispatch('toast', { type: 'success', text: json.message });
                                window.location.href = '/workspaces/' + this.workspaceId;
                            } else {
                                this.$dispatch('toast', { type: 'error', text: json.message || 'Could not delete the project.' });
                            }
                        },
                    });
                },

                async submitAddMember() {
                    if (!this.addUserId) return;
                    this.addingMember = true;
                    const json = await postForm('/projects/' + this.projectId + '/members/add', { user_id: this.addUserId, role: this.addRole });
                    this.addingMember = false;
                    if (!json) return;
                    if (json.status === 'success') {
                        this.$dispatch('toast', { type: 'success', text: json.message });
                        window.location.reload();
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not add member.' });
                    }
                },

                confirmRemoveMember(userId, name) {
                    this.$dispatch('confirm', {
                        title: 'Remove member',
                        body: 'Remove "' + name + '" from this project?',
                        variant: 'danger',
                        confirmLabel: 'Remove',
                        onConfirm: async () => {
                            const json = await postForm('/projects/' + this.projectId + '/members/remove', { user_id: userId });
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

                async uploadFile(file) {
                    if (!file || this.uploading) return;
                    this.uploading = true;
                    const body = new FormData();
                    body.append('file', file);
                    body.append(window.csrf.fieldName, window.csrf.token);

                    let json;
                    try {
                        const res = await fetch('/projects/' + this.projectId + '/files/upload', {
                            method: 'POST',
                            headers: { 'X-Requested-With': 'XMLHttpRequest' },
                            body,
                        });
                        json = await res.json();
                    } catch (e) {
                        this.uploading = false;
                        this.$dispatch('toast', { type: 'error', text: 'Network error. Please try again.' });
                        return;
                    }
                    this.uploading = false;
                    if (json.csrf_hash) window.csrf.token = json.csrf_hash;

                    if (json.status === 'success') {
                        this.files.push(json.file);
                        this.$dispatch('toast', { type: 'success', text: json.message || 'File uploaded.' });
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not upload the file.' });
                    }
                },

                confirmDeleteFile(file) {
                    this.$dispatch('confirm', {
                        title: 'Delete file',
                        body: 'Remove "' + file.original_name + '"? This cannot be undone.',
                        variant: 'danger',
                        confirmLabel: 'Delete',
                        onConfirm: async () => {
                            const json = await postForm('/projects/files/delete/' + file.id, {});
                            if (!json) return;
                            if (json.status === 'success') {
                                this.files = this.files.filter(f => f.id !== file.id);
                                this.$dispatch('toast', { type: 'success', text: json.message });
                            } else {
                                this.$dispatch('toast', { type: 'error', text: json.message || 'Could not delete the file.' });
                            }
                        },
                    });
                },

                formatBytes(bytes) {
                    if (!bytes) return '0 B';
                    const units = ['B', 'KB', 'MB', 'GB'];
                    const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
                    return (bytes / Math.pow(1024, i)).toFixed(i === 0 ? 0 : 1) + ' ' + units[i];
                },

                fileIcon(mime) {
                    mime = mime || '';
                    if (mime.startsWith('image/')) {
                        return '<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="16" rx="2"/><path stroke-linecap="round" stroke-linejoin="round" d="M3 16l5-5 4 4 4-5 5 6"/><circle cx="8" cy="8.5" r="1.5"/></svg>';
                    }
                    if (mime === 'application/zip' || mime.includes('compressed') || mime.includes('archive')) {
                        return '<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="4" y="3" width="16" height="18" rx="2"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18M9 6h1.5M9 9h1.5M9 12h1.5M9 15h1.5"/></svg>';
                    }
                    return '<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M7 3h7l5 5v11a2 2 0 01-2 2H7a2 2 0 01-2-2V5a2 2 0 012-2z"/><path stroke-linecap="round" stroke-linejoin="round" d="M14 3v5h5"/></svg>';
                },
            };
        }
    </script>
</div>
<?php $this->endSection() ?>
