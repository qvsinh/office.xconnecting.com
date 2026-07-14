# Plan: User-facing API documentation page (CNR/JPRS proxy)

**Task:** 20260714
**Created:** 2026-07-14
**Branch suggestion:** feature/api-docs-page

## 1. Goal
Give authenticated clients (and admins) a single in-app reference page explaining how to call the
CNR and JPRS proxy endpoints with their API access key: authentication, base URLs, request params,
the response envelope, and copy-pasteable `curl` examples — with links to key management and the
developer consoles. It documents the *already-built* proxy contract; no new proxy behaviour.

## 2. Scope
- **In scope:**
  - Route `GET /api-docs` behind `authFilter` (reachable by client and admin — both are authed).
  - Thin controller `App\Controllers\ApiDocs::index` returning a view; passes only safe, derived
    data (proxy base URL from `base_url()`, the caller's own service ids/providers via
    `list_api_services()`). No DB access.
  - A rich static content view under `app/Views/` extending `layouts/app` with sections:
    - **Authentication** — `Authorization: Bearer <your key>` header; where to get/manage keys
      (link `/my-api-access-keys`); LIVE vs TESTING; the URL env segment must match the key's env;
      401 JSON `{status:error,message:...}` on invalid/disabled/env-mismatch key.
    - **Base URLs & endpoints** — `GET {base}/api/cnr/{live|testing}/...` and
      `GET|POST {base}/api/jprs/{live|testing}/...`.
    - **Request** — CNR `command` param (+ params, e.g. `domain`/`DomainName`); JPRS `Command`
      param; note `command=History`/`Command=History` returns the caller's own api_history.
    - **Response envelope** — `{status, api_service_id, data|message}`; success rule = RRP/JPRS
      response `code` starts with `2`; example success + example error JSON; the CNR 400 (missing
      command) and 403 (restricted command, e.g. `ModifyDomain` on `dnp.sg`) cases.
    - **curl examples** — one CNR (e.g. `command=StatusAccount`) and one JPRS, each with Bearer
      header; static example success/error JSON blocks.
    - **Try it in-browser** — pointer to `/console/cnr` and `/console/jprs`.
    - **Full command reference** — link out to the registrar manuals in `docs/CNR` / `docs/JPRS`
      (do NOT duplicate per-command docs).
  - Sidebar link "API docs" in `layouts/app` for both the `client` and `admin` nav zones.
  - Tests: 200 for authed user, guest redirected, no access_key/secret leaked, required section
    headings/anchors present.
- **Out of scope:**
  - OpenAPI/Swagger spec or an interactive try-it beyond the existing developer consoles.
  - Per-command reference for every registrar command (link to `docs/CNR`/`docs/JPRS` instead).
  - Public / unauthenticated docs (page is authed only).
  - Any change to proxy routes, `ApiFilter`, `CnrApi`/`JprsApi`, or the clients.
  - Printing the user's actual `access_key` value anywhere.

## 3. Affected areas
| Layer | Files / Symbols | Notes |
|-------|-----------------|-------|
| Backend | `app/Controllers/ApiDocs.php` (new) | Thin controller, no model; extends `BaseController` |
| Config / Routes | `app/Config/Routes.php` | Add `GET api-docs` inside the existing `authFilter` group (lines 63–88) |
| Frontend (Views) | `app/Views/api_docs/index.php` (new) | Extends `layouts/app`; static content + `esc()` on the few dynamic values |
| Frontend (Views) | `app/Views/layouts/app.php` | Add "API docs" nav link in both `admin` and `client` `<nav>` branches |
| Tests | `tests/Controllers/ApiDocsTest.php` (new) | Feature test (auth, guest, no-secret, sections) |

## 4. Impact analysis
New symbols only — no existing symbol is modified or renamed, so blast radius is minimal.
- `app/Config/Routes.php`: additive route line inside the `authFilter` group; no existing route
  changes. Zero blast radius on existing routes.
- `app/Views/layouts/app.php`: additive `<a>` links in the two nav branches; shared layout is
  used by every authed page, so a **broken markup edit here affects all pages** — keep the edit to
  two self-contained anchor lines mirroring the existing pattern; verify existing pages still
  render (a couple of existing feature tests already exercise `layouts/app`).
- No changes to `ApiFilter`, `CnrApi`, `JprsApi`, `CnrClient`, `JprsClient`, `list_api_services()`
  — the page only *reads* `list_api_services()` (safe: id/provider/label only, no secrets) and
  `base_url()`.

## 5. Implementation steps

