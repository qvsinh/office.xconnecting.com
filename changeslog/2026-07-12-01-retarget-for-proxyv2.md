# 2026-07-12 — Retarget .claude + CLAUDE.md for the proxyv2 rebuild

## Raw prompt

> let update CLAUDE, sub-agents suitable with new project, I copied from appv2.psi.vn, this is
> rebuild for proxy.services.vn, same pattern with appv2.psi.vn

> commit this

## Decisions

- Project is the rebuild of `proxy.services.vn`, a **registrar API proxy/gateway + developer
  console** (CNR/JPRS proxy via API access keys, console + API history, PSI Billing API) — not a
  domain-registrar/billing platform like psi.vn. Same tooling pattern as appv2.psi.vn.
- Dual-session model confirmed present in legacy proxy: `userAuthenticated` (identity/zone
  `global`/`cms`/`client`) vs `userWorking` (account scope).

## What the agent did

- Retargeted `CLAUDE.md`: title, intro, legacy path (`../proxy.services.vn`), feature summary,
  CodeGraph reference.
- Renamed all `.claude/agents/*` from appv2.psi.vn → proxyv2.services.vn; fixed reviewer session
  zones and domain examples (API keys/history/notifications), backend-coder/tester/README examples.
- Initialized git (no repo existed) and committed. `.env` / `settings.local.json` kept out via
  `.gitignore`.
