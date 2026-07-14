# Plan: Developer Consoles (CNR + JPRS, admin + user)

**Task:** 20260713 (developer-consoles)
**Created:** 2026-07-13
**Branch suggestion:** `feature/developer-consoles`

## 1. Goal
Deliver the "developer console" pillar: a web UI (admin + user) to compose and run CNR/JPRS
registrar API calls against an issued API access key and view the normalized response — **without
duplicating the proxy's upstream request logic**. The card's core win is a refactor: extract the
upstream call/parse/log logic out of `Api\CnrApi` / `Api\JprsApi` into shared client services that
**both** the existing proxy controllers and the new console controllers call.

## 2. Scope
- **In scope:**
  - Refactor: extract `sendRequest` + parse + history-log logic from `Api\CnrApi` / `Api\JprsApi`
    into shared `App\Libraries\CnrClient` / `App\Libraries\JprsClient` (registered as
    `Services::cnrClient()` / `Services::jprsClient()`). Proxy controllers become thin wrappers and
    **all existing proxy tests stay green**.
  - Consolidate the history-write logic (`enable_log` gate, `SKIP_LOG_LISTING_COMMANDS`, credential
    stripping) into one root — `ApiHistoryModel::record()` — called by both clients.
  - Web console controllers: `Admin\ConsoleCnr`, `Admin\ConsoleJprs`, `Client\ConsoleCnr`,
    `Client\ConsoleJprs`. Each: `index()` (render console view) + `run()` (POST JSON endpoint, CSRF).
  - Ownership-scoped key selection: user console lists/uses only the session user's **own enabled**
    keys for that provider; admin console may select any user's key. Upstream identity
    (`user_id` / `env` / `service_id`) is **always derived from the resolved key row**, never from
    raw request ids.
  - `command=History` on the console reuses the scoped history read (same envelope as the proxy).
  - Routes under `adminFilter` (admin) / `authFilter` (client); CSRF on the `run` POSTs.
  - Views (TailAdmin): key/service selector, command input, params key/value editor, JSON response
    viewer (pretty-printed via Alpine `x-text` — same XSS discipline as the history preview).
  - Sidebar activation (replace the "CNR / JPRS console" placeholder in `layouts/app.php`).
  - Tests: shared-client unit tests (mocked `curlrequest`), console run happy-path + validation,
    **IDOR (user console can only use own keys)**, admin can use any key, `command=History`,
    and a reuse/no-duplication guard.
- **Out of scope (later cards):**
  - PSI Billing API console (`ConsolePsiBillingApi`).
  - User management, notifications, 2FA, sub-users.
  - System modules (servers, scheduled tasks, translations, email templates, settings, logs).
  - Tailwind asset build pipeline.
  - Any change to the `api/*` proxy routes or `ApiFilter` (Bearer path stays untouched).

## 3. Affected areas
| Layer | Files / Symbols | Notes |
|-------|-----------------|-------|
| Backend (new) | `app/Libraries/CnrClient.php`, `app/Libraries/JprsClient.php` | Shared upstream call+parse+log; the dedup target |
| Backend (new) | `app/Controllers/Admin/ConsoleCnr.php`, `Admin/ConsoleJprs.php`, `Client/ConsoleCnr.php`, `Client/ConsoleJprs.php` | Web session controllers |
| Backend (edit) | `app/Controllers/Api/CnrApi.php`, `Api/JprsApi.php` | Delegate to the new clients; keep envelope identical |
| Backend (edit) | `app/Controllers/Api/ApiController.php` | `logApiCall` logic moves to `ApiHistoryModel::record`; keep/thin `fetchHistory` |
| Backend (edit) | `app/Models/ApiHistoryModel.php` | Add `record()` (log gate/strip) + a scoped console-history read method |
| Backend (edit) | `app/Models/ApiAccessKeyModel.php` | Add provider-scoped list (`listForUserInServices`) + owned-key resolver for console |
| Backend (edit) | `app/Config/Services.php` | Register `cnrClient()` / `jprsClient()` |
| Frontend (Views) | `app/Views/admin/api_console/*`, `app/Views/client/api_console/*` (new) | Console UI (frontend-coder) |
| Frontend (Views) | `app/Views/layouts/app.php` | Replace console placeholder with real nav links |
| Config / Routes | `app/Config/Routes.php` | Console routes in admin + client groups |
| Tests | `tests/unit/CnrApiParseRrpResponseTest.php`, `tests/unit/JprsApiPayloadTest.php`, `tests/database/ApiControllerLogApiCallTest.php`, `tests/_support/Api/TestApiController.php` | Re-point to moved symbols |
| Tests (new) | `tests/database/CnrClientTest.php`, `JprsClientTest.php`, `tests/database/Console*Test.php` | Shared-client + console + IDOR |

