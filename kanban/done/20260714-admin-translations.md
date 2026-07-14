# Plan: Admin Translations management

**Task:** 20260714-admin-translations
**Created:** 2026-07-14
**Branch suggestion:** feature/admin-translations

## 1. Goal
Give admins a CRUD screen to manage UI translation strings (per language-code + slug → content)
plus an export endpoint that streams a language's strings as a downloadable file. Mirrors the
established `Admin\Settings` / `Admin\Servers` module pattern exactly.

## 2. Scope
- **In scope:**
  - `TranslationModel` over the existing `translation` table (searchAdmin / countAdmin / find + two small helpers).
  - Idempotent migration for `translation` (guarded no-op on dev/prod, real create on greenfield/tests).
  - `Admin\Translations` controller: index / create / edit(JSON) / update / delete + **export**.
  - View `app/Views/admin/translations/index.php` (list, keyword search, language filter, pagination, create/edit/delete modals, export button).
  - Sidebar entry under `$navGroups['Admin']`.
  - Routes under the `admin` group (`authFilter` + `adminFilter`; POSTs behind `csrf`).
  - Model + feature tests.
- **Out of scope:**
  - Any runtime i18n rewiring (no `lang()` lookup, no `setLocale`, no locale negotiation). `session('language')`
    and per-user `language` stay exactly as they are today — this task is admin CRUD + export only.
  - Import/upload of translation files.
  - A `languages` reference table (none exists in the live schema; language codes come from
    `DISTINCT lang_code` on the `translation` table).
  - Legacy `export_to_files()` PHP-array echo (replaced by safe JSON stream — see §7).

## 3. Affected areas
| Layer | Files / Symbols | Notes |
|-------|-----------------|-------|
| Backend | `app/Models/TranslationModel.php` (new) | Mirror `SiteVariableModel`, but NO soft-delete / NO timestamps (live schema has neither). |
| Backend | `app/Controllers/Admin/Translations.php` (new) | Mirror `Admin\Settings` + add `export()`. |
| Database | `app/Database/Migrations/2026-07-14-130000_CreateTranslation.php` (new) | Guarded `up()` via `resetDataCache()`→`tableExists()`; tests-only `down()`. |
| Config / Routes | `app/Config/Routes.php` (edit, additive) | Append a `translations*` block inside the existing `admin` group. |
| Frontend (Views) | `app/Views/admin/translations/index.php` (new) | Mirror `admin/settings/index.php`. |
| Frontend (Views) | `app/Views/layouts/app.php` (edit, additive) | One entry in `$navGroups['Admin']`. |
| Tests | `tests/database/TranslationModelTest.php`, `tests/feature/Web/AdminTranslationsTest.php` (new) | Mirror `SiteVariableModelTest` / `AdminSettingsTest`. |

## 4. Impact analysis
All production symbols are **new** — CodeGraph blast radius is N/A (nothing calls them yet). The only
edits to *existing* files are **purely additive**:
- `app/Config/Routes.php` — append 6 route lines inside the `admin` group closure. No existing route/symbol changed.
- `app/Views/layouts/app.php` — append one array entry to `$navGroups['Admin']`. No existing entry changed.

No rename/refactor of any existing symbol → no caller enumeration required. Blast radius: **minimal**.

## 5. Implementation steps

### Phase 1 — Backend
1. [ ] Create migration `2026-07-14-130000_CreateTranslation.php` — owner: `backend-coder`
   - Mirror `CreateSiteVariable`: `Database::connect($this->DBGroup)->resetDataCache()`, early-return if `tableExists('translation')`.
   - Fields (match live schema exactly): `id` BIGINT unsigned AI PK; `lang_code` VARCHAR(5) NOT NULL;
     `location` VARCHAR(255) NULL; `slug` VARCHAR(255) NOT NULL; `content` TEXT NOT NULL.
   - `addPrimaryKey('id')`, `addUniqueKey(['lang_code', 'slug'])`. **No** `created_at` / `deleted_at`.
   - `down()`: drop only when connected to the configured `tests` DB group (copy the Settings guard verbatim).
   - Acceptance: `php spark migrate` is a no-op where `translation` already exists; on the tests DB it creates a
     table whose `SHOW COLUMNS` matches the legacy schema (id, lang_code, location, slug, content).