### Phase 1 — Backend
1. [ ] Add route `GET api-docs -> ApiDocs::index` inside the existing `authFilter` group in
   `app/Config/Routes.php` (do not add to the `admin` group — admins are authed and can hit the
   shared route; the view's nav zone auto-selects). — owner: `backend-coder`
   - Acceptance: `php spark routes` lists `api-docs` under the `authFilter` filter; guests are
     redirected by `authFilter`.
2. [ ] Create `App\Controllers\ApiDocs` extending `BaseController` with `index(): string` that
   returns `view('api_docs/index', [...])`. Pass only: `proxyBaseUrl` (e.g. `rtrim(base_url(), '/')`),
   `services` (`list_api_services()`), and `user` (`session('userWorking')`) for display context.
   No model, no raw SQL, no `access_key`. — owner: `backend-coder`
   - Acceptance: `phpstan analyse` (level 5) passes; controller has no DB dependency; nothing in
     the view payload contains a key/secret/PEM/password.

### Phase 2 — Frontend (Views)
1. [ ] Create `app/Views/api_docs/index.php` extending `layouts/app` with the sections listed in
   §2. Static example/code blocks (curl, JSON) are literal text; `esc()` every dynamic value
   (`$proxyBaseUrl`, each service label/id, username). Use TailAdmin/Tailwind for layout
   (cards/sections, `<pre>`/`<code>` styled blocks), a small Alpine "copy to clipboard" on code
   blocks, and the shared toast component for the copy confirmation. Link `/my-api-access-keys`,
   `/console/cnr`, `/console/jprs`, and the registrar manuals in `docs/CNR`/`docs/JPRS`.
   — owner: `frontend-coder`
   - Acceptance: page renders inside the app shell; all four required content areas
     (authentication, endpoints/request, response envelope, curl examples) present with stable
     heading text/anchors; no inline CSS, no jQuery, no Bootstrap; `esc()` on all dynamic output.
   - UI notes: load the most specific UI/UX skill (`minimalist-ui` fits TailAdmin) before layout;
     copy button = Alpine `x-data`; confirmation via the shared toast Alpine component; do not
     render `alert()`/`confirm()`.
2. [ ] Add an "API docs" nav link to both the `admin` and `client` `<nav>` branches in
   `app/Views/layouts/app.php`, matching the existing anchor styling. Client link `/api-docs`;
   admin link `/api-docs` (same shared route). — owner: `frontend-coder`
   - Acceptance: link visible in both zones; existing pages still render unchanged.

### Phase 3 — Tests
1. [ ] Create `tests/Controllers/ApiDocsTest.php` (feature test). — owner: `tester`
   - Cases: authed user gets 200; guest is redirected (not 200); response body contains the key
     section markers (e.g. "Authorization: Bearer", "/api/cnr/", "/api/jprs/", the response-envelope
     example); response body does NOT contain any `access_key`/Bearer token value nor a decoded
     Hashids-looking secret; nav link `/api-docs` present in the shell.
   - Acceptance: tests pass in the sandbox MySQL suite; overall suite stays green.

## 6. Test plan
- **Unit:** none required (no business logic; controller is transport-only).
- **Integration/Feature:** `ApiDocsTest` — auth gating (200 vs redirect), required content
  sections present, no-secret-leak assertion, sidebar link present.
- **Browser / E2E:** manual — log in as a client, open "API docs" from the sidebar, verify sections
  render, copy button copies a curl example and shows a toast, links to `/my-api-access-keys` and
  the consoles work; repeat as admin.
- **Manual smoke:** copy the CNR curl example, substitute a real testing key, and confirm it hits
  `/api/cnr/testing/...` and returns the documented envelope.

## 7. Risks & open questions
- **Shared layout edit** touches every authed page — mitigate by keeping the nav edit to two
  anchor lines and relying on existing layout-backed feature tests to catch regressions.
- **Doc drift**: examples are hand-written and can go stale if the proxy contract changes. Mitigate
  by keeping the page a *summary* that links to `docs/CNR`/`docs/JPRS` and the live consoles rather
  than restating registrar command detail.
- **Decision (env base URL):** show both `/api/cnr/live` and `/api/cnr/testing` shapes generically
  rather than pre-filling the caller's env, so one page serves both key types.
- **Open question:** URL path — plan uses `/api-docs`; confirm the user prefers this over
  `/docs/api`. Defaulting to `/api-docs` unless told otherwise.
- **Open question:** should the page list the caller's own service ids from `list_api_services()`,
  or stay fully generic? Plan includes a small "your services" hint (id/provider/label only, never
  keys); drop it if the user wants a purely static page.

## 8. Rollout
- No migration, no env vars, no feature flag. Additive route + view + nav link + test.
- Deploy: merge, run existing test suite, `php spark routes` to confirm the new route, smoke-test
  the page as both client and admin.

---
## Decisions confirmed (2026-07-14, orchestrator)
1. Route path: **/api-docs** (single shared route under authFilter, view picks nav zone via is_admin()).
2. Keep the page **fully generic** — no per-user "your services" hint (simpler, zero data coupling). Examples use placeholder tokens; never render a real access_key.