## 4. Impact analysis
CodeGraph index for this repo is still sparse; blast radius verified by `grep` over `app/` + `tests/`.

**`Api\CnrApi::sendRequest` (private)** — sole caller `CnrApi::proxy()` (line 52). No external callers.
Moving to `CnrClient::call()` is contained.

**`Api\CnrApi::parseRrpResponse` (private)** — caller `sendRequest` (line 99) **and** referenced by
`tests/unit/CnrApiParseRrpResponseTest.php` via `ReflectionMethod(CnrApi::class, 'parseRrpResponse')`.
Moving it into `CnrClient` **breaks that reflection test** → test must be re-pointed to
`CnrClient::class` (make it a public method on the client so no reflection is needed — simpler).

**`Api\JprsApi::sendRequest` (private)** — sole caller `JprsApi::proxy()` (line 35).
**`Api\JprsApi::parseJprsResponse` / `buildJprsPlainPayload` (private)** — callers inside `sendRequest`
**and** `tests/unit/JprsApiPayloadTest.php` via reflection. Same re-point as CNR.

**`ApiController::logApiCall` (protected)** — callers: `CnrApi::sendRequest` (lines 101, 116),
`JprsApi::sendRequest` (lines 124, 141), and `tests/_support/Api/TestApiController.php` (line 34,
exercised by `tests/database/ApiControllerLogApiCallTest.php`). Moving the gate/strip logic into
`ApiHistoryModel::record()` means both clients call `record()`; the existing `logApiCall` test must be
re-pointed to `ApiHistoryModel::record()` (preferred — tests the logic at its new root), or
`logApiCall` kept as a thin delegator to preserve the test as-is. **Recommend re-point.**

**`ApiController::fetchHistory` (protected)** — callers: `CnrApi::proxy` (line 38), `JprsApi::proxy`
(line 32). Not called by tests directly. Leave `fetchHistory` on `ApiController` so the proxy path is
byte-for-byte unchanged; extract only its **read query** into `ApiHistoryModel` so the console can
reuse it and produce the same envelope. Low blast radius.

**Blast radius verdict:** contained. No public API surface changes. The only non-obvious breakage is
the three reflection/support tests above — they are updated in Phase 1/Phase 3, not left red.

## 5. Implementation steps

### Phase 1 — Backend: extract shared clients (the refactor)
1. [ ] Add `ApiHistoryModel::record(array $row, bool $enableLog): void` — owner: `backend-coder`
   - Move the exact gate/strip logic from `ApiController::logApiCall`: `enable_log` check,
     `SKIP_LOG_LISTING_COMMANDS` + `limit` skip, unset `AgentLoginID`/`OperationPassword`/
     `AgentLoginPassword`, `dnszone`→domain fallback, JSON-encode params/response, insert (try/catch
     + `log_message`, never throw into the caller).
   - Acceptance: `record()` reproduces current insert/skip/strip behavior; no raw SQL.
2. [ ] Create `App\Libraries\CnrClient` with public
   `call(int $serviceId, string $env, string $command, array $params, ?int $userId, bool $enableLog, mixed $metadata = null): array`
   and public `parseRrpResponse(string $body): array` — owner: `backend-coder`
   - Port `CnrApi::sendRequest` verbatim: `get_cnr_service_config`, missing-config 500 array,
     `s_login`/`s_pw`/`command` query, `service('curlrequest')->get`, parse, `ApiHistoryModel::record`
     with derived fields, returns the parsed array. Uses `service('service_config')` helper.
   - Acceptance: returned array identical shape to today's `sendRequest`; upstream creds never returned.
3. [ ] Create `App\Libraries\JprsClient` with public
   `call(...)` (same signature, returns the `{code,description,data,raw_response}` struct or `false`
   on missing config) plus public `parseJprsResponse` / `buildJprsPlainPayload` — owner: `backend-coder`
   - Port `JprsApi::sendRequest` verbatim incl. PEM merge (`buildCombinedPem`), ISO-2022-JP convert,
     mTLS `cert`/`verify`, temp-file `finally` cleanup, and `record()` logging.
   - Acceptance: JPRS payload/parse/mTLS behavior unchanged; temp PEM still 0600 + removed.
4. [ ] Register `Services::cnrClient()` / `Services::jprsClient()` (shared) — owner: `backend-coder`
   - Acceptance: `service('cnrClient')` / `service('jprsClient')` resolve.