2. [ ] Create `TranslationModel` — owner: `backend-coder`
   - `$table = 'translation'`, `returnType = 'object'`, `useAutoIncrement = true`.
   - **`useSoftDeletes = false`, `useTimestamps = false`** (divergence from `SiteVariableModel` — live table has no such columns).
   - `allowedFields = ['lang_code', 'location', 'slug', 'content']`.
   - `SORT_ALLOWLIST = ['id', 'lang_code', 'slug']`; `KEYWORD_FIELDS = ['slug', 'content']`.
   - `findTranslation(int $id): ?object` — typed wrapper over `find()`.
   - `searchAdmin(array $filters, string $sort = 'lang_code', string $order = 'asc', int $limit = 25, int $offset = 0): array`
     — keyword LIKE across slug/content, exact `lang_code` filter; secondary `id` sort in same direction; sort allowlist fallback to `lang_code`.
   - `countAdmin(array $filters): int` — same `applyAdminFilters()` as search (shared, so rows/total can't drift).
   - `applyAdminFilters(BaseBuilder, array $filters)`: exact `lang_code`, then keyword group over `KEYWORD_FIELDS`.
   - `distinctLangCodes(): array` — `select('lang_code')->distinct()->orderBy('lang_code')` → list of codes (for the filter dropdown + export selector). ponytail: one query, no new table.
   - `allByLang(string $langCode): array` — rows (slug, content) for one language, no pagination, ordered by slug (feeds `export()`).
   - Acceptance: unit tests in Phase 3 pass.

3. [ ] Create `Admin\Translations` controller — owner: `backend-coder`
   - Extends `BaseController`; uses `jsonSuccess` / `jsonFailed` envelope (carries `csrf_hash`), `resolveLimit()` clamp copied from `Settings`.
   - `index(): string` — read `page/limit/sort/order/keyword/lang_code`; render `admin/translations/index`
     with `items`, `total`, `page`, `limit`, `sort`, `order`, `filters`, and `langCodes` (from `distinctLangCodes()`).
   - `create(): ResponseInterface` — validate `lang_code=required|max_length[5]`, `slug=required|max_length[255]`,
     `content=required`, `location=permit_empty|max_length[255]`. **Composite-unique check** (`lang_code`+`slug`)
     done manually in the controller (CI4 `is_unique` can't span two columns) → `jsonFailed` on duplicate. Insert on success.
   - `edit(int $id): ResponseInterface` — return `{translation: {id, lang_code, location, slug, content}}` or `jsonFailed('Translation not found.', 404)`.
   - `update(): ResponseInterface` — validate (+ `id=required|is_natural_no_zero`); 404 if `findTranslation()` misses;
     composite-unique-**except-self** check on (`lang_code`,`slug`); update on success.
   - `delete(): ResponseInterface` — validate `id`; 404 if missing; **hard delete** (no soft-delete column). Return success.
   - `export(): ResponseInterface` — GET. Require `lang_code` (validate non-empty, `max_length[5]`; 400 if missing).
     Stream `allByLang()` as a downloadable JSON object mapping `slug → content`, `Content-Type: application/json`,
     `Content-Disposition: attachment; filename="translations-{lang_code}.json"`. Use `json_encode` (native, no deps, no eval risk). See §7 for the divergence rationale.
   - Acceptance: feature tests in Phase 3 pass.

4. [ ] Add routes — owner: `backend-coder` (edit `app/Config/Routes.php`, additive, inside the `admin` group after the `settings` block)
   ```php
   $routes->get('translations', 'Admin\Translations::index');
   $routes->get('translations/export', 'Admin\Translations::export');
   $routes->post('translations/create', 'Admin\Translations::create', ['filter' => 'csrf']);
   $routes->get('translations/edit/(:num)', 'Admin\Translations::edit/$1');
   $routes->post('translations/update', 'Admin\Translations::update', ['filter' => 'csrf']);
   $routes->post('translations/delete', 'Admin\Translations::delete', ['filter' => 'csrf']);
   ```
   - Acceptance: `php spark routes` lists all six; export is GET (read-only, no CSRF needed) but still under `authFilter`+`adminFilter`.

### Phase 2 — Frontend (Views)
1. [ ] Create `app/Views/admin/translations/index.php` — owner: `frontend-coder`
   - Copy `admin/settings/index.php` structure: `$this->extend('layouts/app')`, `content` section, Alpine `translationsAdmin()` component.
   - Table columns: **Lang** (sortable), **Slug** (sortable), **Content** (truncate + `title`), **Location** (truncate), **Actions** (Edit / Delete).
   - Filter bar: keyword search **plus** a `lang_code` `<select>` populated from `$langCodes` (blank = all); per-page 25/50/100; hidden `sort`/`order`; Search + Clear.
   - **Export control:** a link/button `GET /admin/translations/export?lang_code=<selected>` — disabled/hidden until a language is chosen (export requires `lang_code`). Reuse the current filter's `lang_code`.
   - Create modal: inputs `lang_code` (maxlength 5), `slug` (maxlength 255), `location` (maxlength 255, optional), `content` (textarea).
   - Edit modal: AJAX-load via `/admin/translations/edit/{id}`, same fields; note `(lang_code, slug)` must stay unique.
   - Delete via the shared `confirm` dispatch; toasts via shared `toast` dispatch. Reuse `postForm()` helper.
   - UI notes: TailAdmin/Tailwind classes only (copy Settings styling), Alpine for modals/state, `fetch()` for AJAX with the CSRF meta, `esc()` on **every** dynamic value (`esc(..., 'attr')` in attributes, `json_encode` + `esc(...,'attr')` for JS-passed strings).
   - Acceptance: page renders, search + language filter work, create/edit/delete round-trip, export link downloads the chosen language.

2. [ ] Add sidebar entry — owner: `frontend-coder` (edit `app/Views/layouts/app.php`, additive)
   - Append to `$navGroups['Admin']`: `['label' => 'Translations', 'href' => '/admin/translations']`.
   - Acceptance: "Translations" appears in the Admin sidebar group and links to the index.

### Phase 3 — Tests
1. [ ] `tests/database/TranslationModelTest.php` — owner: `tester`
   - `findTranslation` returns row / null; `searchAdmin` keyword matches slug and content; `lang_code` filter narrows results;
     sort allowlist rejects arbitrary column (falls back to `lang_code`); `countAdmin` matches unpaginated count for same filters;
     `distinctLangCodes` returns unique codes; `allByLang` returns only that language's rows.
   - **No soft-delete test** (hard delete) — instead assert `delete()` removes the row entirely.
2. [ ] `tests/feature/Web/AdminTranslationsTest.php` — owner: `tester`
   - create stores + returns success envelope with `csrf_hash`; create rejects missing required fields; create rejects duplicate `(lang_code, slug)`.
   - edit returns fields / 404 on unknown id.
   - update persists; update rejects colliding `(lang_code, slug)` on another row but allows keeping own; 404 on unknown id.
   - delete hard-removes the row / 404 on unknown id.
   - **export**: returns 200, `Content-Type: application/json`, `Content-Disposition` attachment, body is a JSON map of the chosen language's slug→content; export without `lang_code` → 400.
   - gating: non-admin client → redirect `/dashboard`; guest → redirect `/login`.
   - Mirror `AdminSettingsTest` infra: `DatabaseTestTrait` + `FeatureTestTrait` + `WebAuthFixture`, `$migrate=true`, `$refresh=true`, `$namespace=null`, `tearDown` destroys session, `db_proxy_test` via `RUNNING_MODE=testing`.

## 6. Test plan
- **Unit (model):** searchAdmin (keyword slug/content, lang_code filter, sort fallback), countAdmin parity, findTranslation, distinctLangCodes, allByLang, hard delete.
- **Integration (feature, real routing+filters+CSRF):** full CRUD, composite-unique enforcement, export stream + headers, admin gating, guest redirect.
- **Browser / E2E:** create a `ja` string, filter by `ja`, edit it, export `ja` (file downloads with the string), delete it.
- **Manual smoke:** `RUNNING_MODE=testing vendor/bin/phpunit --filter Translation`; then in the app, visit `/admin/translations`, add/edit/delete a row, click Export for a language and confirm the JSON download.

## 7. Risks & open questions
- **Schema already live:** the `translation` table almost certainly already exists on the shared dev DB (same as `site_variable`).
  The coder should confirm with `SHOW COLUMNS FROM translation` (against `db_proxy_dev`) and record it in the card, exactly as the Settings card did. The guarded migration means no-op there.
- **No soft-delete / no timestamps** — deliberate divergence from the Settings/Servers pattern to match the real schema. Delete is permanent; the delete confirm copy should make that clear. Do **not** add `deleted_at`/`created_at` columns just to match the template.
- **Composite unique** `(lang_code, slug)` can't use CI4 `is_unique`; enforced by a manual model/controller check plus the DB unique key as backstop. Ensure the check runs before insert/update.
- **Export format (divergence):** legacy `export_to_files()` echoed a raw PHP `return [...]` array with only naive quote-escaping — an injection/eval hazard. The rebuild streams **JSON** (`json_encode`, safe, importable) instead. ponytail: JSON only for now.
  - Open question: is **CSV** also wanted? Trivial to add later (`fputcsv` to `php://output`, `?format=csv`). Defaulting to JSON-only unless the user asks for CSV.
- **`location` column:** present in the live schema, appears to be an unused hint field. Kept and editable but optional; no behavior depends on it.

## 8. Rollout
- Migration order: new file `2026-07-14-130000_CreateTranslation.php` runs after existing migrations; safe no-op on dev/prod, creates on greenfield/tests.
- No env vars, no feature flag. Reuses `app.rpp_default` for pagination like the other admin lists.
- Deploy notes: run `php spark migrate` (no-op where the table exists); no data backfill required. No changes to auth, sessions, or i18n runtime.
