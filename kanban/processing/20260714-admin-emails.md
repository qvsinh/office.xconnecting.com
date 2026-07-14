# Plan: Admin Email Templates + Sent-Email Log

**Task:** 20260714-admin-emails
**Created:** 2026-07-14
**Branch suggestion:** `feature/admin-emails`

## 1. Goal
Give admins a place to manage the HTML email templates the platform sends (subject/body
per language) and to read the log of messages that were actually sent. Templates get full
CRUD; the sent-email log is read-only (list + search + view-detail modal).

## 2. Scope
- **In scope:**
  - `email_templates` CRUD (list/search/paginate, create, edit-JSON, update, delete) — mirrors `Admin\Settings`/`Admin\Servers`.
  - `emails` (sent-email log) read-only list with keyword search, pagination, and a view-detail modal.
  - Two idempotent migrations (`email_templates`, `emails`), two models, one controller for templates + one read-only controller for the log, views, routes, sidebar entries, and tests.
- **Out of scope:**
  - The email **send pipeline** itself (SMTP/AWS/SparkPost dispatch, `sendmail_helper`) — not built here; the log table is just populated later by that pipeline.
  - Open/click **tracking endpoints** (`/e/v/{id}`, `/e/c/{id}`) and the `views`/`clicks` counters (columns exist; no tracking routes in v1).
  - Legacy behaviour of writing template `content` to executable `.php` view files (`file_put_contents(app/Views/emails/{lang}/{name}.php)`) — deliberately **not** ported (RCE risk); see §7.
  - Actually rendering/sending a template with token substitution — out of scope for this task; documented as a follow-up.
  - Any client-facing (non-admin) email UI.

## 3. Affected areas
| Layer | Files / Symbols | Notes |
|-------|-----------------|-------|
| Backend | `app/Controllers/Admin/EmailTemplates.php` (new) | index/create/edit/update/delete, JSON envelopes |
| Backend | `app/Controllers/Admin/Emails.php` (new) | read-only index + view-detail (JSON html) |
| Backend | `app/Models/EmailTemplateModel.php` (new) | searchAdmin/countAdmin/findTemplate |
| Backend | `app/Models/EmailModel.php` (new) | searchAdmin/countAdmin/findEmail (read-only usage) |
| Database | `app/Database/Migrations/2026-07-14-130000_CreateEmailTemplates.php` (new) | guarded/idempotent |
| Database | `app/Database/Migrations/2026-07-14-140000_CreateEmails.php` (new) | guarded/idempotent |
| Frontend (Views) | `app/Views/admin/email_templates/index.php` (new) | mirrors `admin/settings/index.php` |
| Frontend (Views) | `app/Views/admin/emails/index.php` (new) | read-only list + detail modal |
| Config / Routes | `app/Config/Routes.php` | add routes in the admin group |
| Frontend (Views) | `app/Views/layouts/app.php` | add sidebar entries to `$navGroups['Admin']` |
| Tests | `tests/database/EmailTemplateModelTest.php`, `tests/database/EmailModelTest.php`, `tests/feature/Web/AdminEmailTemplatesTest.php`, `tests/feature/Web/AdminEmailsTest.php` | mirror Server/Settings tests |

## 4. Impact analysis
All new symbols — no existing symbol is renamed or refactored, so blast radius is additive
and low.
- `app/Config/Routes.php` — appending new routes inside the existing `admin` group (`authFilter`+`adminFilter`). No existing route changes.
- `app/Views/layouts/app.php` — appending two items to `$navGroups['Admin']` array literal. Every admin view that extends the layout picks them up automatically; no behavioural change to existing links.
- New models/controllers have zero inbound callers until routes point at them.
- CodeGraph index for this repo is still sparse; confirmed via grep that no `EmailTemplate`/`Email` symbols exist under `app/` yet, so no collisions.

## 5. Implementation steps

### Confirmed legacy schema (source: `../proxy.services.vn/setup/db_proxy_service.sql` lines 233–288)

**`email_templates`** (templates, keyed by `(name, lang_code)`):
| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT AI PK | |
| `name` | VARCHAR(255) NOT NULL | the template key/slug (e.g. `signup_verify_email`) |
| `lang_code` | VARCHAR(2) NULL | `en` / `ja` |
| `subject` | VARCHAR(255) NOT NULL | may contain `{amount}`, `{invoice_id}` tokens |
| `content` | LONGTEXT NULL | admin-authored HTML body (trusted content) |
| `active` | INT NOT NULL DEFAULT 1 | |
| `created_at` | DATETIME | |
| `deleted_at` | DATETIME NULL | soft delete |
| UNIQUE KEY `(name, lang_code)` | | idempotency key on upsert |

