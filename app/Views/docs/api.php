<?php
/**
 * REST API connection guide: how to mint a personal API key and wire an AI
 * agent/script to `/api/v1` (see `kanban/processing/20260721-07-rest-api.md`
 * for the actual endpoints/behaviour this documents).
 *
 * @var string $apiBaseUrl e.g. https://example.test/api/v1 (no trailing slash)
 */
$this->extend('layouts/app');

$baseUrl = rtrim($apiBaseUrl, '/');

$docsNav = [
    ['label' => 'Overview', 'href' => '/docs'],
    ['label' => 'Getting started', 'href' => '/docs/getting-started'],
    ['label' => 'REST API', 'href' => '/docs/api'],
];

$endpoints = [
    ['GET', '/me', 'Current user (the API key owner) and basic counts.'],
    ['GET', '/projects', "Projects the key's user can access."],
    ['GET', '/projects/{id}', 'One project with its board columns.'],
    ['GET', '/projects/{id}/tasks', 'Tasks in a project (filter by ?column_id, ?assignee_id, ?priority).'],
    ['POST', '/projects/{id}/tasks', 'Create a task in a project.'],
    ['GET', '/tasks/{id}', 'One task.'],
    ['PATCH', '/tasks/{id}', 'Update fields on a task.'],
    ['DELETE', '/tasks/{id}', 'Soft-delete a task.'],
];

$curlGet = <<<CURL
    curl -s {$baseUrl}/projects \\
      -H "Authorization: Bearer YOUR_API_KEY"
    CURL;

$curlPost = <<<CURL
    curl -s -X POST {$baseUrl}/projects/1/tasks \\
      -H "Authorization: Bearer YOUR_API_KEY" \\
      -H "Content-Type: application/json" \\
      -d '{"title": "Write the launch checklist", "priority": "high"}'
    CURL;

$pythonSnippet = <<<PY
    import requests

    BASE_URL = "{$baseUrl}"
    API_KEY = "YOUR_API_KEY"
    headers = {"Authorization": f"Bearer {API_KEY}"}

    # List the projects this key can access
    projects = requests.get(f"{BASE_URL}/projects", headers=headers).json()

    # Create a task in project 1 (requires a write-scoped key)
    task = requests.post(
        f"{BASE_URL}/projects/1/tasks",
        headers=headers,
        json={"title": "Write the launch checklist", "priority": "high"},
    ).json()
    PY;

