# Plan: Notifications — per-user bell/dropdown + list + mark-read

**Task:** 20260713-notifications
**Created:** 2026-07-13
**Branch suggestion:** `feature/notifications`

## 1. Goal
Give every authenticated user (admin + client) a topbar notification bell with an unread badge,
a dropdown of recent notifications, a full "all notifications" page, and mark-one / mark-all-read
actions — all strictly scoped to the session user. This card **reads and marks** existing rows only;
row creation belongs to the scheduled-tasks card.

## 2. Scope
- **In scope:**
  - `NotificationModel` mapping the existing `notifications` table (object returnType, timestamps).
  - Create migration for `notifications` (idempotent — table already exists in `db_proxy_dev`, but the
    test sandbox `db_proxy_test` needs it) plus the two read indexes.
  - `Notifications` controller under `App\Controllers` (shared by both zones, `authFilter` group):
    full list page + JSON feed + unread-count + mark-read (POST) + mark-all-read (POST).
  - Topbar bell in the shared `layouts/app.php` (unread badge + Alpine dropdown), and a full
    `/notifications` list page view.
  - Tests: owner-scoped list/count/unread, IDOR on mark-read, mark-all-read scoping, guest gating,
    XSS on message rendering.
- **Out of scope (later cards):**
  - Notification **creation** hooks (scheduled tasks generate rows) — scheduled-tasks card. This card
    ships only a tiny test seeder/factory to populate rows for its own tests.
  - Admin broadcast / send-to-user.
  - Email / push / real-time (websocket/SSE) delivery.
  - 2FA, sub-users, other system modules.

## 3. Affected areas
| Layer | Files / Symbols | Notes |
|-------|-----------------|-------|
| Backend | `app/Models/NotificationModel.php` (new) | maps `notifications`; owner-scoped read/mark methods |
| Backend | `app/Controllers/Notifications.php` (new) | shared controller (not Admin/ nor Client/), all reads/mutations scoped to `session('userWorking')->id` |
| Frontend (Views) | `app/Views/layouts/app.php` (edit — topbar bell) | additive: new bell element + nested `notificationBell()` Alpine component |
| Frontend (Views) | `app/Views/notifications/index.php` (new) | full list page extending `layouts/app` |
| Database | `app/Database/Migrations/2026-07-13-140000_CreateNotifications.php` (new) | idempotent create + indexes `(user_id, created_at)`, `(user_id, is_read)` |
| Config / Routes | `app/Config/Routes.php` (edit) | add 5 routes to the `authFilter` group (reachable from both admin + client pages) |
| Tests | `tests/database/NotificationModelTest.php` (new) | model unit/DB tests |
| Tests | `tests/feature/Web/NotificationsTest.php` (new) | controller feature tests (IDOR, gating, JSON envelopes) |
| Tests | `tests/feature/Web/NotificationsViewTest.php` (new) | view-render + XSS on message text |

## 4. Impact analysis
Mostly new symbols (empty CodeGraph blast radius). Two existing files are edited:

- **`app/Views/layouts/app.php`** — rendered by **every authenticated page** (dashboard, api-access-keys,
  api-history, consoles, admin + client). Blast radius = all views extending `layouts/app`. The change is
  **additive** (a new bell in the topbar). **Decision to shrink blast radius:** put the bell's state in a
  **nested `x-data="notificationBell()"`** on the bell element, do **not** add fields to the shared
  `appShell()` root — so the existing shared toast/confirm/alert component is untouched and cannot regress.
- **`app/Config/Routes.php`** — additive only; new routes appended to the existing `authFilter` group
  (lines 45–64). No existing route changes.

No existing controller/model symbols are modified or renamed, so no `codegraph_callers` rewrite is needed.

## 5. Implementation steps

### Phase 1 — Backend

