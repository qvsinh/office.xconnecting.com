# Plan: UI/UX overhaul — align app shell with TailAdmin design language

**Task:** 20260714
**Created:** 2026-07-14
**Branch suggestion:** feature/ui-ux-overhaul

## 1. Goal
Fix a batch of cross-cutting layout/design defects and bring the authenticated app shell in line
with the TailAdmin design language (https://demo.tailadmin.com/). This is one coherent pass over the
shared layout `app/Views/layouts/app.php` and shared components, plus a tiny view-only auth helper so
that an admin "acting as" a client sees the client zone. Authorization is untouched.

## 2. Scope
- **In scope (items 1–6, 8):**
  1. Filter/modal "Clear" controls become real `<button>` elements, not `<a>` links.
  2. Act-as zone separation: nav/zone rendering keys off the **working** user's zone (`session('userWorking')->zone`) via a new view-only helper; acting-as an admin-as-client shows the CLIENT nav. Banner + "Return to admin" path preserved.
  3. Sidebar re-grouped into TailAdmin-style sections with headers (MENU / API / ACCOUNT / ADMIN).
  4. Match TailAdmin look (colors, typography, sidebar, header, cards, buttons, form controls, tables) via Tailwind utilities.
  5. Fix modal/dropdown FOUC by adding the `[x-cloak]{display:none!important}` rule and `x-cloak` on every hidden-by-default element.
  6. Dark/light theme switcher (class strategy, persisted in `localStorage`, applied pre-paint, topbar toggle, `dark:` variants on shell + components).
  8. Proxy-branded favicon referenced from the layout `<head>` (and the standalone auth pages).
- **Out of scope:**
  - Item 7 (System-info page) — separate card.
  - Full npm/Tailwind build pipeline & pulling TailAdmin's compiled CSS — deferred backlog item (see Risks §7); this card stays on CDN Tailwind + inline `tailwind.config`.
  - Any change to `is_admin()`/`is_client()`/`adminFilter` or authorization behaviour.
  - Sub-user delegation, dashboard widgets, new pages.

## 3. Affected areas
| Layer | Files / Symbols | Notes |
|-------|-----------------|-------|
| Backend (helper) | `app/Helpers/auth_helper.php` — add `current_zone()`, `is_working_admin()` | VIEW-ONLY zone read; does not touch `is_admin()` |
| Frontend (Views) | `app/Views/layouts/app.php` (primary) | Sidebar grouping, x-cloak CSS, dark mode, favicon, theme toggle, zone helper wiring |
| Frontend (Views) | Filter-bar views w/ `<a>...Clear</a>`: `app/Views/admin/users/index.php`, `app/Views/client/api_history/index.php`, `app/Views/admin/system/migrations.php`, `app/Views/admin/api_history/index.php`, `app/Views/admin/api_access_keys/index.php` | Item 1 |
| Frontend (Views) | Standalone auth pages: `app/Views/auth/login.php`, `app/Views/auth/verify_2fa.php`, `app/Views/auth/basic_auth.php` | Own `<html>` head — need x-cloak CSS, favicon, dark-mode pre-paint script, brand config |
| Frontend (Views) | Child views with hidden-by-default Alpine elements | Audit for missing `x-cloak` (item 5) + add `dark:` variants where visible in shell |
| Assets | `public/favicon.ico` (exists) + optional `public/favicon.svg` | Item 8 — add branded SVG mark, keep .ico fallback |
| Config / Routes | none | No route/config change |
| Tests | `tests/` — feature tests for nav zone, x-cloak, forms, favicon | See §6 |

## 4. Impact analysis
- **`app/Views/layouts/app.php` — HIGH blast radius (shared shell).** Every authenticated page extends
  this layout via `$this->extend('layouts/app')`. All sidebar/topbar/dark-mode/x-cloak edits land here.
  Because most items (1 partially, 2, 3, 4, 5, 6, 8) edit this one file, splitting across agents would
  conflict — hence a **single frontend pass**. Regression surface = the whole authenticated area;
  smoke every page group after the pass.
- **`app/Helpers/auth_helper.php` — LOW/controlled blast radius.** Adding two new functions
  (`current_zone()`, `is_working_admin()`); existing `is_authenticated()/is_admin()/is_client()` are
  **not modified** (they stay anchored to `userAuthenticated` — security). Current callers of
  `is_admin()` (layout line 16 default, `Admin/*` controllers indirectly via adminFilter) are
  unaffected. Only the layout's `$navZone` default switches from `is_admin()` to the new
  `current_zone()`.
  - Callers to re-verify: `app/Views/layouts/app.php:16,70,149` (the only place `navZone` is derived —
    no controller passes `navZone`, confirmed via grep). After the change, admin-as-client on
    `/dashboard` renders client nav; a real admin on `/admin/*` still renders admin nav (working
    zone == auth zone when not acting).
- **Shared Alpine root `appShell()`** stays intact; the notification bell and user-menu nested
  components must not be merged into it (existing invariant — see layout comments). Dark-mode toggle
  can live either as a tiny nested `x-data` (preferred, isolated) or a small addition to `appShell()`;
  prefer a nested/isolated component to avoid regressing the shared toast/confirm root.

## 5. Implementation steps

### Phase 1 — Backend (view-only helper) — do first, small
1. [ ] Add to `app/Helpers/auth_helper.php` — owner: `backend-coder`
   - `current_zone(): string` — returns `session('userWorking')->zone ?? 'client'` (falls back safely when unauthenticated). VIEW-ONLY.
   - `is_working_admin(): bool` — `is_authenticated() && current_zone() === 'global'`.
   - Guard both with `function_exists()` like the existing helpers; add doc comment stating these are
     for the VIEW LAYER only and MUST NOT be used for authorization (that stays on `is_admin()` /
     `userAuthenticated`).
   - Acceptance: unit test — with `userAuthenticated` global + `userWorking` client (acting-as),
     `current_zone()==='client'` and `is_working_admin()===false`, while `is_admin()===true` (unchanged).

### Phase 2 — Frontend, single coherent pass on the shared layout — owner: `frontend-coder`
> Load a UI/UX skill first (`minimalist-ui` / `design-taste-frontend` + the TailAdmin reference) before
> the visual pass. Keep Tailwind + Alpine + `fetch()` only; `esc()` all dynamic output; keep the shared
> toast/confirm/alert + acting-as banner working.

1. [ ] **x-cloak FOUC fix (item 5)** — in `layouts/app.php` `<head>`, add a `<style>[x-cloak]{display:none!important}</style>` rule. Audit every hidden-by-default Alpine element in the layout (sidebar `x-show`, notification dropdown, user menu, confirm modal, alerts/toasts) and any child-view modal/dropdown for a missing `x-cloak`; add where absent.
   - Acceptance: on a hard reload, no modal/dropdown/sidebar flash; `[x-cloak]` rule present; every `x-show`-hidden-by-default element carries `x-cloak`.
2. [ ] **Dark-mode plumbing (item 6)** — after the CDN Tailwind `<script>`, add `<script>tailwind.config={darkMode:'class',theme:{extend:{colors:{ /* brand palette */ }}}}</script>`. Add an **inline pre-paint** script at the very top of `<head>` that reads `localStorage.theme` and toggles `document.documentElement.classList` `dark` before first paint (no flash). Add a topbar toggle control (isolated `x-data`, sun/moon SVG) that flips `<html>.dark` and persists to `localStorage`.
   - Acceptance: toggling switches theme instantly; choice survives reload with no flash; `darkMode:'class'` config active.
3. [ ] **Zone-aware nav (item 2)** — change layout default from `$navZone ??= is_admin() ? 'admin' : 'client';` to derive from the new helper: `$navZone ??= is_working_admin() ? 'admin' : 'client';` (use `current_zone()`). Update the topbar console label (line ~149) accordingly. Keep the acting-as banner + "Return to admin" form exactly as-is.
   - Acceptance: admin acting-as a client on `/dashboard` sees the **client** sidebar + "Client console" label AND the acting-as banner; a real admin on `/admin/*` sees admin nav; a real client sees client nav.
4. [ ] **Sidebar regrouping (item 3)** — restructure both nav branches into TailAdmin-style groups with `uppercase` section headers and spacing: MENU (Dashboard) · API (Access Keys / History / CNR Console / JPRS Console / API Docs if a route exists, else omit) · ACCOUNT (Profile / Security / Notifications) · ADMIN (admin zone only: Users / System → Info/Migrations/Logs). Preserve all existing hrefs (admin uses `/admin/*`, client uses `/`, `/my-api-access-keys`, `/api-history`, `/console/*`). Mark the active item.
   - Acceptance: both zones render grouped sections with headers matching TailAdmin structure; every existing link still resolves; ADMIN group only in admin zone.
5. [ ] **TailAdmin look (item 4)** — align the shell (sidebar, topbar, cards, buttons, form controls, tables) with TailAdmin's palette/typography/spacing via Tailwind utilities; add `dark:` variants to shell chrome and shared components (toast/confirm/alert/dropdowns). Replace ad-hoc hex classes (`text-[#111111]`, `border-[#EAEAEA]`, etc.) with the brand palette tokens defined in the `tailwind.config` where practical.
   - Acceptance: shell visually matches TailAdmin language in both light and dark; shared components legible in dark mode.
6. [ ] **Favicon (item 8)** — reference the favicon in the layout `<head>` (`<link rel="icon" ...>`); add a tasteful proxy-branded `public/favicon.svg` and keep `favicon.ico` as fallback.
   - Acceptance: browser tab shows the branded icon; `/favicon.ico` and `/favicon.svg` both served (200).

### Phase 3 — Frontend, item 1 + standalone auth pages — owner: `frontend-coder` (same pass, after layout)
1. [ ] **Clear buttons (item 1)** — in the 5 filter-bar views, replace `<a href="?limit=...">Clear</a>` with a proper `<button type="button">` that resets the filter (Alpine `@click="window.location='?limit=...'"` or a `type="reset"` within the GET form — pick per-view within hard rules; no jQuery). Audit create/edit modals for any link-styled clear/cancel and convert to `<button>`.
   - Acceptance: no `<a>` acting as a Clear/reset control remains; each Clear is `<button type="button">`/`type="reset"` and still clears the filter.
2. [ ] **Standalone auth pages** — apply the x-cloak CSS rule, favicon link, and dark-mode pre-paint script + brand `tailwind.config` to `auth/login.php`, `auth/verify_2fa.php`, `auth/basic_auth.php` (they have their own `<html>` head, do not extend the layout).
   - Acceptance: auth pages have no x-cloak flash, show the favicon, and honour the persisted theme.

### Phase 4 — Tests — owner: `tester`
1. [ ] Feature/unit tests per §6; keep all existing tests green.

## 6. Test plan
- **Unit:**
  - `current_zone()` / `is_working_admin()` return client-zone when acting-as (working=client, auth=global) and admin-zone when not; `is_admin()` stays anchored to `userAuthenticated` (unchanged).
- **Integration (feature/HTTP):**
  - Admin acting-as a client → GET `/dashboard` response contains the **client** nav links (`/my-api-access-keys`, `/console/cnr`) and the acting-as banner, and does **not** contain admin-only links (`/admin/users`).
  - Real admin → GET `/admin/dashboard` contains admin nav (`/admin/users`, `/admin/system`).
  - Every authenticated page response includes the `[x-cloak]{display:none` rule.
  - Rendered filter-bar markup contains `<button` for Clear and no `>Clear</a>`.
  - `GET /favicon.ico` and `/favicon.svg` return 200.
- **Browser / E2E (manual or e2e-runner):**
  - Hard reload each page group (dashboard, keys, history, consoles, notifications, account, admin users/system, login) — no modal/dropdown/sidebar flash.
  - Theme toggle flips light/dark, persists across reload, no flash-of-wrong-theme.
  - Sidebar groups render with headers matching TailAdmin; active item highlighted.
- **Manual smoke:** log in as admin → act as a client → confirm client nav + banner → Return to admin → confirm admin nav; toggle dark mode across a few pages; submit and Clear a filter.

## 7. Risks & open questions
- **CDN vs build (decision):** Stay on **CDN Tailwind + inline `tailwind.config`** for this card
  (adds `darkMode:'class'` + brand palette with zero build tooling). Adopting TailAdmin's compiled CSS
  / full npm pipeline is the deferred "Tailwind build pipeline" backlog item — pulling it in here would
  balloon scope. Note: the CDN `tailwind.config` object must be set in a `<script>` that runs after the
  CDN loader; verify dark variants actually compile at runtime.
- **Dark-mode flash:** Must apply `dark` class in an inline pre-paint script (first thing in `<head>`),
  before the CDN script paints — otherwise a light→dark flash. Applies to layout AND the 3 standalone
  auth pages.
- **Shared Alpine root:** Keep the theme toggle and any new interactivity in isolated `x-data`
  components; do not merge into `appShell()` (existing invariant protecting the toast/confirm/alert root).
- **Item 1 approach per view:** filter Clear is a GET-form reset; `type="reset"` won't clear the URL
  query, so an Alpine `@click` navigation (or a plain link styled as needed) may be simplest — frontend-coder
  picks the smallest correct option per view while satisfying "must be a `<button>`".
- **Open question:** Is there an "API Docs" route to link in the sidebar API group? If none exists yet,
  omit it (do not scaffold a route in this card).

## 8. Rollout
- No migrations, no env vars, no feature flag. Pure view + one helper.
- Deploy note: assets remain CDN-served; verify CSP (`app/Config/ContentSecurityPolicy.php`) still
  allows `cdn.tailwindcss.com` / `unpkg.com` and the inline config/theme scripts (nonce or inline
  allowance) — if CSP blocks inline scripts, the dark-mode pre-paint + `tailwind.config` scripts need a
  nonce. Confirm before merge.
- Order: Phase 1 helper → Phase 2 single layout pass → Phase 3 filter/auth views → Phase 4 tests.