$agentPrompt = <<<PROMPT
    You have an API key and access to a project-management REST API.

    Base URL: {$baseUrl}
    Send this header on every request: Authorization: Bearer YOUR_API_KEY

    You can:
    - GET /projects to list the projects you have access to
    - GET /projects/{id}/tasks to list the tasks in a project
    - POST /projects/{id}/tasks with JSON body {"title": "...", "priority": "low|medium|high", "assignee_id": null, "due_date": "YYYY-MM-DD"} to create a task
    - GET /tasks/{id} to read one task
    - PATCH /tasks/{id} to update a task
    - DELETE /tasks/{id} to remove a task

    POST, PATCH, and DELETE only work if the key has "write" scope — a "read" key gets a 403.
    PROMPT;
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-6xl lg:flex lg:gap-8">

    <nav class="mb-6 flex gap-2 overflow-x-auto pb-1 lg:hidden" aria-label="Docs">
        <?php foreach ($docsNav as $item) : ?>
            <a href="<?= esc($item['href'], 'attr') ?>"
               class="shrink-0 rounded-full px-3 py-1.5 text-sm font-medium <?= url_is($item['href'])
                    ? 'bg-[#111111] text-white dark:bg-gray-100 dark:text-gray-900'
                    : 'bg-[#F7F6F3] text-[#787774] dark:bg-gray-800 dark:text-gray-300' ?>">
                <?= esc($item['label']) ?>
            </a>
        <?php endforeach ?>
    </nav>

    <nav class="hidden lg:block lg:w-56 lg:shrink-0" aria-label="Docs">
        <div class="sticky top-6 space-y-1">
            <?php foreach ($docsNav as $item) : ?>
                <a href="<?= esc($item['href'], 'attr') ?>"
                   class="block rounded-md px-3 py-2 text-sm font-medium <?= url_is($item['href'])
                        ? 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400'
                        : 'text-[#111111] hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800' ?>">
                    <?= esc($item['label']) ?>
                </a>
            <?php endforeach ?>
        </div>
    </nav>

    <div class="min-w-0 max-w-3xl flex-1" x-data="{
            copy(ref) {
                window.copyText(this.$refs[ref].innerText).then(ok => {
                    this.$dispatch('toast', { type: ok ? 'success' : 'error', text: ok ? 'Copied' : 'Could not copy — copy it manually.' });
                });
            },
        }">
        <h1 class="text-2xl font-semibold tracking-tight dark:text-gray-100">REST API</h1>
        <p class="mt-2 text-sm leading-6 text-[#787774] dark:text-gray-400">
            Connect an AI agent or script to your projects and tasks with a personal API key.
        </p>

        <!-- 1. Create a key -->
        <section class="mt-8">
            <h2 class="text-lg font-semibold dark:text-gray-100">1. Create an API key</h2>
            <p class="mt-2 text-sm leading-6 text-[#787774] dark:text-gray-400">
                Go to
                <a href="/account/api-keys" class="font-medium text-[#111111] hover:underline dark:text-gray-100">Account &rarr; API Keys</a>
                and click "Create key". Choose a scope:
            </p>
            <ul class="mt-3 space-y-1 text-sm leading-6 text-[#787774] dark:text-gray-400">
                <li><span class="font-medium text-[#111111] dark:text-gray-100">Read</span> — can only list/get projects and tasks.</li>
                <li><span class="font-medium text-[#111111] dark:text-gray-100">Write</span> — can also create, update, and delete tasks.</li>
            </ul>
            <p class="mt-3 text-sm leading-6 text-[#787774] dark:text-gray-400">
                You can also set an expiry (never, 30, 90, or 365 days). A key can be revoked at any time from the same page.
            </p>
            <div class="mt-4 rounded-md border border-yellow-200 bg-[#FBF3DB] px-4 py-3 text-sm text-[#956400] dark:border-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300">
                Treat an API key like a password. The plaintext token is shown <strong>once</strong>, right after
                creation — store it in a secrets manager or environment variable. It can't be viewed again, only revoked.
            </div>
        </section>

        <!-- 2. Authenticate -->
        <section class="mt-8">
            <h2 class="text-lg font-semibold dark:text-gray-100">2. Authenticate</h2>
            <p class="mt-2 text-sm leading-6 text-[#787774] dark:text-gray-400">
                Base URL:
                <code class="rounded bg-[#F7F6F3] px-1.5 py-0.5 text-xs dark:bg-gray-900 dark:text-gray-300"><?= esc($baseUrl) ?></code>
            </p>
            <p class="mt-2 text-sm leading-6 text-[#787774] dark:text-gray-400">
                Send the token on every request as a Bearer header:
            </p>
            <p class="mt-2">
                <code class="rounded bg-[#F7F6F3] px-1.5 py-0.5 text-xs dark:bg-gray-900 dark:text-gray-300">Authorization: Bearer &lt;token&gt;</code>
            </p>
        </section>

        <!-- 3. Endpoints -->
        <section class="mt-8">
            <h2 class="text-lg font-semibold dark:text-gray-100">3. Endpoints</h2>
            <div class="mt-3 overflow-x-auto rounded-lg border border-[#EAEAEA] dark:border-gray-700">
                <table class="min-w-full divide-y divide-[#EAEAEA] text-sm dark:divide-gray-700">
                    <thead>
                        <tr class="text-left text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">
                            <th class="px-4 py-3">Method</th>
                            <th class="px-4 py-3">Path</th>
                            <th class="px-4 py-3">Description</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#EAEAEA] bg-white dark:divide-gray-700 dark:bg-gray-800">
                        <?php foreach ($endpoints as [$method, $path, $description]) : ?>
                            <tr>
                                <td class="px-4 py-3">
                                    <span class="rounded-full bg-[#F7F6F3] px-2 py-0.5 text-xs font-semibold uppercase tracking-wide text-[#787774] dark:bg-gray-700 dark:text-gray-300">
                                        <?= esc($method) ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 font-mono text-xs dark:text-gray-100"><?= esc($baseUrl . $path) ?></td>
                                <td class="px-4 py-3 text-[#787774] dark:text-gray-400"><?= esc($description) ?></td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-sm leading-6 text-[#787774] dark:text-gray-400">
                <span class="font-medium text-[#111111] dark:text-gray-100">Scope:</span>
                GET endpoints work with a read-scoped key. POST, PATCH, and DELETE require a
                write-scoped key — a read-only key gets a 403.
            </p>
        </section>

        <!-- 4. Examples -->
        <section class="mt-8">
            <h2 class="text-lg font-semibold dark:text-gray-100">4. Examples</h2>

            <p class="mt-3 text-sm font-medium text-[#111111] dark:text-gray-100">List projects (curl)</p>
            <div class="relative mt-2">
                <pre class="overflow-x-auto rounded-lg bg-gray-900 p-4 pr-16 text-xs leading-5 text-gray-100"><code x-ref="curlGet"><?= esc($curlGet) ?></code></pre>
                <button type="button" @click="copy('curlGet')"
                        class="absolute right-2 top-2 rounded-md bg-white/10 px-2 py-1 text-xs font-medium text-gray-100 hover:bg-white/20">Copy</button>
            </div>

            <p class="mt-4 text-sm font-medium text-[#111111] dark:text-gray-100">Create a task (curl)</p>
            <div class="relative mt-2">
                <pre class="overflow-x-auto rounded-lg bg-gray-900 p-4 pr-16 text-xs leading-5 text-gray-100"><code x-ref="curlPost"><?= esc($curlPost) ?></code></pre>
                <button type="button" @click="copy('curlPost')"
                        class="absolute right-2 top-2 rounded-md bg-white/10 px-2 py-1 text-xs font-medium text-gray-100 hover:bg-white/20">Copy</button>
            </div>

            <p class="mt-4 text-sm font-medium text-[#111111] dark:text-gray-100">Python (requests)</p>
            <div class="relative mt-2">
                <pre class="overflow-x-auto rounded-lg bg-gray-900 p-4 pr-16 text-xs leading-5 text-gray-100"><code x-ref="pySnippet"><?= esc($pythonSnippet) ?></code></pre>
                <button type="button" @click="copy('pySnippet')"
                        class="absolute right-2 top-2 rounded-md bg-white/10 px-2 py-1 text-xs font-medium text-gray-100 hover:bg-white/20">Copy</button>
            </div>
        </section>

        <!-- 5. Agent prompt -->
        <section class="mt-8">
            <h2 class="text-lg font-semibold dark:text-gray-100">5. Tell an AI agent how to use it</h2>
            <p class="mt-2 text-sm leading-6 text-[#787774] dark:text-gray-400">
                Paste this into an agent's instructions (fill in the real key first):
            </p>
            <div class="relative mt-2">
                <pre class="overflow-x-auto rounded-lg bg-gray-900 p-4 pr-16 text-xs leading-5 text-gray-100"><code x-ref="agentPrompt"><?= esc($agentPrompt) ?></code></pre>
                <button type="button" @click="copy('agentPrompt')"
                        class="absolute right-2 top-2 rounded-md bg-white/10 px-2 py-1 text-xs font-medium text-gray-100 hover:bg-white/20">Copy</button>
            </div>
        </section>
    </div>
</div>
<?php $this->endSection() ?>