**`emails`** (sent-message log):
| Column | Type | Notes |
|--------|------|-------|
| `id` | VARCHAR(255) PK | a **token/UUID**, not auto-increment (used by legacy tracking URLs) |
| `subject` | VARCHAR(255) NOT NULL | |
| `content` | LONGTEXT NOT NULL | rendered HTML that was sent |
| `from_email` | VARCHAR(100) NOT NULL | |
| `to_email` | VARCHAR(255) NULL | |
| `status` | INT DEFAULT 0 | 0=pending, 1=sent, 2=failed |
| `name` | VARCHAR(100) NULL | template name that produced this email |
| `views` | INT DEFAULT 0 | open tracking (unused in v1) |
| `clicks` | INT DEFAULT 0 | click tracking (unused in v1) |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | KEY `emails_created_IDX` |
| `deleted_at` | DATETIME NULL | soft delete |
| `error_message` | TEXT NULL | populated when `status=2` |

**Placeholder/token style (confirmed):** subjects use `{token}` syntax (`{amount}`, `{invoice_id}`);
legacy bodies were PHP view files using `$name`, `$url_verify`, `$email_id` plus
`track_views_url()`/`track_clicks_url()`. For the rebuild the body is stored as HTML in
`email_templates.content` and (when a send pipeline is built later) rendered via **token
substitution**, not by writing `.php` files. This task does not implement rendering.

### Phase 1 — Backend (owner: `backend-coder`)

1. [ ] Migration `2026-07-14-130000_CreateEmailTemplates.php` — owner: `backend-coder`
   - Create `email_templates` per confirmed schema. Add `updated_at DATETIME NULL` (new column vs legacy) so the model's `useTimestamps` works consistently with Settings/Servers; keep legacy columns otherwise identical. Add UNIQUE `(name, lang_code)` and soft-delete `deleted_at`.
   - Guard with `Database::connect($this->DBGroup)->resetDataCache()` → `if ($db->tableExists('email_templates')) return;` (mirror `CreateServers`).
   - `down()` drops the table **only** when connected to the configured `tests` DB group (mirror `CreateServers::down()`).
   - Acceptance: `php spark migrate` is a no-op on an env where the table exists; test suite regress/latest cycle passes.

2. [ ] Migration `2026-07-14-140000_CreateEmails.php` — owner: `backend-coder`
   - Create `emails` per confirmed schema. Keep `id` as **VARCHAR(255) PK** (token, `useAutoIncrement=false`) to stay faithful to the legacy tracking model; add `updated_at DATETIME NULL`. Add `KEY (created_at)` and a `KEY (status)` (the list filters/sorts on both).
   - Same `resetDataCache()`→`tableExists()` guard and tests-only `down()`.
   - Acceptance: idempotent; table + indexes created on a fresh test DB.

3. [ ] `EmailTemplateModel` — owner: `backend-coder`
   - `$table='email_templates'`, `$primaryKey='id'`, `$returnType='object'`, `$useSoftDeletes=true`, `$useTimestamps=true`, `$allowedFields=['name','lang_code','subject','content','active']`.
   - `SORT_ALLOWLIST = ['id','name','lang_code','subject','active','created_at']`; `KEYWORD_FIELDS = ['name','subject']`.
   - `findTemplate(int $id): ?object`, `searchAdmin($filters,$sort,$order,$limit,$offset): array`, `countAdmin($filters): int`, shared `applyAdminFilters()` (keyword LIKE across name/subject; optional `lang_code` exact filter; optional `active` 0/1 filter). Secondary `id` sort for deterministic paging — mirror `ServerModel`.
   - Acceptance: unit-covered filters/sort/soft-delete behaviour matches `ServerModel` semantics.

4. [ ] `EmailModel` — owner: `backend-coder`
   - `$table='emails'`, `$primaryKey='id'`, `$useAutoIncrement=false`, `$returnType='object'`, `$useSoftDeletes=true`, `$useTimestamps=true`. `$allowedFields` limited to the send-pipeline columns (`id,subject,content,from_email,to_email,status,name,error_message`) even though this task only reads — keep it honest for the future writer.
   - `SORT_ALLOWLIST = ['created_at','status','to_email','name']` (default `created_at desc`); `KEYWORD_FIELDS = ['name','subject','to_email']`. `findEmail(string $id): ?object`, `searchAdmin/countAdmin/applyAdminFilters` — keyword: if the term is a valid email, match `to_email` exactly, else LIKE across name/subject/to_email (mirror legacy `Emails::index`); optional `status` in [0,1,2] filter.
   - Acceptance: search/paginate/soft-delete unit-covered; email-keyword branch tested.