1. [ ] Create migration `2026-07-13-140000_CreateNotifications.php` — owner: `backend-coder`
   - Recreate the legacy schema: `id` INT unsigned PK AI, `user_id` INT unsigned null, `task_id` INT
     unsigned null, `message` TEXT not null, `is_read` TINYINT(1) default 0, `created_at`/`updated_at`
     DATETIME null.
   - Add keys `idx_notifications_user_created (user_id, created_at)` (covers the list's
     `WHERE user_id=? ORDER BY created_at DESC`) and `idx_notifications_user_read (user_id, is_read)`
     (covers the unread-count `WHERE user_id=? AND is_read=0`). See `mysql-patterns` (composite indexes).
   - **Idempotent:** call `createTable('notifications', true)` (ifNotExists) so it is a no-op on the dev DB
     that already has the table; `down()` drops the table.
   - Acceptance: `php spark migrate` succeeds on a fresh `db_proxy_test`; re-running against a DB that
     already has `notifications` does not error.

2. [ ] Create `app/Models/NotificationModel.php` — owner: `backend-coder`
   - `$table = 'notifications'`, `$returnType = 'object'`, `$useSoftDeletes = false`,
     `$useTimestamps = true` (createdField `created_at`, updatedField `updated_at`),
     `$allowedFields = ['user_id','task_id','message','is_read']`.
   - Methods (all owner-scoped, parameterized Query Builder — never raw SQL):
     - `listForUser(int $userId, int $limit, int $offset): array` — `where('user_id')`,
       `orderBy('created_at','DESC')`, secondary `orderBy('id','DESC')` for stable pagination,
       `findAll($limit, $offset)`.
     - `countForUser(int $userId): int` — total for the user (pagination).
     - `unreadCountForUser(int $userId): int` — `where user_id AND is_read=0`.
     - `markRead(int $id, int $userId): bool` — **fetch by `id` AND `user_id` first**; return false if
       missing/foreign (no IDOR, no existence leak); else set `is_read=1` and return true.
     - `markAllRead(int $userId): int` — set `is_read=1` for the user's unread rows; return affected count.
   - Acceptance: unit-tested per §6; `markRead` on another user's id returns false and mutates nothing.

3. [ ] Create `app/Controllers/Notifications.php` (extends `BaseController`) — owner: `backend-coder`
   - Resolve owner as `$me = session('userWorking'); $userId = (int) $me->id;` on every action — **never**
     accept `user_id` from request input.
   - Actions:
     - `index()` — full page: read `page` (min 1), fixed `perPage` (e.g. 15), render
       `notifications/index` with `items = listForUser`, `total = countForUser`, `page`, `perPage`,
       `unreadCount = unreadCountForUser`.
     - `unreadCount()` — GET JSON `jsonSuccess('OK', ['unread_count' => ...])` (badge, called once on page load).
     - `feed()` — GET JSON: recent N (limit ~10) via `listForUser($userId, 10, 0)` +
       `unread_count`; return `jsonSuccess('OK', ['items' => $items, 'unread_count' => ...])`.
       Return raw stored `message`/`created_at` — the view escapes via Alpine `x-text` (never `x-html`).
     - `markRead()` — POST: `$id = (int) getPost('id')`; `if ($id<=0 || !markRead($id,$userId))`
       → `jsonFailed('Notification not found.', 404)`; else `jsonSuccess(..., ['unread_count' => fresh count])`.
     - `markAllRead()` — POST: `$n = markAllRead($userId)`; `jsonSuccess("{$n} marked read.", ['unread_count' => 0])`.
   - Acceptance: all endpoints return the shared envelope with `csrf_hash`; mutations are POST + `csrf`;
     cross-user mark-read returns 404 and mutates nothing.

