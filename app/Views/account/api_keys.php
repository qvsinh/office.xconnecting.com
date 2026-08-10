<?php
/**
 * Self-service personal API keys: create/list/revoke tokens used by AI
 * agents/scripts against the REST API. The plaintext token is only ever
 * available in the JSON response of Account\ApiKeys::create() — this view
 * never receives or renders a hash/token from $keys, and never re-fetches
 * it after a reload.
 *
 * @var list<object{id:int,name:string,token_prefix:string,scopes:string,last_used_at:?string,expires_at:?string,revoked_at:?string,created_at:string}> $keys
 */
$this->extend('layouts/app');

$now = date('Y-m-d H:i:s');

$statusBadge = static function (?string $revokedAt, ?string $expiresAt) use ($now): array {
    if ($revokedAt !== null) {
        return ['Revoked', 'bg-[#F7F6F3] text-[#787774] dark:bg-gray-700 dark:text-gray-300'];
    }

    if ($expiresAt !== null && $expiresAt < $now) {
        return ['Expired', 'bg-[#FBF3DB] text-[#956400] dark:bg-yellow-900/40 dark:text-yellow-300'];
    }

    return ['Active', 'bg-[#EDF3EC] text-[#346538] dark:bg-green-900/40 dark:text-green-300'];
};
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-7xl" x-data="apiKeysPage()">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight dark:text-gray-100"><?= esc(lang('apikeys.title')) ?></h1>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">
                Personal access tokens for AI agents and scripts to use the REST API on your behalf.
            </p>
        </div>
        <button type="button" @click="openCreate()" class="btn-primary">+ Create key</button>
    </div>

    <div class="mt-6 overflow-x-auto rounded-lg border border-[#EAEAEA] bg-white dark:border-gray-700 dark:bg-gray-800">
        <table class="min-w-full divide-y divide-[#EAEAEA] text-sm dark:divide-gray-700">
            <thead>
                <tr class="text-left text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">
                    <th class="px-4 py-3"><?= esc(lang('apikeys.name')) ?></th>
                    <th class="px-4 py-3">Token</th>
                    <th class="px-4 py-3">Scopes</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3"><?= esc(lang('apikeys.last_used')) ?></th>
                    <th class="px-4 py-3"><?= esc(lang('apikeys.expires')) ?></th>
                    <th class="px-4 py-3"><?= esc(lang('apikeys.created')) ?></th>
                    <th class="px-4 py-3 text-right"><?= esc(lang('common.actions')) ?></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#EAEAEA] dark:divide-gray-700">
                <?php foreach ($keys as $key) :
                    [$statusLabel, $statusClass] = $statusBadge($key->revoked_at ?? null, $key->expires_at ?? null);
                    ?>
                    <tr>
                        <td class="px-4 py-3 font-medium dark:text-gray-100"><?= esc($key->name) ?></td>
                        <td class="px-4 py-3">
                            <code class="rounded bg-[#F7F6F3] px-1.5 py-0.5 text-xs dark:bg-gray-900 dark:text-gray-300"><?= esc($key->token_prefix) ?>…</code>
                        </td>
                        <td class="px-4 py-3">
                            <span class="rounded-full bg-[#F7F6F3] px-2 py-0.5 text-xs font-medium uppercase tracking-wide text-[#787774] dark:bg-gray-700 dark:text-gray-300">
                                <?= esc($key->scopes) ?>
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium uppercase tracking-wide <?= esc($statusClass, 'attr') ?>">
                                <?= esc($statusLabel) ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-xs text-[#787774] dark:text-gray-400"><?= esc($key->last_used_at ?? 'Never') ?></td>
                        <td class="px-4 py-3 text-xs text-[#787774] dark:text-gray-400"><?= esc($key->expires_at ?? 'Never') ?></td>
                        <td class="px-4 py-3 text-xs text-[#787774] dark:text-gray-400"><?= esc($key->created_at) ?></td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            <?php if (($key->revoked_at ?? null) === null) : ?>
                                <button type="button" class="text-sm font-medium text-[#9F2F2D] hover:underline dark:text-red-400"
                                        @click="confirmRevoke(<?= (int) $key->id ?>, <?= esc(json_encode($key->name), 'attr') ?>)">Revoke</button>
                            <?php else : ?>
                                <span class="text-xs text-[#787774] dark:text-gray-400">—</span>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                <?php if ($keys === []) : ?>
                    <tr>
                        <td colspan="8" class="px-4 py-12 text-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="mx-auto h-10 w-10 text-gray-300 dark:text-gray-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 1 1 4 0 2 2 0 0 1-4 0Zm0 0L9.5 12.5m-3.7 3.7L4 18l1 3 3-1 1.8-1.8m0 0L12.5 16.5m-2.4 2.4-2-2" />
                            </svg>
                            <p class="mt-3 text-sm text-[#787774] dark:text-gray-400"><?= esc(lang('apikeys.none_yet')) ?></p>
                            <button type="button" @click="openCreate()" class="mt-4 btn-primary">+ Create key</button>
                        </td>
                    </tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>

    <!-- Create modal -->
    <div x-show="createOpen" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center px-4"
         @keydown.escape.window="createOpen = false">
        <div class="fixed inset-0 bg-black/40" @click="createOpen = false"></div>
        <div x-show="createOpen" x-transition
             class="modal-panel relative max-w-md"
             x-trap.noscroll="createOpen" role="dialog" aria-modal="true" aria-labelledby="create-key-title">
            <button type="button" class="absolute right-4 top-4 rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:text-gray-500 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                    @click="createOpen = false" aria-label="<?= esc(lang('common.close'), 'attr') ?>">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <h2 id="create-key-title" class="pr-8 text-lg font-semibold text-gray-800 dark:text-white/90"><?= esc(lang('apikeys.create')) ?></h2>
            <form @submit.prevent="submitCreate" class="mt-4 space-y-4">
                <div>
                    <label for="key_name" class="form-label"><?= esc(lang('apikeys.name')) ?></label>
                    <input id="key_name" type="text" x-model="createForm.name" required maxlength="100"
                           placeholder="e.g. my-agent" class="form-input">
                </div>
                <div>
                    <label for="key_scope" class="form-label">Scope</label>
                    <select id="key_scope" x-model="createForm.scopes" class="form-select">
                        <option value="read">Read</option>
                        <option value="write">Write</option>
                    </select>
                    <p class="mt-1 text-xs text-[#787774] dark:text-gray-400">Read = list/get. Write = create/update/delete tasks.</p>
                </div>
                <div>
                    <label for="key_expiry" class="form-label">Expires</label>
                    <select id="key_expiry" x-model="createForm.expires_days" class="form-select">
                        <option value="0"><?= esc(lang('apikeys.never')) ?></option>
                        <option value="30">30 days</option>
                        <option value="90">90 days</option>
                        <option value="365">365 days</option>
                    </select>
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" class="btn-secondary" @click="createOpen = false"><?= esc(lang('common.cancel')) ?></button>
                    <button type="submit" :disabled="creating" class="btn-primary">
                        <span x-show="!creating">Create</span>
                        <span x-show="creating" x-cloak><?= esc(lang('apikeys.creating')) ?></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- One-time token reveal -->
    <div x-show="revealOpen" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center px-4">
        <div class="fixed inset-0 bg-black/40"></div>
        <div x-show="revealOpen" x-transition
             class="modal-panel relative max-w-md"
             x-trap.noscroll="revealOpen" role="dialog" aria-modal="true" aria-labelledby="reveal-key-title">
            <h2 id="reveal-key-title" class="text-lg font-semibold text-gray-800 dark:text-white/90"><?= esc(lang('apikeys.created_ok')) ?></h2>
            <div class="mt-4 rounded-md border border-yellow-200 bg-[#FBF3DB] px-4 py-3 text-sm text-[#956400] dark:border-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300">
                This is the only time the token is shown — store it securely.
            </div>
            <div class="mt-4 flex items-center gap-2">
                <code class="block flex-1 overflow-x-auto whitespace-nowrap rounded-md border border-[#EAEAEA] bg-[#F7F6F3] px-3 py-2 text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" x-text="revealToken"></code>
                <button type="button" class="btn-secondary shrink-0" @click="copyToken()"><?= esc(lang('account.copy')) ?></button>
            </div>
            <p class="mt-4 text-sm text-[#787774] dark:text-gray-400">
                Use it as <code>Authorization: Bearer &lt;token&gt;</code> against
                <code>GET /api/v1/me</code> to verify it works.
            </p>
            <div class="flex justify-end pt-4">
                <button type="button" class="btn-primary" @click="doneReveal()"><?= esc(lang('apikeys.done')) ?></button>
            </div>
        </div>
    </div>
</div>

<script>
    function apiKeysPage() {
        return {
            creating: false,
            createOpen: false,
            createForm: { name: '', scopes: 'read', expires_days: '0' },
            revealOpen: false,
            revealToken: '',

            openCreate() {
                this.createForm = { name: '', scopes: 'read', expires_days: '0' };
                this.createOpen = true;
            },

            async submitCreate() {
                this.creating = true;
                const json = await postForm('/account/api-keys/create', this.createForm);
                this.creating = false;
                if (!json) return;
                if (json.status === 'success') {
                    this.createOpen = false;
                    this.revealToken = json.token;
                    this.revealOpen = true;
                } else {
                    this.$dispatch('toast', { type: 'error', text: json.message || 'Could not create the API key.' });
                }
            },

            async copyToken() {
                if (await window.copyText(this.revealToken)) {
                    this.$dispatch('toast', { type: 'success', text: 'Copied' });
                } else {
                    this.$dispatch('toast', { type: 'error', text: 'Could not copy — copy it manually.' });
                }
            },

            doneReveal() {
                this.revealOpen = false;
                window.location.reload();
            },

            confirmRevoke(id, name) {
                this.$dispatch('confirm', {
                    title: 'Revoke API key',
                    body: 'This will immediately revoke "' + name + '". Any agent or script using it will stop working. This cannot be undone.',
                    variant: 'danger',
                    confirmLabel: 'Revoke',
                    onConfirm: async () => {
                        const json = await postForm('/account/api-keys/revoke', { id });
                        if (!json) return;
                        if (json.status === 'success') {
                            this.$dispatch('toast', { type: 'success', text: json.message });
                            window.location.reload();
                        } else {
                            this.$dispatch('toast', { type: 'error', text: json.message || 'Could not revoke the key.' });
                        }
                    },
                });
            },
        };
    }
</script>

<?php $this->endSection() ?>