5. [ ] Rewrite `Api\CnrApi::proxy` / `Api\JprsApi::proxy` to delegate to the clients — owner: `backend-coder`
   - Keep command-missing/domain-restriction/History branches and the exact success/error envelope
     (`{status, api_service_id, data|message}`, RRP 2xx=success). Pass `env`/`service_id`/`user_id`/
     `enable_log` from the controller's resolved auth context into `call()`.
   - Delete the now-unused `sendRequest`/parse/`logApiCall` bodies from the API controllers.
   - Acceptance: proxy responses byte-identical to before; existing proxy tests pass unchanged.
6. [ ] Extract `fetchHistory`'s read query into a scoped `ApiHistoryModel` method
   (e.g. `searchScoped(serviceName, serviceId, userId, filters, sort, limit, offset)`), used by both
   `ApiController::fetchHistory` and the console — owner: `backend-coder`
   - Acceptance: proxy `command=History` envelope unchanged; method returns rows only (no envelope).
7. [ ] `ApiAccessKeyModel`: add `listForUserInServices(int $userId, array $serviceIds): list<object>`
   (enabled + owned keys whose `service_id` ∈ provider ids) and reuse `findKey()`/`searchAdmin()` —
   owner: `backend-coder`
   - Provider→service-id map comes from `list_api_services()` (helper), filtered by provider.
   - Acceptance: returns only `is_enable=1` keys owned by the user for the given service ids.

### Phase 2 — Backend: console controllers
1. [ ] `Client\ConsoleCnr` + `Client\ConsoleJprs` (extend `BaseController`) — owner: `backend-coder`
   - `index()`: list the session user's own enabled keys for the provider via
     `listForUserInServices((int) session('userWorking')->id, cnrServiceIds())`; render the view.
   - `run()` (POST, CSRF): validate `key_id` + `command` (+ params array) with CI4 `Validation`;
     resolve key via `findKey((int) $key_id)`; **reject unless `key->user_id === session('userWorking')->id`
     and `key->is_enable === 1`** (IDOR guard); derive `service_id`/`env`(from `user_status`)/`user_id`
     from the key row; on `command=History` return scoped history envelope, else call
     `service('cnrClient'|'jprsClient')->call(...)` and wrap in the standard envelope; `setJSON`.
   - Acceptance: a user cannot run against another user's key (404/403); no key string reaches JS.