4. [ ] Add routes to `app/Config/Routes.php` (inside the existing `authFilter` group, lines 45–64) — owner: `backend-coder`
   - `$routes->get('notifications', 'Notifications::index');`
   - `$routes->get('notifications/feed', 'Notifications::feed');`
   - `$routes->get('notifications/unread-count', 'Notifications::unreadCount');`
   - `$routes->post('notifications/mark-read', 'Notifications::markRead', ['filter' => 'csrf']);`
   - `$routes->post('notifications/mark-all-read', 'Notifications::markAllRead', ['filter' => 'csrf']);`
   - Placed in the plain `authFilter` group (not the `admin` group) so both admin and client pages — which
     share `layouts/app` — can reach them; `userWorking` is set for both. Do **not** touch the `api/*` group.
   - Acceptance: `php spark routes` lists all five under `authFilter`; POSTs carry the `csrf` filter.

### Phase 2 — Frontend (Views)

1. [ ] Add the topbar bell to `app/Views/layouts/app.php` — owner: `frontend-coder`
   - Load the most specific UI/UX skill (`minimalist-ui` fits TailAdmin) before visual work.
   - Insert a bell button + dropdown **between** the zone label (line 93) and the user menu (line 96),
     wrapped in its own `x-data="notificationBell()"` (nested — do **not** add fields to `appShell()`).
   - Behaviour:
     - `x-init` (on load): one `fetch('/notifications/unread-count')` to populate the badge — **load once
       on page, no interval poll** (decision, see §7).
     - Bell button shows a red count badge when `unread > 0`; `@click` toggles the dropdown and, on open,
       fetches `/notifications/feed` to fill the recent list + refresh `unread`.
     - Each row: `x-text` the `message` and a formatted `created_at`; unread rows visually distinct; a row
       `@click` calls `postForm('/notifications/mark-read', { id })` and updates `unread` from the response.
     - Footer: "Mark all as read" → `postForm('/notifications/mark-all-read')` (sets `unread = 0`, marks
       list read in-place) and a "View all" link to `/notifications`.
   - UI notes: TailAdmin/Tailwind utility classes only (no Bootstrap/tabler); Alpine for state; AJAX via the
     existing `postForm()` / `fetch()` + `window.csrf`; toasts/confirms via the shared components; **`esc()`
     any server-rendered value and use Alpine `x-text` (never `x-html`) for feed content** — messages are
     stored text and must be treated as untrusted.
   - Acceptance: bell renders on every authenticated admin + client page; badge reflects unread count;
     dropdown opens and lists recent items; mark-one / mark-all update the badge without a full reload.

2. [ ] Create `app/Views/notifications/index.php` — owner: `frontend-coder`
   - Extend `layouts/app` via `$this->extend()` / `$this->section('content')`.
   - Server-rendered table/list of the user's notifications (`items`), each `esc()`-escaped; unread rows
     styled distinctly; per-row "Mark read" and a page-level "Mark all as read" using `postForm()` +
     toast; simple pagination (`page`/`perPage`/`total`).
   - UI notes: same Tailwind/Alpine/`fetch()` rules; `esc()` all output; empty-state message when none.
   - Acceptance: page lists the user's notifications with working mark-read/mark-all and pagination; no
     other user's rows appear.

3. [ ] Add a "Notifications" sidebar link (both zones) in `layouts/app.php` — owner: `frontend-coder`
   - Add `<a href="/notifications">Notifications</a>` in both the admin and client `<nav>` blocks,
     matching the existing link styling.
   - Acceptance: link present and highlighted on `/notifications`.

### Phase 3 — Tests