5. [ ] `Admin\EmailTemplates` controller — owner: `backend-coder`
   - Extends `BaseController`. Methods: `index()` (view), `create()`, `edit(int $id)` (JSON record for modal), `update()`, `delete()`. Use `jsonSuccess`/`jsonFailed` + `csrf_hash` envelopes; `resolveLimit()` clamp to 100 (mirror Settings).
   - Validation: `name` required max_length[255]; `lang_code` required in_list of supported codes (`en`,`ja` — read supported set from existing config/translations if present, else hardcode the two legacy dirs and note it); `subject` required max_length[255]; `content` required; `active` permit_empty in_list[0,1]. On create/update enforce the `(name,lang_code)` uniqueness with `is_unique[email_templates.name,...]` scoped by lang — since CI4 `is_unique` can't do a composite easily, do a model-level pre-check (`where name && lang_code`, excluding current id) and return `jsonFailed` on collision. Document that choice inline.
   - **Body is trusted admin HTML:** store `content` verbatim (no escaping on store). Escaping happens per-context in views (see Phase 2). Add a class docblock stating the body is trusted admin content and is only rendered raw inside the isolated preview, never interpolated into the admin chrome.
   - Acceptance: CRUD returns correct envelopes; validation rejects bad input with 400; composite-unique collision returns failure; all POSTs require CSRF; unauthenticated/non-admin blocked by filters.

6. [ ] `Admin\Emails` controller (read-only) — owner: `backend-coder`
   - Extends `BaseController`. Methods: `index()` (view with search/paginate) and `view(string $id)` returning `jsonSuccess` with a rendered detail-modal partial (or the raw record fields) for the modal. **No** create/update/delete.
   - Acceptance: list renders with filters; `view()` returns 404 JSON for unknown id, 200 with detail for known id; filters/gating enforced.

7. [ ] Routes — owner: `backend-coder`
   - Inside the existing admin group (`authFilter`+`adminFilter`):
     - `GET  email-templates` → `Admin\EmailTemplates::index`
     - `POST email-templates/create` → `...::create` `['filter'=>'csrf']`
     - `GET  email-templates/edit/(:num)` → `...::edit/$1`
     - `POST email-templates/update` → `...::update` `['filter'=>'csrf']`
     - `POST email-templates/delete` → `...::delete` `['filter'=>'csrf']`
     - `GET  emails` → `Admin\Emails::index`
     - `GET  emails/view/(:segment)` → `Admin\Emails::view/$1` (id is a token, not `:num`)
   - Acceptance: `php spark routes` lists all seven; no auto-routing.

### Phase 2 — Frontend (Views) (owner: `frontend-coder`)

1. [ ] `app/Views/admin/email_templates/index.php` — owner: `frontend-coder`
   - Mirror `app/Views/admin/settings/index.php`: extend the shared layout, TailAdmin table, keyword + `lang_code` + `active` filter controls, pager, create/edit modals (Alpine `x-data`), AJAX via `fetch()` with CSRF token from meta tags, toast/confirm via the shared Alpine components.
   - **Escape everything in the LIST view** — `esc()` on `name`, `subject`, `lang_code`, and any preview of `content`. The `content` textarea in the edit modal is a plain `<textarea>` whose value is `esc($item->content)` (escaping is correct inside a textarea value).
   - **Preview:** if a body preview is offered, render it inside a **sandboxed `<iframe srcdoc>`** (or a clearly isolated container) so the trusted admin HTML renders without polluting/scripting the admin page; never inject `content` directly into the surrounding DOM. UI notes: TailAdmin components, Alpine interactivity, `fetch()` AJAX, shared toast/confirm.
   - Acceptance: list renders, filters work, create/edit/delete round-trip via AJAX and show toasts; no unescaped output in the list; preview is isolated.

2. [ ] `app/Views/admin/emails/index.php` — owner: `frontend-coder`
   - Read-only TailAdmin table: columns `to_email`, `subject`, `name` (template), `status` (badge: pending/sent/failed), `created_at`. Keyword search + status filter + pager. A "View" button opens a detail modal populated via `fetch('/admin/emails/view/{id}')`.
   - Detail modal shows headers (`from_email`, `to_email`, `subject`, `status`, `created_at`, `error_message`) all `esc()`'d, and the message body rendered in a **sandboxed iframe** (same isolation rule as the template preview). No create/edit/delete controls.
   - Acceptance: list + search + pagination work; detail modal opens and shows an isolated body; page is strictly read-only.