2. [ ] `Admin\ConsoleCnr` + `Admin\ConsoleJprs` (extend admin base / `BaseController` under adminFilter)
   — owner: `backend-coder`
   - `index()`: render console; key selection via `searchAdmin` (any user's keys, provider-filtered).
   - `run()` (POST, CSRF): resolve key via `findKey`, **no ownership restriction** but still derive
     identity from the key row (never trust request ids); same envelope + History behavior.
   - Acceptance: admin can select and run any user's key; identity always taken from the key row.
3. [ ] Routes — owner: `backend-coder`
   - Admin group: `GET admin/console/cnr`, `POST admin/console/cnr/run` (csrf); same for `jprs`.
   - Client group: `GET console/cnr`, `POST console/cnr/run` (csrf); same for `jprs`.
   - Acceptance: `php spark routes` lists all 8; run POSTs carry the `csrf` filter; `api/*` untouched.

### Phase 3 — Frontend (Views)
1. [ ] Console views `app/Views/{admin,client}/api_console/cnr/index.php` + `.../jprs/index.php`
   (extend `layouts/app`) — owner: `frontend-coder`
   - UI notes: TailAdmin card layout. Alpine `x-data` console: key/service `<select>` (options from
     controller data, key shown as description + provider + **masked** key, value = `key_id`),
     command text input, dynamic key/value params editor (add/remove rows), Run button. AJAX via
     `fetch()` with the CI4 CSRF token; render the JSON envelope pretty-printed in a `<pre>` via
     `x-text` (never `x-html`). Errors/success via the shared toast component. `esc()` every dynamic
     server value in the initial render. Admin view adds a user/key search field.
   - Acceptance: run round-trips a call, shows response; no key string in the DOM/network beyond
     `key_id`; no jQuery/Bootstrap; all output escaped.
2. [ ] Sidebar: replace the `layouts/app.php` "CNR / JPRS console" placeholder (line ~68) with real
   links for both zones (admin → `/admin/console/cnr`, `/admin/console/jprs`; client → `/console/cnr`,
   `/console/jprs`) — owner: `frontend-coder`
   - Acceptance: nav links render per `$navZone`; active state consistent with existing items.

### Phase 4 — Tests
1. [ ] Re-point moved-symbol tests — owner: `tester`
   - `CnrApiParseRrpResponseTest` → `CnrClient::parseRrpResponse` (public, drop reflection);
     `JprsApiPayloadTest` → `JprsClient`; `ApiControllerLogApiCallTest` + `TestApiController` →
     `ApiHistoryModel::record`.
2. [ ] `CnrClientTest` / `JprsClientTest` (DB + mocked `curlrequest`) — owner: `tester`
   - Happy path builds correct upstream query/payload, parses, and writes history (respecting
     `enable_log` + skip-listing + credential strip). Missing-config path returns the 500/`false`
     shape without an HTTP call.
3. [ ] Console feature tests (admin + client) — owner: `tester`
   - Run happy-path (mock client) returns the envelope; validation rejects missing `key_id`/`command`;
     **IDOR: client `run()` with another user's `key_id` is rejected**; admin `run()` accepts any
     user's key; `command=History` returns scoped rows; CSRF enforced on POST.
4. [ ] Reuse/no-duplication guard — owner: `tester`
   - Assert `Api\CnrApi`/`Api\JprsApi` no longer define `sendRequest` and the console controllers
     contain no `curlrequest` call (they must route through the shared client). Keep it a simple
     source/reflection assertion.

## 6. Test plan
- **Unit:** RRP parse, JPRS payload build + parse (now on the client classes); provider→service-id
  filtering for key listing.
- **Integration (DB):** `CnrClient::call` / `JprsClient::call` with mocked `curlrequest` (success +
  error + missing-config); `ApiHistoryModel::record` gate/strip; console `run()` endpoints for both
  zones and both providers; `command=History` scoped read.
- **Security / IDOR:** client console rejects a `key_id` it does not own and a disabled key; admin
  console accepts any key but derives identity from the key row; CSRF required on `run`.
- **Regression:** full existing proxy suite (`api/cnr`, `api/jprs`, `logApiCall`, `fetchHistory`,
  parse tests) stays green after the refactor. Target: ≥80% coverage on new code; keep ~188 green.
- **Browser / E2E:** log in as a client → open `/console/cnr` → pick own key → run a `StatusDomain`
  (or `History`) → see pretty JSON; repeat as admin on any user's key.
- **Manual smoke:** `composer cs` + `composer analyse` (phpstan lvl 5) clean; `php spark routes`
  shows the 8 console routes; upstream creds never appear in any response or the DOM.

## 7. Risks & open questions
- **Refactor regression (primary risk):** moving `sendRequest`/parse/log could drift proxy behavior.
  Mitigation — port verbatim, keep envelopes identical, re-point the 3 affected tests in the same PR,
  run the whole suite before review (ponytail: dedup is the win, but the proxy contract is frozen).
- **Decision — server-side shared-service (RECOMMENDED) vs POST to `/api/*` with Bearer:** use the
  server-side path. The console controller runs under the web session (authFilter/adminFilter + CSRF)
  and calls `service('cnrClient')->call(...)` directly; the browser only ever sends `key_id`. This
  keeps the access-key string and upstream registrar creds entirely server-side (the Bearer path would
  leak the key to JS and force a stateless CSRF-less call). Decided: server-side.
- **Decision — identity source:** always derive `user_id`/`env`/`service_id` from the resolved key
  row, never from request ids, in both zones (the admin zone's only difference is skipping the
  ownership equality check).
- **Where does history-log logic live?** Recommend `ApiHistoryModel::record()` as the single root
  (both clients call it); `ApiController::logApiCall` is removed and its test re-pointed. Confirm this
  vs keeping a thin `logApiCall` delegator.
- **JPRS mTLS in tests:** `call()` still touches the filesystem for PEM merge; JPRS client tests must
  mock `curlrequest` and either stub cert paths or assert on the pre-transport payload to avoid needing
  real certs.
- **Open question:** should the console expose an env (live/testing) toggle, or is env fixed by the
  selected key's `user_status`? Plan assumes **fixed by the key row** (matches the proxy's per-key env).

## 8. Rollout
- No new migrations required (reuses `users_api_access_keys` + `api_history`). The unrelated staged
  `AddUserCreatedIndexToApiHistory` migration is independent of this card.
- No new env vars (registrar creds already read via `service_config` helper from `.env`).
- Deploy order: merge backend refactor + clients + controllers + routes first (suite green), then
  views + sidebar. No feature flag needed — routes are additive and gated by existing filters.
- Post-deploy: `php spark routes` sanity check; smoke a testing-env CNR `StatusDomain` and a JPRS
  call from each zone; confirm `api/*` proxy still authenticates and logs.