1. [ ] `tests/database/NotificationModelTest.php` — owner: `tester`
   - Seed rows for two users; assert `listForUser`/`countForUser`/`unreadCountForUser` scoping and order;
     `markRead` happy path + **IDOR** (user A cannot mark user B's row — returns false, row unchanged);
     `markAllRead` affects only the caller and returns the correct count.
2. [ ] `tests/feature/Web/NotificationsTest.php` — owner: `tester`
   - Authenticated feed/unread-count JSON envelopes; POST mark-read / mark-all-read with CSRF; cross-user
     mark-read → 404, no mutation; **guest → redirect to login** (gating).
3. [ ] `tests/feature/Web/NotificationsViewTest.php` — owner: `tester`
   - Full page renders the user's rows only; **XSS**: a message containing `<script>`/`"` is escaped in the
     server-rendered page and delivered raw (not HTML-injected) via the feed for `x-text` binding.

## 6. Test plan
- **Unit / DB (model):**
  - `listForUser` returns only the user's rows, newest first, respects limit/offset.
  - `countForUser` / `unreadCountForUser` correct per user.
  - `markRead` sets `is_read=1` for an owned row; returns false + no mutation for a foreign/missing id (IDOR).
  - `markAllRead` marks only the caller's unread rows; returns affected count; caller's already-read and
    other users' rows untouched.
- **Integration (controller / endpoints):**
  - `GET /notifications/unread-count` and `/notifications/feed` return `status=success` + `csrf_hash`.
  - `POST /notifications/mark-read` (owned) → success + fresh `unread_count`; (foreign) → 404.
  - `POST /notifications/mark-all-read` → success, `unread_count=0`.
  - Missing/invalid `id` on mark-read → 404.
  - Guest hitting any route → redirect to login (authFilter).
- **Browser / E2E:**
  - Load any page → badge shows unread count; open bell → recent list; click a row → badge decrements,
    row marked read; "Mark all as read" → badge clears; "View all" → `/notifications`.
- **Manual smoke (post-merge):**
  1. `php spark migrate` on the dev DB (no-op if `notifications` exists) and on a fresh test DB.
  2. Insert a couple of rows for your user (SQL or the test seeder); log in; verify bell badge, dropdown,
     mark-one, mark-all, and the `/notifications` page as both an admin and a client user.

## 7. Risks & open questions
- **Decision — polling vs load-once (resolved):** **No interval poll.** The badge loads once per page via a
  single `x-init` fetch to `/notifications/unread-count`; the recent list loads only when the dropdown is
  opened (`/notifications/feed`); mark-one/mark-all update the badge from their JSON response. This avoids a
  chatty poll while keeping the count fresh on navigation. Upgrade path: add an interval or SSE later if
  near-real-time badge updates are needed — the endpoints already support it.
- **Decision — bell state isolation (resolved):** the bell uses a nested `notificationBell()` Alpine
  component, not the shared `appShell()` root, so the topbar change cannot regress toasts/confirms/alerts.
- **Decision — one shared controller (resolved):** notifications are per-identity, identical for admin and
  client, so a single `App\Controllers\Notifications` under the `authFilter` group serves both — no
  Admin//Client/ split. Reads/mutations scope to `session('userWorking')->id`.
- **Risk — untrusted message text (XSS):** messages are stored text authored elsewhere (future scheduled
  tasks). Every render path must `esc()` (server) or `x-text` (Alpine) — never `x-html`. Covered by the view
  test.
- **Risk — migration on an existing table:** dev DB already has `notifications`; the create migration must be
  idempotent (`ifNotExists`) so it neither errors nor drops data. `down()` dropping the table is destructive
  — acceptable in dev/test, note it in rollout.
- **Open question — `task_id` link:** legacy stores an optional `task_id` (link to a scheduled task). This
  card carries the column but does not render a link target (scheduled-tasks page does not exist yet). OK to
  ignore for now; revisit when the scheduled-tasks card lands.

## 8. Rollout
- **Migration order:** run after existing migrations; `2026-07-13-140000_CreateNotifications` is
  idempotent (safe on dev, creates on test). No data backfill.
- **Env vars:** none new. Uses existing session (`userWorking`) and CSRF config.
- **Feature flag:** none; additive UI. If needed, the sidebar link and bell can be omitted to soft-hide.
- **Deploy notes:** clear/rebuild routes cache if enabled; no asset build changes beyond the new view
  markup (Tailwind via existing CDN/build). Verify `php spark routes` shows the five new routes under
  `authFilter` and that `api/*` is unaffected.
