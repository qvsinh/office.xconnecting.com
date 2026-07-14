<?php
/**
 * Shared read-only detail modal for the API history viewers (client + admin).
 * Included inside the parent view's `x-data` div — it does NOT declare its
 * own Alpine component, it expects the including view's component to expose:
 *   - previewOpen (bool), previewLoading (bool), previewRow (object|null)
 *   - closePreview(), prettyPrint(raw)
 *
 * Every dynamic value is bound via `x-text` — never `x-html` — so a
 * `params`/`response`/`metadata`/`domain` blob containing markup (e.g. a
 * registrar echoing back `<script>...`) renders as inert text, never markup.
 */
?>
<div x-show="previewOpen" x-cloak class="fixed inset-0 z-40 flex items-center justify-center px-4"
     @keydown.escape.window="closePreview()">
    <div class="fixed inset-0 bg-black/40" @click="closePreview()"></div>
    <div x-show="previewOpen" x-transition
         class="relative max-h-[85vh] w-full max-w-2xl overflow-y-auto rounded-lg border border-[#EAEAEA] bg-white p-6 shadow-sm"
         x-trap.noscroll="previewOpen" role="dialog" aria-modal="true" aria-labelledby="preview-title">
        <div class="flex items-center justify-between">
            <h2 id="preview-title" class="text-base font-semibold">Log entry detail</h2>
            <button type="button" class="text-[#787774] hover:text-[#111111]" @click="closePreview()" aria-label="Close">&times;</button>
        </div>

        <p x-show="previewLoading" x-cloak class="mt-6 text-sm text-[#787774]">Loading…</p>

        <dl x-show="!previewLoading && previewRow" x-cloak class="mt-4 space-y-4 text-sm">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-[#787774]">Env</dt>
                    <dd x-text="previewRow?.env || '—'"></dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-[#787774]">Status</dt>
                    <dd>
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium uppercase tracking-wide"
                              :class="Number(previewRow?.status) === 1 ? 'bg-[#EDF3EC] text-[#346538]' : 'bg-[#FDEBEC] text-[#9F2F2D]'"
                              x-text="Number(previewRow?.status) === 1 ? 'Success' : 'Failed'"></span>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-[#787774]">Service</dt>
                    <dd x-text="(previewRow?.service_name || '—') + ' #' + (previewRow?.service_id ?? '—')"></dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-[#787774]">Command</dt>
                    <dd x-text="previewRow?.command || '—'"></dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-[#787774]">Domain</dt>
                    <dd x-text="previewRow?.domain || '—'"></dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-[#787774]">Created</dt>
                    <dd x-text="previewRow?.created_at || '—'"></dd>
                </div>
                <div class="col-span-2" x-show="previewRow?.user_name || previewRow?.user_email">
                    <dt class="text-xs font-medium uppercase tracking-wide text-[#787774]">User</dt>
                    <dd x-text="(previewRow?.user_name || 'Unnamed') + ' (' + (previewRow?.user_email || 'no email') + ')'"></dd>
                </div>
            </div>
            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-[#787774]">URL</dt>
                <dd class="break-all font-mono text-xs" x-text="previewRow?.url || '—'"></dd>
            </div>
            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-[#787774]">Params</dt>
                <pre class="mt-1 max-h-48 overflow-auto whitespace-pre-wrap rounded-md bg-[#F7F6F3] p-3 font-mono text-xs" x-text="prettyPrint(previewRow?.params)"></pre>
            </div>
            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-[#787774]">Response</dt>
                <pre class="mt-1 max-h-48 overflow-auto whitespace-pre-wrap rounded-md bg-[#F7F6F3] p-3 font-mono text-xs" x-text="prettyPrint(previewRow?.response)"></pre>
            </div>
            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-[#787774]">Metadata</dt>
                <pre class="mt-1 max-h-48 overflow-auto whitespace-pre-wrap rounded-md bg-[#F7F6F3] p-3 font-mono text-xs" x-text="prettyPrint(previewRow?.metadata)"></pre>
            </div>
        </dl>
    </div>
</div>