3. [ ] Sidebar — owner: `frontend-coder`
   - Add to `$navGroups['Admin']` in `app/Views/layouts/app.php`: `['label'=>'Email Templates','href'=>'/admin/email-templates']` and `['label'=>'Sent Emails','href'=>'/admin/emails']`.
   - Acceptance: both entries appear in the admin sidebar and link correctly.

### Phase 3 — Tests (owner: `tester`)

1. [ ] `tests/database/EmailTemplateModelTest.php` — mirror `ServerModelTest`: findTemplate hit/miss, keyword search across name+subject, lang_code + active filters, sort allowlist fallback (inject a malicious sort column), countAdmin matches unpaginated count, soft-delete exclusion.
2. [ ] `tests/database/EmailModelTest.php` — findEmail (string id) hit/miss, keyword LIKE vs exact-email branch, status filter, default `created_at desc` order, sort allowlist fallback, soft-delete exclusion.
3. [ ] `tests/feature/Web/AdminEmailTemplatesTest.php` — mirror `AdminServersTest`: create success envelope + row persisted, validation 400s, composite-unique `(name,lang_code)` collision rejected, edit JSON 200/404, update, delete soft-deletes, CSRF required, admin gating (non-admin/unauth blocked).
4. [ ] `tests/feature/Web/AdminEmailsTest.php` — seed rows directly via `EmailModel`; assert list route 200 for admin, `view/{id}` 200 for known id + 404 for unknown, gating; assert **no** write routes exist (create/update/delete return 404/405).
   - All tests: `protected $migrate=true; $refresh=true; $namespace=null;`, `DatabaseTestTrait`+`FeatureTestTrait`+`WebAuthFixture`, run under `db_proxy_test` with `RUNNING_MODE=testing`.

## 6. Test plan
- **Unit / Model:** `EmailTemplateModel` and `EmailModel` search/count/filter/sort/soft-delete (see Phase 3.1–3.2), incl. sort-allowlist SQL-injection fallback and the email-vs-LIKE keyword branch.
- **Integration (feature, JSON + routing + filters):** template CRUD envelopes, validation, composite uniqueness, edit-JSON 200/404, soft delete; sent-email log list + `view/{id}` 200/404; CSRF enforcement; admin gating on every route.
- **Browser / E2E (manual):** create a template → appears in list; edit → modal prefilled → save → toast; preview renders in isolated iframe; delete → confirm modal → row gone; open Sent Emails → search by recipient → open detail modal.
- **Manual smoke:** `php spark migrate`; `php spark routes | grep -E "email-templates|emails"`; log in as admin, exercise both pages; confirm non-admin cannot reach `/admin/email-templates`.

## 7. Risks & open questions
- **Legacy RCE pattern dropped (decision, not a question):** legacy `Email_templates::update()` did `file_put_contents("../app/Views/emails/{lang}/{name}.php", $content)` — writing user-editable content into an executable PHP view. This is a remote-code-execution vector and is **not** ported. Templates live in the DB only; rendering (future work) uses token substitution over the stored HTML.
- **Trusted-HTML boundary:** template `content` and the logged email body are raw HTML by design. Mitigation: escape all *metadata* in lists/modals; render the *body* only inside a sandboxed `<iframe srcdoc>` (`sandbox` with no `allow-scripts`), never interpolated into the admin page DOM. This must not be "simplified away" in review.
- **`emails.id` is a VARCHAR token, not an int** — the log's `view` route uses `(:segment)` and the model sets `useAutoIncrement=false`. Don't treat it like the numeric PKs on servers/settings.
- **`lang_code` allowed set** — legacy only ships `en`/`ja` view dirs. Open question: is the supported language list defined anywhere in proxyv2 config yet? If yes, source the validation `in_list` from it; if not, hardcode `en,ja` and leave a `ponytail:` note to centralise later.
- **Log volume** — the `emails` table can grow large. v1 is read-only list + keyword search + pager with a clamped limit (≤100) and a `created_at` index, which is sufficient. Keyset pagination / retention pruning are deferred until the send pipeline exists and the table is actually populated.
- **Send pipeline absent** — until it's built, the `emails` list will be empty in practice. That's expected; this task delivers the schema + read UI so the pipeline has somewhere to write.

## 8. Rollout
- **Migration order:** `2026-07-14-130000_CreateEmailTemplates` then `2026-07-14-140000_CreateEmails` (both idempotent, safe to re-run). Run `php spark migrate`.
- **No env vars / feature flags** — pure additive admin feature behind existing `authFilter`+`adminFilter`.
- **Deploy notes:** ship backend (Phase 1) before frontend (Phase 2) so routes exist when views call them — matches the established Servers/Settings sequence where feature tests only hit JSON routes until the view lands. No data backfill; tables start empty.
