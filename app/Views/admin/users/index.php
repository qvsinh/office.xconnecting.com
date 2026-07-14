<?php
/**
 * Admin user management: search + paginate every account, create/edit via a
 * shared Alpine modal, and act-as impersonation. API-key management for a
 * user lives at /admin/api-access-keys (link only — see detail.php, not
 * rebuilt here).
 *
 * @var list<object>                                      $items   searchAdmin() rows (id/name/email/company/zone/env/status/login_at/created_at — no password)
 * @var int                                               $total
 * @var int                                               $page
 * @var int                                               $limit
 * @var string                                            $sort
 * @var string                                            $order
 * @var array{keyword:string, zone:string, status:string} $filters
 */
$this->extend('layouts/app');

$totalPages = max(1, (int) ceil($total / max($limit, 1)));

$queryBase = [
    'keyword' => $filters['keyword'] ?? '',
    'zone'    => $filters['zone'] ?? '',
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
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-7xl" x-data="usersAdmin()">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Users</h1>
            <p class="mt-1 text-sm text-[#787774]">Manage every account, then act as a user to see their view.</p>
        </div>
        <button type="button" @click="openCreate()"
                class="rounded-md bg-[#111111] px-4 py-2 text-sm font-medium text-white hover:bg-[#333333]">
            + Add user
        </button>
    </div>

    <form method="get" class="mt-6 flex flex-wrap items-end gap-3 rounded-lg border border-[#EAEAEA] bg-white p-4">
        <div class="min-w-[220px] flex-1">
            <label for="keyword" class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-[#787774]">Search</label>
            <input type="text" id="keyword" name="keyword" value="<?= esc($filters['keyword'] ?? '', 'attr') ?>"
                   placeholder="Name, email, or company"
                   class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111]">
        </div>
        <div>
            <label for="zone" class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-[#787774]">Zone</label>
            <select id="zone" name="zone" class="rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111]">
                <option value="">Any</option>
                <option value="global" <?= ($filters['zone'] ?? '') === 'global' ? 'selected' : '' ?>>Admin</option>
                <option value="client" <?= ($filters['zone'] ?? '') === 'client' ? 'selected' : '' ?>>Client</option>
            </select>
        </div>
        <div>
            <label for="status" class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-[#787774]">Status</label>
            <select id="status" name="status" class="rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111]">
                <option value="">Any</option>
                <option value="1" <?= ($filters['status'] ?? '') === '1' ? 'selected' : '' ?>>Active</option>
                <option value="0" <?= ($filters['status'] ?? '') === '0' ? 'selected' : '' ?>>Inactive</option>
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
        <input type="hidden" name="sort" value="<?= esc($sort, 'attr') ?>">
        <input type="hidden" name="order" value="<?= esc($order, 'attr') ?>">
        <button type="submit" class="rounded-md border border-[#EAEAEA] px-4 py-2 text-sm font-medium hover:bg-gray-50">Search</button>
        <a href="?limit=<?= esc((string) $limit, 'attr') ?>" class="text-sm text-[#787774] hover:text-[#111111]">Clear</a>
    </form>

    <div class="mt-6 overflow-x-auto rounded-lg border border-[#EAEAEA] bg-white">
        <table class="min-w-full divide-y divide-[#EAEAEA] text-sm">
            <thead>
                <tr class="text-left text-xs font-medium uppercase tracking-wide text-[#787774]">
                    <th class="px-4 py-3"><a href="<?= esc($sortUrl('name'), 'attr') ?>" class="hover:text-[#111111]">User<?= esc($sortMark('name')) ?></a></th>
                    <th class="px-4 py-3"><a href="<?= esc($sortUrl('zone'), 'attr') ?>" class="hover:text-[#111111]">Zone<?= esc($sortMark('zone')) ?></a></th>
                    <th class="px-4 py-3">Env</th>
                    <th class="px-4 py-3"><a href="<?= esc($sortUrl('status'), 'attr') ?>" class="hover:text-[#111111]">Status<?= esc($sortMark('status')) ?></a></th>
                    <th class="px-4 py-3"><a href="<?= esc($sortUrl('login_at'), 'attr') ?>" class="hover:text-[#111111]">Last login<?= esc($sortMark('login_at')) ?></a></th>
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#EAEAEA]">
                <?php foreach ($items as $item) : ?>
                    <tr>
                        <td class="px-4 py-3">
                            <p class="font-medium"><?= esc($item->name) ?></p>
                            <p class="text-xs text-[#787774]"><?= esc($item->email) ?></p>
                        </td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium uppercase tracking-wide <?= $item->zone === 'global' ? 'bg-[#E1F3FE] text-[#1F6C9F]' : 'bg-gray-100 text-gray-700' ?>">
                                <?= $item->zone === 'global' ? 'Admin' : 'Client' ?>
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium uppercase tracking-wide <?= $item->env === 'LIVE' ? 'bg-[#EDF3EC] text-[#346538]' : 'bg-[#FBF3DB] text-[#956400]' ?>">
                                <?= esc($item->env) ?>
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium uppercase tracking-wide <?= (int) $item->status === 1 ? 'bg-[#EDF3EC] text-[#346538]' : 'bg-[#FDEBEC] text-[#9F2F2D]' ?>">
                                <?= (int) $item->status === 1 ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-xs text-[#787774]"><?= esc($item->login_at ?? '—') ?></td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            <a href="/admin/users/view/<?= (int) $item->id ?>" class="mr-3 text-sm font-medium text-[#111111] hover:underline">View</a>
                            <button type="button" class="mr-3 text-sm font-medium text-[#111111] hover:underline" @click="openEdit(<?= (int) $item->id ?>)">Edit</button>
                            <button type="button" class="text-sm font-medium text-[#1F6C9F] hover:underline"
                                    @click="confirmActAs(<?= (int) $item->id ?>, <?= esc(json_encode($item->name), 'attr') ?>)">Act as</button>
                        </td>
                    </tr>
                <?php endforeach ?>
                <?php if ($items === []) : ?>
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-sm text-[#787774]">No users match your search.</td>
                    </tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex items-center justify-between text-sm text-[#787774]">
        <p><?= esc((string) $total) ?> user<?= $total === 1 ? '' : 's' ?> total</p>
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

    <!-- Create modal -->
    <div x-show="createOpen" x-cloak class="fixed inset-0 z-40 flex items-center justify-center px-4"
         @keydown.escape.window="createOpen = false">
        <div class="fixed inset-0 bg-black/40" @click="createOpen = false"></div>
        <div x-show="createOpen" x-transition
             class="relative w-full max-w-md rounded-lg border border-[#EAEAEA] bg-white p-6 shadow-sm"
             x-trap.noscroll="createOpen" role="dialog" aria-modal="true" aria-labelledby="create-user-title">
            <h2 id="create-user-title" class="text-base font-semibold">Add user</h2>
            <form @submit.prevent="submitCreate" class="mt-4 space-y-4">
                <div>
                    <label for="create_name" class="mb-1.5 block text-sm font-medium">Name</label>
                    <input id="create_name" type="text" x-model="createForm.name" required maxlength="255"
                           class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111]">
                </div>
                <div>
                    <label for="create_email" class="mb-1.5 block text-sm font-medium">Email</label>
                    <input id="create_email" type="email" x-model="createForm.email" required maxlength="255"
                           class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111]">
                </div>
                <div>
                    <label for="create_password" class="mb-1.5 block text-sm font-medium">Password</label>
                    <input id="create_password" type="password" x-model="createForm.password" minlength="5" autocomplete="new-password"
                           class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111]">
                    <p class="mt-1 text-xs text-[#787774]">Leave blank to auto-generate a random password.</p>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="create_zone" class="mb-1.5 block text-sm font-medium">Zone</label>
                        <select id="create_zone" x-model="createForm.zone" required
                                class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111]">
                            <option value="global">Admin (global)</option>
                            <option value="client">Client</option>
                        </select>
                    </div>
                    <div>
                        <label for="create_env" class="mb-1.5 block text-sm font-medium">Environment</label>
                        <select id="create_env" x-model="createForm.env" required
                                class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111]">
                            <option value="LIVE">LIVE</option>
                            <option value="TESTING">TESTING</option>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="create_language" class="mb-1.5 block text-sm font-medium">Language</label>
                        <input id="create_language" type="text" x-model="createForm.language" maxlength="5" placeholder="en"
                               class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111]">
                    </div>
                    <div>
                        <label for="create_timezone" class="mb-1.5 block text-sm font-medium">Timezone</label>
                        <input id="create_timezone" type="text" x-model="createForm.timezone" maxlength="30" placeholder="Asia/Tokyo"
                               class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111]">
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" :checked="createForm.status === 1"
                           @change="createForm.status = $event.target.checked ? 1 : 0"
                           class="rounded border-[#EAEAEA] text-[#111111] focus:ring-[#111111]">
                    Active
                </label>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" class="rounded-md border border-[#EAEAEA] px-3 py-1.5 text-sm hover:bg-gray-50" @click="createOpen = false">Cancel</button>
                    <button type="submit" :disabled="creating"
                            class="rounded-md bg-[#111111] px-4 py-1.5 text-sm font-medium text-white hover:bg-[#333333] disabled:cursor-not-allowed disabled:opacity-60">
                        <span x-show="!creating">Create</span>
                        <span x-show="creating" x-cloak>Creating…</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit modal -->
    <div x-show="editOpen" x-cloak class="fixed inset-0 z-40 flex items-center justify-center px-4"
         @keydown.escape.window="editOpen = false">
        <div class="fixed inset-0 bg-black/40" @click="editOpen = false"></div>
        <div x-show="editOpen" x-transition
             class="relative w-full max-w-md rounded-lg border border-[#EAEAEA] bg-white p-6 shadow-sm"
             x-trap.noscroll="editOpen" role="dialog" aria-modal="true" aria-labelledby="edit-user-title">
            <h2 id="edit-user-title" class="text-base font-semibold">Edit user</h2>
            <p x-show="editLoading" x-cloak class="mt-4 text-sm text-[#787774]">Loading…</p>
            <form x-show="!editLoading" @submit.prevent="submitEdit" class="mt-4 space-y-4">
                <div>
                    <label for="edit_name" class="mb-1.5 block text-sm font-medium">Name</label>
                    <input id="edit_name" type="text" x-model="editForm.name" required maxlength="255"
                           class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111]">
                </div>
                <div>
                    <label for="edit_email" class="mb-1.5 block text-sm font-medium">Email</label>
                    <input id="edit_email" type="email" x-model="editForm.email" required maxlength="255"
                           class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111]">
                </div>
                <div>
                    <label for="edit_password" class="mb-1.5 block text-sm font-medium">Password</label>
                    <input id="edit_password" type="password" x-model="editForm.password" minlength="5" autocomplete="new-password"
                           class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111]">
                    <p class="mt-1 text-xs text-[#787774]">Leave blank to keep the current password.</p>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="edit_zone" class="mb-1.5 block text-sm font-medium">Zone</label>
                        <select id="edit_zone" x-model="editForm.zone" required
                                class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111]">
                            <option value="global">Admin (global)</option>
                            <option value="client">Client</option>
                        </select>
                    </div>
                    <div>
                        <label for="edit_env" class="mb-1.5 block text-sm font-medium">Environment</label>
                        <select id="edit_env" x-model="editForm.env" required
                                class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111]">
                            <option value="LIVE">LIVE</option>
                            <option value="TESTING">TESTING</option>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="edit_language" class="mb-1.5 block text-sm font-medium">Language</label>
                        <input id="edit_language" type="text" x-model="editForm.language" maxlength="5"
                               class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111]">
                    </div>
                    <div>
                        <label for="edit_timezone" class="mb-1.5 block text-sm font-medium">Timezone</label>
                        <input id="edit_timezone" type="text" x-model="editForm.timezone" maxlength="30"
                               class="block w-full rounded-md border border-[#EAEAEA] px-3 py-2 text-sm focus:border-[#111111] focus:outline-none focus:ring-1 focus:ring-[#111111]">
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" :checked="editForm.status === 1"
                           @change="editForm.status = $event.target.checked ? 1 : 0"
                           class="rounded border-[#EAEAEA] text-[#111111] focus:ring-[#111111]">
                    Active
                </label>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" class="rounded-md border border-[#EAEAEA] px-3 py-1.5 text-sm hover:bg-gray-50" @click="editOpen = false">Cancel</button>
                    <button type="submit" :disabled="editing"
                            class="rounded-md bg-[#111111] px-4 py-1.5 text-sm font-medium text-white hover:bg-[#333333] disabled:cursor-not-allowed disabled:opacity-60">
                        <span x-show="!editing">Save</span>
                        <span x-show="editing" x-cloak>Saving…</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Hidden act-as form: submitted via JS after the shared confirm modal —
         a plain POST navigation (not fetch), because Admin\Users::actAs()
         redirects to /dashboard, which fetch() can't turn into a real page
         navigation. -->
    <form id="act-as-form" method="post" action="/admin/users/act-as/0" class="hidden">
        <input type="hidden" name="<?= esc(csrf_token(), 'attr') ?>" value="<?= esc(csrf_hash(), 'attr') ?>">
    </form>

    <script>
        function usersAdmin() {
            return {
                creating: false,
                createOpen: false,
                createForm: { name: '', email: '', password: '', zone: 'client', env: 'LIVE', status: 1, language: 'en', timezone: 'Asia/Tokyo' },
                editing: false,
                editOpen: false,
                editLoading: false,
                editForm: { id: null, name: '', email: '', password: '', zone: 'client', env: 'LIVE', status: 1, language: '', timezone: '' },

                openCreate() {
                    this.createForm = { name: '', email: '', password: '', zone: 'client', env: 'LIVE', status: 1, language: 'en', timezone: 'Asia/Tokyo' };
                    this.createOpen = true;
                },

                async submitCreate() {
                    this.creating = true;
                    const json = await postForm('/admin/users/create', this.createForm);
                    this.creating = false;
                    if (!json) return;
                    if (json.status === 'success') {
                        this.createOpen = false;
                        this.$dispatch('toast', { type: 'success', text: json.message });
                        window.location.reload();
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not create the user.' });
                    }
                },

                async openEdit(id) {
                    this.editOpen = true;
                    this.editLoading = true;
                    let json;
                    try {
                        const res = await fetch('/admin/users/edit/' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                        json = await res.json();
                    } catch (e) {
                        this.editOpen = false;
                        this.$dispatch('toast', { type: 'error', text: 'Network error. Please try again.' });
                        return;
                    }
                    this.editLoading = false;
                    if (json.status !== 'success') {
                        this.editOpen = false;
                        this.$dispatch('toast', { type: 'error', text: json.message || 'User not found.' });
                        return;
                    }
                    this.editForm = {
                        id: json.user.id,
                        name: json.user.name,
                        email: json.user.email,
                        password: '',
                        zone: json.user.zone,
                        env: json.user.env,
                        status: json.user.status,
                        language: json.user.language || '',
                        timezone: json.user.timezone || '',
                    };
                },

                async submitEdit() {
                    this.editing = true;
                    const json = await postForm('/admin/users/update', this.editForm);
                    this.editing = false;
                    if (!json) return;
                    if (json.status === 'success') {
                        this.editOpen = false;
                        this.$dispatch('toast', { type: 'success', text: json.message });
                        window.location.reload();
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not update the user.' });
                    }
                },

                confirmActAs(id, name) {
                    this.$dispatch('confirm', {
                        title: 'Act as this user',
                        body: 'You will see the app as ' + name + '. Use "Return to admin" in the banner to come back.',
                        variant: 'default',
                        confirmLabel: 'Act as',
                        onConfirm: () => {
                            const f = document.getElementById('act-as-form');
                            f.action = '/admin/users/act-as/' + id;
                            f.submit();
                        },
                    });
                },
            };
        }
    </script>
</div>
<?php $this->endSection() ?>
