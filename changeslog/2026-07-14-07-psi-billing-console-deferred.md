# PSI Billing API console — deferred (out of current scope)

**Date:** 2026-07-14
**Decision:** Skip building a dedicated PSI Billing API console for now.

## Context

`CLAUDE.md` lists "the upstream PSI Billing API console" under the System pillar.
On audit against the legacy repo (`../proxy.services.vn`), the legacy
`Admin\ConsolePsiBillingApi` is **not** a live request composer — it is only a
logs viewer over the `api_history` table filtered to the PSI Billing service
(`logs` / `preview` / `delete` / `deleteAll` / `deleteByAge`).

proxyv2 defines only **CNR** and **JPRS** as services
(`app/Helpers/service_config_helper.php` derives the provider from service ids
matching `cnr` / `jprs`). There is **no PSI service and no PSI Billing proxy
integration** in proxyv2 — "PSI" in the legacy code is the company brand, not a
proxied API here.

## Rationale

- The generic **admin ApiHistory viewer** (`Admin\ApiHistory`) already provides
  per-service, keyword/status-filtered log viewing with preview + bulk delete —
  it subsumes the legacy PSI console's entire function generically.
- A dedicated PSI page would pre-filter `api_history` to a PSI `service_id` that
  does not exist yet, so it would render empty until a PSI Billing proxy is
  actually built and its calls are logged. That is speculative scaffolding
  (YAGNI).
- Building the full PSI Billing **proxy integration** (so there is data to view)
  is a large new feature that requires the upstream PSI API contract, base URL,
  and credentials — none of which are in scope for the CNR+JPRS core rebuild.

## Outcome

Deferred. When/if a PSI Billing proxy is added, its calls will appear in the
existing admin ApiHistory viewer automatically; a dedicated pre-filtered PSI
page can be added then in one small controller/view if desired.
