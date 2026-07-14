<?php

/**
 * User-facing API documentation for the CNR/JPRS proxy — shared by admin and
 * client zones (both authed via authFilter, both extend layouts/app). Purely
 * static reference content; the only dynamic values are the proxy base URL
 * and the caller-visible service list, both already secret-free
 * (see App\Controllers\ApiDocs::index()).
 *
 * @var string                                                $proxyBaseUrl e.g. https://proxyv2.services.vn (no trailing slash)
 * @var list<array{id: int, provider: string, label: string}> $services
 */
$this->extend('layouts/app');
$pageTitle = 'API docs';

$cnrCurl = 'curl -s \\' . "\n"
    . '  -H "Authorization: Bearer <YOUR_API_KEY>" \\' . "\n"
    . '  "' . $proxyBaseUrl . '/api/cnr/testing?command=StatusAccount"';

$cnrSuccess = <<<'JSON'
    {
      "status": "success",
      "api_service_id": "cnr.800",
      "data": {
        "code": "200",
        "description": "Command completed successfully",
        "property": {
          "accountbalance": ["1234.56"]
        }
      }
    }
    JSON;

$cnrError = <<<'JSON'
    {
      "status": "error",
      "api_service_id": "cnr.800",
      "message": "Command parameter is missing."
    }
    JSON;

$jprsCurl = 'curl -s \\' . "\n"
    . '  -H "Authorization: Bearer <YOUR_API_KEY>" \\' . "\n"
    . '  "' . $proxyBaseUrl . '/api/jprs/testing?Command=StatusDomain&DomainName=example.jp"';

$jprsSuccess = <<<'JSON'
    {
      "status": "success",
      "api_service_id": "jprs.801",
      "data": {
        "ResultCode": "200",
        "ResultMessage": "Command completed successfully.",
        "DomainName": "example.jp",
        "DomainStatus": "Active"
      }
    }
    JSON;

$jprsError = <<<'JSON'
    {
      "status": "error",
      "api_service_id": "jprs.801",
      "data": {
        "code": "541",
        "description": "Parameter value policy error"
      },
      "message": "Parameter value policy error"
    }
    JSON;
