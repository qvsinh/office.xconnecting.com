<?php
/**
 * Workspaces landing: "My workspaces" card grid (owned/joined/company-wide)
 * plus a "Discover" section for company workspaces not yet joined. Create
 * via a shared Alpine modal — mirrors `admin/settings/index.php`'s
 * create-modal pattern.
 *
 * @var list<object> $mine         id/name/description/role/member_count/project_count
 * @var list<object> $discoverable id/name/description/member_count
 */
$this->extend('layouts/app');

$roleBadge = static fn (string $role): string => match ($role) {
    'owner'  => 'bg-[#FBF3DB] text-[#956400] dark:bg-yellow-900/30 dark:text-yellow-300',
    'admin'  => 'bg-[#E1F3FE] text-[#1F6C9F] dark:bg-blue-900/30 dark:text-blue-300',
    'member' => 'bg-[#EDF3EC] text-[#346538] dark:bg-green-900/30 dark:text-green-300',
    default  => 'bg-[#F7F6F3] text-[#787774] dark:bg-gray-700 dark:text-gray-300',
};
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-7xl" x-data="workspacesPage()">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Workspaces</h1>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">Collaboration spaces you own or belong to.</p>
        </div>
        <button type="button" @click="openCreate()" class="btn-primary">
            + New workspace
        </button>
    </div>

    <?php if ($mine === []) : ?>
        <div class="mt-6 rounded-lg border border-dashed border-[#EAEAEA] bg-white px-6 py-14 text-center dark:border-gray-700 dark:bg-gray-800">
            <p class="text-sm font-medium text-gray-800 dark:text-white/90">You're not in any workspace yet.</p>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">Create one to start organizing projects and tasks with your team.</p>
            <button type="button" @click="openCreate()" class="btn-primary mt-4">+ New workspace</button>
        </div>
    <?php else : ?>
        <div class="mt-6 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($mine as $ws) :
                $memberCount  = (int) ($ws->member_count ?? 0);
                $projectCount = (int) ($ws->project_count ?? 0);
                ?>
                <div class="flex flex-col rounded-lg border border-[#EAEAEA] bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                    <div class="flex items-start justify-between gap-2">
                        <h2 class="font-semibold text-gray-800 dark:text-white/90"><?= esc($ws->name ?? '') ?></h2>
                        <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium uppercase tracking-wide <?= $roleBadge((string) ($ws->role ?? '')) ?>">
                            <?= esc((string) ($ws->role ?? '')) ?>
                        </span>
                    </div>
                    <p class="mt-2 line-clamp-2 text-sm text-[#787774] dark:text-gray-400"><?= esc((string) ($ws->description ?? '') ?: 'No description.') ?></p>
                    <div class="mt-4 flex items-center gap-4 text-xs text-[#787774] dark:text-gray-400">
                        <span><?= esc((string) $memberCount) ?> member<?= $memberCount === 1 ? '' : 's' ?></span>
                        <span><?= esc((string) $projectCount) ?> project<?= $projectCount === 1 ? '' : 's' ?></span>
                    </div>
                    <a href="/workspaces/<?= (int) $ws->id ?>" class="btn-secondary mt-4 w-full text-center">Open</a>
                </div>
            <?php endforeach ?>
        </div>
    <?php endif ?>

    <?php if ($discoverable !== []) : ?>
        <div class="mt-6">
            <h2 class="text-base font-semibold tracking-tight">Discover</h2>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">Company-wide workspaces you can join.</p>
            <div class="mt-4 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <?php foreach ($discoverable as $ws) :
                    $memberCount = (int) ($ws->member_count ?? 0);
                    ?>
                    <div class="flex flex-col rounded-lg border border-[#EAEAEA] bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                        <h3 class="font-semibold text-gray-800 dark:text-white/90"><?= esc($ws->name ?? '') ?></h3>
                        <p class="mt-2 line-clamp-2 text-sm text-[#787774] dark:text-gray-400"><?= esc((string) ($ws->description ?? '') ?: 'No description.') ?></p>
                        <p class="mt-4 text-xs text-[#787774] dark:text-gray-400"><?= esc((string) $memberCount) ?> member<?= $memberCount === 1 ? '' : 's' ?></p>
                        <button type="button" class="btn-primary mt-4 w-full" :disabled="joining === <?= (int) $ws->id ?>" @click="join(<?= (int) $ws->id ?>)">
                            <span x-show="joining !== <?= (int) $ws->id ?>">Join</span>
                            <span x-show="joining === <?= (int) $ws->id ?>" x-cloak>Joining…</span>
                        </button>
                    </div>
                <?php endforeach ?>
            </div>
        </div>
    <?php endif ?>

    <!-- Create modal -->
    <div x-show="createOpen" x-cloak class="fixed inset-0 z-40 flex items-center justify-center px-4"
         @keydown.escape.window="createOpen = false">
        <div class="fixed inset-0 bg-black/40" @click="createOpen = false"></div>
        <div x-show="createOpen" x-transition
             class="modal-panel relative max-w-md"
             x-trap.noscroll="createOpen" role="dialog" aria-modal="true" aria-labelledby="create-workspace-title">
            <button type="button" class="absolute right-4 top-4 rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:text-gray-500 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                    @click="createOpen = false" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <h2 id="create-workspace-title" class="pr-8 text-lg font-semibold text-gray-800 dark:text-white/90">New workspace</h2>
            <form @submit.prevent="submitCreate" class="mt-4 space-y-4">
                <div>
                    <label for="create_name" class="form-label">Name</label>
                    <input id="create_name" type="text" x-model="createForm.name" required maxlength="150" class="form-input">
                </div>
                <div>
                    <label for="create_description" class="form-label">Description</label>
                    <textarea id="create_description" x-model="createForm.description" rows="3" class="form-textarea"></textarea>
                </div>
                <div>
                    <label for="create_visibility" class="form-label">Visibility</label>
                    <select id="create_visibility" x-model="createForm.visibility" class="form-select">
                        <option value="private">Private — invite only</option>
                        <option value="company">Company — anyone can discover & join</option>
                    </select>
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" class="btn-secondary" @click="createOpen = false">Cancel</button>
                    <button type="submit" :disabled="creating" class="btn-primary">
                        <span x-show="!creating">Create</span>
                        <span x-show="creating" x-cloak>Creating…</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function workspacesPage() {
            return {
                creating: false,
                createOpen: false,
                createForm: { name: '', description: '', visibility: 'private' },
                joining: null,

                openCreate() {
                    this.createForm = { name: '', description: '', visibility: 'private' };
                    this.createOpen = true;
                },

                async submitCreate() {
                    this.creating = true;
                    const json = await postForm('/workspaces/create', this.createForm);
                    this.creating = false;
                    if (!json) return;
                    if (json.status === 'success' && json.id) {
                        window.location.href = '/workspaces/' + json.id;
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not create the workspace.' });
                    }
                },

                async join(id) {
                    this.joining = id;
                    const json = await postForm('/workspaces/' + id + '/join', {});
                    this.joining = null;
                    if (!json) return;
                    if (json.status === 'success') {
                        this.$dispatch('toast', { type: 'success', text: json.message || 'Joined the workspace.' });
                        window.location.reload();
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not join the workspace.' });
                    }
                },
            };
        }
    </script>
</div>
<?php $this->endSection() ?>
