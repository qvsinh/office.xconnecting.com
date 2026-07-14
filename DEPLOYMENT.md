# Deployment / Go-Live Checklist — proxyv2.services.vn

This rebuild is a **faithful port** of the legacy `proxy.services.vn` API contract.
Existing API clients keep working **unchanged** as long as the items below hold.
Verified by direct comparison of the legacy and rebuilt auth/proxy code.

## API compatibility (verified)

The public proxy surface is byte-for-byte compatible with the legacy system:

| Aspect | Contract (unchanged) |
|---|---|
| Auth header | `Authorization: Bearer <access_key>` |
| Key decode | `get_hashids(32)->decode()` → `[user_id, env, service_id]` |
| Key lookup | `users_api_access_keys.access_key` + cross-check `is_enable`, `user_id`, `service_id` |
| Endpoint URL | `/api/cnr/{live|testing}/...`, `/api/jprs/{live|testing}/...` |
| Response envelope | `{status, api_service_id:"cnr.<id>", data, message}`, success = upstream `code` starts with `2` |
| Reject | HTTP 401 `{status:"error", message:"..."}` |

Non-breaking differences: JPRS also accepts **POST** (legacy was GET-only — a superset);
reject message is `Unauthorized` (legacy used `Unauthorized[n]`) — status code is identical.

## MUST DO before / at cutover

1. **`hashid_salt` — set to the LIVE system's exact value.**
   API keys are *decoded*, not just string-matched. If the salt differs, **every existing
   key fails to decode → all clients get 401.** Copy `hashid_salt` from the live `.env`
   verbatim into the proxyv2 live `.env`.

2. **Point at the live database** (or migrate the data). The rebuilt app reads the same
   tables/columns: `users`, `users_api_access_keys` (`access_key, is_enable, enable_log,
   service_id, user_status, last_access`), `api_history`. Existing users + keys work as-is.

3. **Run `php spark migrate` on live — safe.** Every migration touching a pre-existing
   table is guarded by `tableExists()` and no-ops. Only genuinely new tables are created:
   `scheduled_tasks` (+ 3 disabled default purge jobs). No existing data is altered.

4. **`.env` production settings:**
   - `app.baseURL = 'http://proxyv2.services.vn/'` (no `index.php`, no localhost redirect)
   - `CI_ENVIRONMENT = production`
   - Registrar upstream credentials (CNR/JPRS) — same values as live.
   - `encryption.key` set.

5. **Password hashing unchanged** — `Config\Auth` (md5 + salt) matches legacy, so existing
   user logins keep working. No dev backdoor (removed).

## Scheduled tasks (new)

`scheduled_tasks` are seeded **disabled**. To run purge jobs, enable them in
`/admin/scheduled-tasks` and add one OS cron entry:

```cron
* * * * * cd /path/to/app && php spark schedule:run >> writable/logs/schedule.log 2>&1
```

Handlers are a fixed in-code allowlist (`emails.purge`, `notifications.purge`,
`api_history.purge`) — no DB-driven code execution.

## Out of scope (intentionally not built)

- **CMS / CRM** — dropped per project scope.
- **PSI Billing API console** — legacy was only an `api_history` logs viewer; the generic
  admin ApiHistory viewer already covers it. No PSI proxy integration exists. See
  `changeslog/2026-07-14-07-psi-billing-console-deferred.md`.
- **Email send pipeline** — templates + sent-log are managed; SMTP dispatch is a later card.
- **servers module** — removed (vestigial inventory CRUD, never wired into the proxy).

## Post-deploy smoke test

1. `curl -H "Authorization: Bearer <existing-live-key>" "http://proxyv2.services.vn/api/cnr/live/?command=StatusAccount"`
   → expect the same `{status, api_service_id, data}` envelope as the old system.
2. Log in to `/admin` with an existing admin account.
3. Confirm `/admin/api-history` shows proxied calls.