?>
<?php $this->section('content') ?>
<div class="mx-auto max-w-4xl">
    <div>
        <h1 class="text-xl font-semibold tracking-tight">API docs</h1>
        <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">
            How to call the CNR and JPRS registrar proxy with your own API access key —
            authentication, endpoints, request/response shape, and copy-pasteable examples.
        </p>
    </div>

    <!-- Authentication -->
    <section id="authentication" class="mt-6 rounded-lg border border-[#EAEAEA] bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-[#787774] dark:text-gray-400">Authentication</h2>
        <div class="mt-4 space-y-3 text-sm leading-relaxed">
            <p>Every request must carry your API access key as a Bearer token:</p>
            <p class="rounded-md border border-[#EAEAEA] bg-[#F7F6F3] px-3 py-2 font-mono text-xs dark:border-gray-700 dark:bg-gray-900">
                Authorization: Bearer &lt;YOUR_API_KEY&gt;
            </p>
            <p>
                Create and manage your keys under
                <a href="/my-api-access-keys" class="font-medium text-[#111111] hover:underline dark:text-gray-100">API access keys</a>.
                A key is never shown again after creation — store it securely and never share or commit it.
            </p>
            <p>
                Each key is issued for either the <strong>LIVE</strong> or <strong>TESTING</strong> environment. The
                <code class="rounded bg-[#F7F6F3] px-1 py-0.5 dark:bg-gray-900">live</code> /
                <code class="rounded bg-[#F7F6F3] px-1 py-0.5 dark:bg-gray-900">testing</code> segment in the URL must match
                the key's environment, or the request is rejected with a
                <code class="rounded bg-[#F7F6F3] px-1 py-0.5 dark:bg-gray-900">401</code> and
                <code class="rounded bg-[#F7F6F3] px-1 py-0.5 dark:bg-gray-900">{"status":"error","message":"..."}</code>
                — the same shape is returned for a missing, invalid, or disabled key.
            </p>
        </div>
    </section>

    <!-- Base URL & endpoints -->
    <section id="endpoints" class="mt-4 rounded-lg border border-[#EAEAEA] bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-[#787774] dark:text-gray-400">Base URL &amp; endpoints</h2>
        <div class="mt-4 space-y-2 text-sm leading-relaxed">
            <p class="rounded-md border border-[#EAEAEA] bg-[#F7F6F3] px-3 py-2 font-mono text-xs dark:border-gray-700 dark:bg-gray-900">
                GET <?= esc($proxyBaseUrl) ?>/api/cnr/{live|testing}
            </p>
            <p class="rounded-md border border-[#EAEAEA] bg-[#F7F6F3] px-3 py-2 font-mono text-xs dark:border-gray-700 dark:bg-gray-900">
                GET|POST <?= esc($proxyBaseUrl) ?>/api/jprs/{live|testing}
            </p>
        </div>

        <?php if ($services !== []) : ?>
            <div class="mt-5 overflow-x-auto rounded-md border border-[#EAEAEA] dark:border-gray-700">
                <table class="min-w-full divide-y divide-[#EAEAEA] text-sm dark:divide-gray-700">
                    <thead>
                        <tr class="text-left text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">
                            <th class="px-4 py-2">Service ID</th>
                            <th class="px-4 py-2">Provider</th>
                            <th class="px-4 py-2">Label</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#EAEAEA] dark:divide-gray-700">
                        <?php foreach ($services as $svc) : ?>
                            <tr>
                                <td class="px-4 py-2 font-mono"><?= esc((string) $svc['id']) ?></td>
                                <td class="px-4 py-2 uppercase"><?= esc($svc['provider']) ?></td>
                                <td class="px-4 py-2"><?= esc($svc['label']) ?></td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-[#787774] dark:text-gray-400">These are the service ids configured on this deployment; your API access key is tied to one of them.</p>
        <?php else : ?>
            <p class="mt-4 text-sm text-[#787774] dark:text-gray-400">No registrar services are configured on this deployment yet.</p>
        <?php endif ?>
    </section>

    <!-- Request -->
    <section id="request" class="mt-4 rounded-lg border border-[#EAEAEA] bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-[#787774] dark:text-gray-400">Request</h2>
        <ul class="mt-4 list-disc space-y-2 pl-5 text-sm leading-relaxed">
            <li>
                <strong>CNR</strong> — pass a <code class="rounded bg-[#F7F6F3] px-1 py-0.5 dark:bg-gray-900">command</code>
                query param (e.g. <code class="rounded bg-[#F7F6F3] px-1 py-0.5 dark:bg-gray-900">StatusAccount</code>,
                <code class="rounded bg-[#F7F6F3] px-1 py-0.5 dark:bg-gray-900">StatusDomain</code>) plus that command's own
                params (e.g. <code class="rounded bg-[#F7F6F3] px-1 py-0.5 dark:bg-gray-900">domain</code>).
            </li>
            <li>
                <strong>JPRS</strong> — pass a <code class="rounded bg-[#F7F6F3] px-1 py-0.5 dark:bg-gray-900">Command</code>
                param (GET query string or POST body) plus that command's own params (e.g.
                <code class="rounded bg-[#F7F6F3] px-1 py-0.5 dark:bg-gray-900">DomainName</code>).
            </li>
            <li>
                <code class="rounded bg-[#F7F6F3] px-1 py-0.5 dark:bg-gray-900">command=History</code> /
                <code class="rounded bg-[#F7F6F3] px-1 py-0.5 dark:bg-gray-900">Command=History</code> is special-cased on
                both providers — it returns your own <em>api_history</em> log instead of calling the upstream registrar.
            </li>
        </ul>
    </section>

    <!-- Response envelope -->
    <section id="response-envelope" class="mt-4 rounded-lg border border-[#EAEAEA] bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-[#787774] dark:text-gray-400">Response envelope</h2>
        <div class="mt-4 space-y-3 text-sm leading-relaxed">
            <p>Every response is JSON with the same top-level shape:</p>
            <p class="rounded-md border border-[#EAEAEA] bg-[#F7F6F3] px-3 py-2 font-mono text-xs dark:border-gray-700 dark:bg-gray-900">
                { "status": "success"|"error", "api_service_id": "cnr.&lt;id&gt;"|"jprs.&lt;id&gt;", "data": ..., "message": ... }
            </p>
            <ul class="list-disc space-y-1 pl-5">
                <li><strong>CNR</strong> success = the upstream RRP response <code class="rounded bg-[#F7F6F3] px-1 py-0.5 dark:bg-gray-900">code</code> starts with <code class="rounded bg-[#F7F6F3] px-1 py-0.5 dark:bg-gray-900">2</code> (e.g. <code class="rounded bg-[#F7F6F3] px-1 py-0.5 dark:bg-gray-900">200</code>).</li>
                <li><strong>JPRS</strong> success = a 3-digit <code class="rounded bg-[#F7F6F3] px-1 py-0.5 dark:bg-gray-900">2xx</code> result code.</li>
                <li>On success the envelope carries <code class="rounded bg-[#F7F6F3] px-1 py-0.5 dark:bg-gray-900">data</code> (the registrar's raw response); on error it adds a human-readable <code class="rounded bg-[#F7F6F3] px-1 py-0.5 dark:bg-gray-900">message</code> (alongside <code class="rounded bg-[#F7F6F3] px-1 py-0.5 dark:bg-gray-900">data</code> when the registrar returned one) — including a <code class="rounded bg-[#F7F6F3] px-1 py-0.5 dark:bg-gray-900">400</code> for a missing <code class="rounded bg-[#F7F6F3] px-1 py-0.5 dark:bg-gray-900">command</code>/<code class="rounded bg-[#F7F6F3] px-1 py-0.5 dark:bg-gray-900">Command</code> and a <code class="rounded bg-[#F7F6F3] px-1 py-0.5 dark:bg-gray-900">403</code> for a domain/command restriction (certain commands are blocked for specific domains).</li>
            </ul>
        </div>
    </section>

    <!-- Copy-pasteable examples -->
    <section id="curl-examples" class="mt-4 rounded-lg border border-[#EAEAEA] bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-[#787774] dark:text-gray-400">Examples</h2>

        <h3 class="mt-4 text-sm font-medium">CNR — StatusAccount</h3>
        <div class="relative mt-2">
            <pre class="overflow-x-auto whitespace-pre rounded-md bg-[#F7F6F3] p-4 font-mono text-xs leading-relaxed dark:bg-gray-900"><code><?= esc($cnrCurl) ?></code></pre>
            <button type="button" x-data="{ copied: false }"
                    @click="navigator.clipboard.writeText(<?= esc(json_encode($cnrCurl), 'attr') ?>).then(() => { copied = true; $dispatch('toast', { type: 'success', text: 'Copied to clipboard.' }); setTimeout(() => copied = false, 1500); }).catch(() => $dispatch('toast', { type: 'error', text: 'Could not copy — select and copy manually.' }))"
                    class="absolute right-3 top-3 rounded-md border border-[#EAEAEA] bg-white px-2 py-1 text-xs font-medium text-[#787774] hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-gray-700">
                <span x-show="!copied">Copy</span>
                <span x-show="copied" x-cloak>Copied!</span>
            </button>
        </div>
        <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Example success</p>
                <pre class="mt-1 overflow-x-auto whitespace-pre rounded-md bg-[#F7F6F3] p-4 font-mono text-xs leading-relaxed dark:bg-gray-900"><code><?= esc($cnrSuccess) ?></code></pre>
            </div>
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Example error</p>
                <pre class="mt-1 overflow-x-auto whitespace-pre rounded-md bg-[#F7F6F3] p-4 font-mono text-xs leading-relaxed dark:bg-gray-900"><code><?= esc($cnrError) ?></code></pre>
            </div>
        </div>

        <h3 class="mt-6 text-sm font-medium">JPRS — StatusDomain</h3>
        <div class="relative mt-2">
            <pre class="overflow-x-auto whitespace-pre rounded-md bg-[#F7F6F3] p-4 font-mono text-xs leading-relaxed dark:bg-gray-900"><code><?= esc($jprsCurl) ?></code></pre>
            <button type="button" x-data="{ copied: false }"
                    @click="navigator.clipboard.writeText(<?= esc(json_encode($jprsCurl), 'attr') ?>).then(() => { copied = true; $dispatch('toast', { type: 'success', text: 'Copied to clipboard.' }); setTimeout(() => copied = false, 1500); }).catch(() => $dispatch('toast', { type: 'error', text: 'Could not copy — select and copy manually.' }))"
                    class="absolute right-3 top-3 rounded-md border border-[#EAEAEA] bg-white px-2 py-1 text-xs font-medium text-[#787774] hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-gray-700">
                <span x-show="!copied">Copy</span>
                <span x-show="copied" x-cloak>Copied!</span>
            </button>
        </div>
        <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Example success</p>
                <pre class="mt-1 overflow-x-auto whitespace-pre rounded-md bg-[#F7F6F3] p-4 font-mono text-xs leading-relaxed dark:bg-gray-900"><code><?= esc($jprsSuccess) ?></code></pre>
            </div>
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">Example error</p>
                <pre class="mt-1 overflow-x-auto whitespace-pre rounded-md bg-[#F7F6F3] p-4 font-mono text-xs leading-relaxed dark:bg-gray-900"><code><?= esc($jprsError) ?></code></pre>
            </div>
        </div>

        <p class="mt-4 text-xs text-[#787774] dark:text-gray-400">
            <code class="rounded bg-[#F7F6F3] px-1 py-0.5 dark:bg-gray-900">&lt;YOUR_API_KEY&gt;</code> is a placeholder — substitute your own key from
            <a href="/my-api-access-keys" class="font-medium text-[#111111] hover:underline dark:text-gray-100">API access keys</a>. Never paste a real key into a shared document or ticket.
        </p>
    </section>

    <!-- Try it in-browser -->
    <section id="try-it" class="mt-4 rounded-lg border border-[#EAEAEA] bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-[#787774] dark:text-gray-400">Try it in-browser</h2>
        <p class="mt-4 text-sm leading-relaxed">
            Prefer not to use <code class="rounded bg-[#F7F6F3] px-1 py-0.5 dark:bg-gray-900">curl</code>? Compose and run a
            command directly against one of your keys in the developer console:
        </p>
        <div class="mt-3 flex flex-wrap gap-3">
            <a href="/console/cnr" class="rounded-md border border-[#EAEAEA] px-4 py-2 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:text-gray-100 dark:hover:bg-gray-700">CNR console</a>
            <a href="/console/jprs" class="rounded-md border border-[#EAEAEA] px-4 py-2 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:text-gray-100 dark:hover:bg-gray-700">JPRS console</a>
        </div>
    </section>

    <!-- Command reference -->
    <section id="command-reference" class="mt-4 rounded-lg border border-[#EAEAEA] bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-[#787774] dark:text-gray-400">Full command reference</h2>
        <p class="mt-4 text-sm leading-relaxed text-[#787774] dark:text-gray-400">
            This page covers the proxy's own request/response contract only. For the full list of
            CNR (CentralNic/RRPproxy) and JPRS commands and their parameters, see each registrar's
            reference manual — CNR's <em>CentralNic Reseller Reference Manual</em> and
            <em>RRPproxy communication manual</em>, and JPRS's transaction application technical
            specifications for GJP/OJP domains.
        </p>
    </section>
</div>
<?php $this->endSection() ?>
