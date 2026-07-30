# UI/UX upgrade playbook — audit and adapted plan

Source: a portable playbook for CI4 + Tailwind + Alpine admin panels. This card
records what already holds in **this** repo, what the generic advice gets wrong
here, and the work that is actually left.

Audited 2026-07-30 against `main` @ `bff6124`.

---

## Audit

| # | Item | Status here |
|---|---|---|
| 1 | Compiled Tailwind instead of the Play CDN | **Already done.** Tailwind v3 CLI (`package.json`), `tailwind.config.js` with `darkMode:'class'` + the `brand` palette, `public/css/app.css` committed. No `cdn.tailwindcss.com` anywhere. |
| 2 | `asset_version()` cache-busting | **Missing.** `Config\Autoload::$helpers` is `[]`, and the CSS link has no `?v=`. |
| 3 | Global `<select>` styling | **Partial.** A `.form-select` component exists with an arrow, but it is opt-in per element. |
| 4 | time-ago + `[data-timeago]` hydrator | **Missing** entirely. |
| 5 | Readability pass | **Missing.** Verified: `#787774` on white is **4.48:1** — it *fails* WCAG AA for body text (4.5:1), by a hair. Used 324 times across 43 views. `#5C5955` measures 6.96:1. Dark muted `#9ca3af` on `#1f2937` is 5.78:1 and needs no change. |
| 6 | Dark-mode white flash between navigations | **Partial.** The pre-paint script adds the `dark` class but never sets `documentElement.style.backgroundColor`, and neither does the toggle. |
| 7 | Sidebar scrollbar flash before Alpine boots | **Present.** The accordion body uses a `grid-rows-[0fr]/[1fr]` `:class`, so before Alpine binds there is no row-size class at all and every group renders open. |
| 8 | Rows-per-page moved to the pagination footer | **Missing.** Nine views carry `name="limit"` inside the top filter form. |
| 9 | Admin-editable app name in sidebar + title | **Already done.** `site_name()` drives the sidebar brand, all three auth-page titles and the landing page. |
| 10 | List/table conventions | Case by case — see below. |
| 11 | Multi-size `favicon.ico` | **Partial.** The `.ico` is a single 32×32; the markup already has both `<link>` tags. |

## Where the generic playbook does not fit

- **Item 1's content glob.** The playbook suggests `./app/**/*.php`; this repo
  uses `./app/Views/**/*.php`. Checked: no Tailwind class strings are built
  outside `app/Views`, so widening it would only slow the build. Leave it.
- **Item 3's `!important` wall.** The playbook makes every property
  `!important` to out-specify `pr-*` utilities. `.form-select` here already sets
  `pr-10` inside the component layer, so a plain `select:not([multiple])` rule in
  the base layer is enough for the un-classed selects and needs no `!important`
  except on `padding-right`, which utilities do fight over.
- **Item 3's literal spaces.** Our existing data URI has raw spaces inside a
  *quoted* `url("…")`, which is legal CSS and works — the playbook's warning
  applies to unquoted URIs. Encoding them anyway costs nothing and removes the
  question, so the new rule uses `%20`.
- **Item 4 assumes UTC storage.** True here: `Config\App::$appTimezone = 'UTC'`
  and attendance timestamps are UTC. The tooltip must therefore say UTC, and the
  attendance views that already render Vietnam-local times must be **left
  alone** — hydrating those would double-convert.
- **Item 5's `.text-sm` bump.** `.form-input`/`.form-label`/`.btn-*` all
  `@apply text-sm`, so changing `.text-sm` resizes every control in the app, not
  just prose. Accepted deliberately (it is the point), but it means the whole UI
  needs a rebuild and a look.

## Work items

1. **`asset_version()` + autoloaded helpers** — new `app_helper.php` function,
   `$helpers = ['app', 'setting']` so pre-auth pages get both, `?v=` on the CSS
   link in the layout and all three standalone auth pages, `asset.version` in
   `.env`.
2. **Global CSS** (`resources/css/app.css`): `select:not([multiple])` base rule;
   move `[x-cloak]` out of the four inline `<style>` blocks; readability
   overrides after `@tailwind utilities`.
3. **Dark-mode canvas** — set `documentElement.style.backgroundColor` in the
   pre-paint script (layout + 3 auth pages) and in the toggle handler.
4. **Sidebar** — `x-cloak` on the accordion body.
5. **time-ago** — `window.timeAgo` + `[data-timeago]` hydrator in the layout;
   apply to the UTC timestamp cells only (access log, sent emails, users' last
   login, scheduled tasks), never to attendance's local times.
6. **Rows-per-page → pagination footer** in the nine list views.
7. **Favicon** — regenerate a 16/32/48/64/128/256 `.ico` from the SVG.
8. **`build-assets.sh`** wrapper.

Item 10 is judgement, not a checklist: the access log already sorts newest-first
and already has a shared preview modal fed from row data. The users list sorts by
`login_at DESC` already. Nothing to do beyond what falls out of item 6.

## Verification

- `npm run build` after every CSS edit, and commit `public/css/app.css`.
- `vendor/bin/phpunit` — the render tests assert every page returns 200 **and**
  that its inline JavaScript parses under `node --check`, which covers the new
  `timeAgo` script and the pre-paint change.
- Re-measure contrast after the readability pass.

---

## Outcome (2026-07-30)

Implemented items 2, 3, 4, 5, 6, 7, 8, 11 and the `build-assets.sh` gap in item
1. Items 1 (core), 9 and 10 were already satisfied.

Findings worth keeping:

- **`asset_version()` does not branch on `ENVIRONMENT`.** The playbook's version
  does, and phpstan correctly calls that dead code — it analyses with
  `ENVIRONMENT='testing'`, so the production branch can never be taken. Rewritten
  to key off whether `asset.version` is *set*, which is the real condition and is
  more explicit anyway. Failure mode if a deploy forgets the key: the CSS stops
  being cached (a re-download per view), never a wrong page.
- **The `%20` encoding in the select data URI does not survive minification** —
  the minifier decodes it back to literal spaces. Encoding the source is still
  worth doing for clarity, but the playbook's Firefox/Safari warning is moot here
  because the URI is quoted.
- **Tailwind emits a third chevron rule**, `.\!dark select:not([multiple])`, an
  important-variant of the `.dark` rule inside `@layer base`. Dead but harmless;
  left alone rather than restructured around it.
- **Moving rows-per-page out of the filter form drops the page size on Search.**
  The GET form only submits its own fields. Nine forms needed a hidden `limit`
  input — the playbook does not mention this, and it is silent data loss.
- **Nine identical footers became one `_pagination` partial** rather than nine
  copies of the new markup.
- **The access log is *not* time-ago hydrated.** It already renders Vietnam-local
  times, so hydration would treat a local time as UTC and be seven hours out. Only
  the three genuinely raw-UTC cells (users' last login, sent emails, scheduled
  tasks' last run) carry `data-timeago`.
- **`esc($v, 'attr')` encodes the space in a timestamp as `&#x20;`.** Harmless —
  `getAttribute()` decodes — but it means a test cannot match the raw string.

Verified: 778 tests (464 feature + 314 database/unit/session), phpstan clean,
contrast re-measured (`#5c5955` = 6.96:1 AA pass, from 4.48:1 AA fail). The four
`BasicAuthGateTest` failures remain pre-existing.

Not done: nothing from the playbook. Item 10's remaining advice (merged columns,
bordered row actions, shared preview modal) was already the case in the views it
would apply to.
