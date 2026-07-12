# Plan: Registrar API Proxy Endpoints (CNR + JPRS) and API-Key Auth

**Task:** 20260712
**Created:** 2026-07-12
**Branch suggestion:** `feature/api-proxy-endpoints`

## 1. Goal
Deliver the first backend feature of the rebuild: authenticated pass-through proxy endpoints
`/api/cnr/(live|testing)` and `/api/jprs/(live|testing)` that authenticate a caller by their
Hashids API access key, forward the request to the correct upstream registrar (CNR/RRP over HTTP,
JPRS over client-cert), normalize the response into a single JSON envelope, and log the call to
`api_history`. This is the foundation every later API/console/history card builds on.

## 2. Scope
- **In scope:**
  - Explicit routes for the `api` group with the `apiFilter` (GET `cnr/(:any)`; GET+POST `jprs/(:any)`).
  - `ApiFilter` (stateless: destroy session in `after()`).
  - `ApiController` base: Bearer-token extraction, Hashids decode `[user_id, env, service_id]`,
    validation against `users_api_access_keys` (`is_enable`, `user_id`, `service_id`),
    `validateEndpoint()` (URL env segment must match key env), `updateTokenLastAccess()`,
    `logApiCall()` (gated by the key's `enable_log`), and local `fetchHistory()` for the
    `History`/`command=History` shortcut.
  - `CnrApi::proxy` — RRP proxy: `command` required, domain/command restrictions, inject
    `cnr.{service_id}.name|password|url` env creds, forward via `service('curlrequest')`,
    `parseRrpResponse()`, RRP `2xx` code = success.
  - `JprsApi::proxy` — JPRS `.jp` variant: PEM client-cert auth from `jprs.{service_id}.*`,
    ISO-2022-JP payload build/parse, 3-digit `2xx` line = success.
  - Normalized envelope `{status, api_service_id, data|message}`.
  - Migrations for `users_api_access_keys` and `api_history` (consolidated), Models
    `ApiAccessKeyModel` + `ApiHistoryModel`, `service_config_helper`, `app_helper` `get_hashids()`.
  - Hashids setup via composer (`hashids/hashids`) + `hashid_salt` env.
  - Unit + feature tests mocking the upstream curl (auth accept/reject, env mismatch, missing
    command, upstream success/error mapping, logging on/off, sensitive-field stripping).
- **Out of scope (do NOT build here):**
  - Admin/user API-key management UI (`Admin\ApiAccessKeys`, `User::api_keys`) — later card.
  - Developer consoles `ConsoleCnr` / `ConsoleJprs` (admin + user).
  - API-history viewer UI (`User\ApiHistory`, `Admin\ConsolePsiBillingApi`).
  - PSI Billing API proxy, CMS, CRM, general user auth/login/2FA, notifications.
  - Any `app/Views/*` — this feature renders no HTML.

## 3. Affected areas
| Layer | Files / Symbols | Notes |
|-------|-----------------|-------|
| Backend | `app/Controllers/Api/ApiController.php` (new, abstract base) | Auth, env check, logging, history fetch |
| Backend | `app/Controllers/Api/CnrApi.php` (new) | RRP proxy + `parseRrpResponse` |
| Backend | `app/Controllers/Api/JprsApi.php` (new) | JPRS proxy + payload build/parse |
| Backend | `app/Filters/ApiFilter.php` (new) | `after()` destroys session |
| Backend | `app/Models/ApiAccessKeyModel.php` (new) | maps `users_api_access_keys` |
| Backend | `app/Models/ApiHistoryModel.php` (new) | maps `api_history` (write + read) |
| Backend | `app/Helpers/service_config_helper.php` (new) | `get_service_config`/`get_cnr_service_config`/`get_jprs_service_config` |
| Backend | `app/Helpers/app_helper.php` (new, minimal) | `get_hashids()` only (port just what's needed) |
| Frontend (Views) | none | Backend-only feature |
| Database | `app/Database/Migrations/*_CreateUsersApiAccessKeys.php`, `*_CreateApiHistory.php` (new) | Consolidated final schema |
| Config / Routes | `app/Config/Routes.php` (append `api` group), `app/Config/Filters.php` (add `apiFilter` alias), `composer.json` (`hashids/hashids`), `.env` (`hashid_salt`, `cnr.*`, `jprs.*` already present) | |
| Tests | `tests/unit/*`, `tests/feature/Api/*` | Mock `curlrequest`; DB via CI4 test traits |

## 4. Impact analysis
CodeGraph index for this repo is effectively empty (greenfield — only stock `Home` exists), so the
blast radius is near-zero: every controller/model/helper/migration/filter above is a **new file**.
Only two existing stock files are touched by **appending**, not modifying:
- `app/Config/Routes.php` — add the `api` route group (no existing route changes).
- `app/Config/Filters.php` — add one alias `'apiFilter' => ApiFilter::class` (no behavior change to
  globals). CSRF stays disabled/irrelevant for these Bearer-authenticated GET endpoints.
- `composer.json` — add `hashids/hashids` dependency (additive).

No existing symbol is renamed or refactored; no consumers to break. Legacy reference lives in
`../proxy.services.vn` (indexed) and is read-only.

**Legacy quirks to fix, not port (call out in review):**
- Legacy Bearer parse `strpos($h, 'Bearer ') == 0` is a latent bug (loose `== 0` also matches
  `false`). Rebuild uses `str_starts_with($h, 'Bearer ')`.
- Legacy `ApiLogModel` maps to an unused `api_logs` table while the proxy writes raw to
  `api_history`. Rebuild collapses this: `ApiHistoryModel` maps `api_history` and is used for both
  write (`logApiCall`) and read (`fetchHistory`) — no raw `db()->table()` in controllers.

## 5. Implementation steps

### Phase 1 — Foundations (deps, config, schema, helpers)
1. [ ] Add `hashids/hashids` via composer; add `hashid_salt=...` to `.env` (and document in
   `env` sample). Port `get_hashids($minLen = 32)` into `app/Helpers/app_helper.php` wrapping the
   composer class — owner: `backend-coder`
   - Acceptance: `helper('app'); get_hashids(32)->encode([1,1,1])` round-trips via `->decode()`;
     no local Hashids library copied (composer package only).
   - Ponytail: use the composer package; do not port the legacy `app/Libraries/Hashids` folder.
2. [ ] Port `app/Helpers/service_config_helper.php` (`get_service_config`, `get_cnr_service_config`,
   `get_jprs_service_config`) reading `cnr.{id}.*` / `jprs.{id}.*` from `.env` — owner: `backend-coder`
   - Acceptance: returns `service_api_id`/`url` for a configured id and null-filled array for an
     unknown id; secrets never hardcoded.
3. [ ] Migration `CreateUsersApiAccessKeys` — consolidated final schema — owner: `backend-coder`
   - Columns: `id` BIGINT PK AI; `user_id` BIGINT NOT NULL; `access_key` VARCHAR(255) UNIQUE;
     `user_status` TINYINT(1) UNSIGNED NULL; `service_id` INT UNSIGNED NULL;
     `is_enable` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1; `enable_log` TINYINT(1) UNSIGNED NOT NULL
     DEFAULT 1; `description` TEXT NULL; `last_access` DATETIME NULL; `created_at` DATETIME NULL.
   - Acceptance: `php spark migrate` creates the table with a unique index on `access_key`;
     `migrate:rollback` drops it cleanly.
4. [ ] Migration `CreateApiHistory` — consolidated (base + metadata) — owner: `backend-coder`
   - Columns: `id` BIGINT PK AI; `env` VARCHAR(50) NULL; `service_name` VARCHAR(50) NULL;
     `service_id` BIGINT UNSIGNED NULL; `user_id` BIGINT UNSIGNED NULL; `url` VARCHAR(255) NULL;
     `command` VARCHAR(255) NULL; `domain` VARCHAR(255) NULL; `params` MEDIUMTEXT NULL;
     `response` LONGTEXT NULL; `metadata` TEXT NULL; `status` INT NULL DEFAULT 1;
     `created_at` DATETIME NULL; `deleted_at` DATETIME NULL. Index: `service_name`, `service_id`,
     `command`, `env`.
   - Acceptance: migrates/rolls back; composite lookups used by `fetchHistory`
     (`service_name`+`service_id`) are indexed. Consult **mysql-patterns** for index choices.
5. [ ] `ApiAccessKeyModel` (table `users_api_access_keys`) and `ApiHistoryModel`
   (table `api_history`, `allowedFields` matching the write set) — owner: `backend-coder`
   - Acceptance: `model(ApiHistoryModel::class)->insert($row)` persists a log row;
     `ApiAccessKeyModel` can fetch by `access_key` and update `last_access`.

### Phase 2 — Auth base + filter + routing
1. [ ] `ApiFilter` — `before()` no-op, `after()` calls `session()->destroy()` (stateless) —
   owner: `backend-coder`
   - Acceptance: registered as alias `apiFilter`; session not persisted across API calls.
2. [ ] Register `apiFilter` alias in `app/Config/Filters.php`; append the `api` route group in
   `app/Config/Routes.php` (GET `cnr/(:any)` → `Api\CnrApi::proxy`; `match(['get','post'])`
   `jprs/(:any)` → `Api\JprsApi::proxy`) — owner: `backend-coder`
   - Acceptance: `php spark routes` lists both routes under the `apiFilter` (JPRS shows GET+POST);
     auto-routing stays off.
3. [ ] `ApiController` (abstract, extends `BaseController`) — owner: `backend-coder`
   - `initController` runs `checkUserAccess()`: extract Bearer via `str_starts_with`; reject empty
     with 401 JSON `{status:error,message:Unauthorized}`; decode with `get_hashids(32)`; require
     `user_id`, `env ∈ {0,1}`, `service_id`; load key row via `ApiAccessKeyModel` by `access_key`
     and reject unless `is_enable=1` AND `user_id` AND `service_id` match; set `$this->user`
     (`id`, `env` LIVE/TESTING), `$this->service_id`, `$this->key_enable_log`.
   - `validateEndpoint()`: URI segment 3 ∈ `{live,testing}` and matches key env, else 401
     `Invalid endpoint`.
   - `updateTokenLastAccess()`: set `last_access = now()` on the key.
   - `logApiCall($params)`: return early unless key `enable_log=1`; skip listing commands
     (`QueryEventList|QueryDNSZoneList|QueryContactList|QueryDomainList`) when a `limit` param is
     present; strip `AgentLoginID|OperationPassword|AgentLoginPassword` from stored params; derive
     `domain` (fallback `params.dnszone`); insert via `ApiHistoryModel`; on insert failure
     `log_message('error', ...)` (never throw into the response path).
   - `fetchHistory($serviceName)`: filtered/paginated read from `api_history` scoped to
     `service_name`+`service_id`, sort allowlist `created_at|id|status|command`, `limit` 1..200
     (default 100), `keyword` LIKE on response/params/command, `domain` LIKE; returns the envelope.
   - Acceptance: all reject paths return proper 401/JSON and `exit()` before controller action;
     credentials/keys never appear in any response body.
   - Ponytail: keep validation, env check, and upstream error handling intact — do not "simplify"
     these away. No speculative interfaces; one abstract base, two concrete controllers.

### Phase 3 — CNR proxy
1. [ ] `CnrApi::proxy` — owner: `backend-coder`
   - Require `command` (else 400 envelope). If `command === History` (case-insensitive) →
     `fetchHistory('cnr')`. Enforce `domainCommandRestrictions` (`dnp.sg` blocks `ModifyDomain`) →
     403 envelope. Otherwise `sendRequest($command, $params)`.
   - `sendRequest`: pull `cnr.{service_id}.name|password|url` from env; if any missing return
     `{code:500, description: 'Configuration ... not found'}` (no upstream call); build query
     `s_login,s_pw,command,...params` (extract/strip `metadata` first); forward via
     `service('curlrequest')->get($url, ['query'=>...])`; `parseRrpResponse($body)`; `logApiCall`;
     map RRP `code` starting with `2` → success envelope `{status:success, api_service_id:'cnr.{id}',
     data}` (200), else `{status:error, ..., data, message: description}` (200). Wrap the HTTP call
     in try/catch → log status 0 and return `{code:500, description: e.message}`.
   - `parseRrpResponse`: parse `key=value` lines and `property[name][idx]` arrays into a map.
   - Acceptance: success/error mapping matches RRP `2xx` rule; upstream exception never leaks a
     stack trace to the client; `metadata` stored but not forwarded upstream.

### Phase 4 — JPRS proxy
1. [ ] `JprsApi::proxy` + transport — owner: `backend-coder`
   - Accept **GET and POST** (per decision): merge `getVar()` (query + body) into params so callers
     may send JPRS params either way. Require `Command` (else 400 envelope). `Command === History`
     → `fetchHistory('jprs')`. `sendRequest($command)` builds JPRS plain payload
     (`*Command\nKey:Value...`), converts UTF-8→ISO-2022-JP, POSTs with client cert.
   - **Transport decision (ponytail):** forward via `service('curlrequest')->post(...)` using
     Guzzle client-cert options (`cert` = PEM path or `[path, pass]`, `ssl_key` = key path,
     `verify` = bool from `ssl_verify`), Content-Type `text/plain; charset=iso-2022-jp`, raw
     `body`. This replaces the legacy raw `curl_init` so the upstream is mockable in tests exactly
     like CNR. Cert paths resolve under `writable/app_files/jprs.certs/` from
     `jprs.{id}.pem_cert_path|pem_key_path|pem_key_pass|ssl_verify`.
     `// ponytail: PEM via curlrequest; P12 (SSLCERTTYPE) not exposed by Guzzle — if a service
     needs P12, add a raw-curl fallback path then.` (env samples are all PEM/.crt today.)
   - Missing `AgentLoginID|AgentLoginPassword|url` → 400 `Configuration ... not found` envelope.
     Convert response ISO-2022-JP→UTF-8, parse 3-digit status line, `logApiCall` (with sensitive
     creds stripped), map `2xx` → success envelope with
     `data:{code,description,data,raw_response}`, else error envelope.
   - Acceptance: PEM cert options passed to the mocked client; sensitive login fields absent from
     the logged/stored params; success determined by the `2xx` 3-digit code.

### Phase 5 — Tests
1. [ ] Feature + unit tests — owner: `tester` (see Test plan). Mock upstream by binding a fake
   `curlrequest` service returning canned RRP/JPRS bodies; seed `users_api_access_keys` rows and
   generate matching tokens with `get_hashids(32)`.

## 6. Test plan
- **Unit:**
  - `parseRrpResponse`: `key=value` lines and `property[x][0]` arrays parse correctly.
  - JPRS payload builder: `*Command` header line + `Key:Value` lines, multi-value arrays, trailing
    newline trimmed; UTF-8↔ISO-2022-JP round-trip.
  - JPRS response parser: 3-digit status line extraction, repeated keys collapse to `;`-joined.
  - `logApiCall` gating: returns early when `enable_log=0`; skips listing commands when `limit`
    set; strips `AgentLoginID/OperationPassword/AgentLoginPassword`; derives `domain` fallback.
  - `service_config_helper`: known vs unknown id.
- **Integration (feature, DB + mocked curl):**
  - Auth accept: valid enabled key + matching env segment → 200 envelope.
  - Auth reject: missing/empty Bearer → 401; malformed token → 401; disabled key
    (`is_enable=0`) → 401; `user_id`/`service_id` mismatch → 401.
  - Env mismatch: TESTING key hitting `/api/cnr/live` (and vice-versa) → 401 `Invalid endpoint`.
  - CNR missing `command` → 400; JPRS missing `Command` → 400.
  - CNR upstream success (`code=200`) → `status:success`; upstream error (`code=5xx`/non-2) →
    `status:error` with `message`; upstream exception → `code:500` mapped, no leak.
  - JPRS upstream `2xx` line → success; non-2xx → error; missing config → 400.
  - JPRS accepts params via **both** GET query and POST body (same result for an equivalent call).
  - Logging on: row inserted in `api_history` with correct `service_name/service_id/status/domain`
    and sensitive fields stripped; logging off: no row inserted.
  - `command=History` returns paginated `fetchHistory` envelope scoped to the caller's service.
  - `last_access` updated on a successful authenticated call.
- **Browser / E2E:** none (no UI in this card).
- **Manual smoke (post-merge):**
  - `php spark migrate` then insert a key row + generate a token; `curl -H "Authorization: Bearer
    <token>" "https://.../api/cnr/testing?command=StatusAccount"` returns the envelope; confirm an
    `api_history` row (with `enable_log=1`) and no creds in the stored params.

## 7. Decisions & risks

**Resolved decisions (2026-07-12):**
- **API keys — start fresh.** Keep salt `hashid_salt = proxy@2026`; greenfield `users_api_access_keys`.
  Keys are minted new; **no legacy key import** and no reuse of the legacy `hash@2025` salt. (If a
  legacy import is ever needed later, it becomes a separate card.)
- **JPRS methods — accept GET and POST.** Merge query + body params. CNR stays GET (legacy contract).

**Verified (no longer risks):**
- `mbstring` is **enabled** (checked `php -m`) — ISO-2022-JP conversion is safe.
- `hashid_salt` **is set** in `.env` (`proxy@2026`).
- All JPRS certs are **PEM** (`.crt`/`.pem`) — no P12 in `.env`, so the `curlrequest` transport
  covers every configured service. (Keep the `ponytail:` note for a raw-curl P12 fallback only if a
  P12 service is ever added.)

**Remaining risk:**
- Greenfield tokens decode consistently because they are both minted and validated with the same
  composer `hashids/hashids` + `proxy@2026` salt + min length 32; nothing external to reconcile.

## 8. Rollout
- Migration order: `CreateUsersApiAccessKeys` then `CreateApiHistory` (independent; either order
  works — no FK). Run `php spark migrate`.
- Dependency: `composer require hashids/hashids` before deploy.
- Env vars: `hashid_salt` (required), `cnr.{id}.name|password|url|service_api_id`,
  `jprs.{id}.AgentLoginID|AgentLoginPassword|url|pem_cert_path|pem_key_path|pem_key_pass|ssl_verify`.
  Cert files under `writable/app_files/jprs.certs/`.
- No feature flag needed — new isolated routes, no existing behavior touched. Rollback = drop the
  two tables and remove the route group/alias.
