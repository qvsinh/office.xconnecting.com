# Deployment — office.xcn.vn (CI4 attendance app)

Target: nginx + PHP-FPM on an AWS EC2 instance, MySQL on the same host, no
reverse proxy in front. Everything below is specific to this repository — the
paths, commands and `.env` keys are the real ones.

---

## 0. Decide these two things first

### 0.1 `CI_ENVIRONMENT` must be `production`

The repo's local `.env` uses `development`. On a public host that setting:

- renders the CodeIgniter **debug toolbar** into every page, and
- shows **full stack traces with file paths and query dumps** to whoever
  triggers a PHP error, and
- writes a complete dump of every request — session contents, POST bodies, SQL —
  to `writable/debugbar/`, which **nothing ever prunes** unless the toolbar is
  successfully opened (49 MB accumulated in four days locally).

This is the single most important line in the production `.env`.

### 0.2 Password hashing — already fixed, one thing to know

Passwords are hashed with **bcrypt** through `password_hash()`, configured in
`.env`:

```ini
auth.password_algorithm = 'bcrypt'   # bcrypt | argon2i | argon2id | default
auth.password_cost      = 12         # bcrypt only; +1 doubles hash and crack time
```

They used to be an **unsalted MD5** digest inherited from the starter — one
digest per password, no work factor, crackable from public rainbow tables. All
hashing and verification now goes through `App\Libraries\PasswordHasher`, which
replaced seven scattered copies of `hash($algo, $plain . $salt)`.

**Old rows migrate themselves.** `PasswordHasher::verify()` still accepts a
legacy MD5 digest, and `Auth::login()` rewrites the row with a bcrypt hash the
moment the owner signs in successfully — login is the only place the plaintext
exists, so it is the only place this can happen. Nobody is locked out and no
forced reset is needed. The same mechanism re-hashes everyone if you later raise
`auth.password_cost`.

Once every row has migrated, check with:

```sql
SELECT COUNT(*) FROM users WHERE password NOT LIKE '$%';
```

When that returns 0, you can set `auth.legacy_algorithm = ''` to stop accepting
legacy digests at all.

---

## 1. Server requirements

```bash
sudo apt update
sudo apt install -y nginx mysql-server \
  php8.3-fpm php8.3-mysql php8.3-intl php8.3-mbstring php8.3-curl \
  php8.3-xml php8.3-zip unzip git
```

Required PHP extensions, and what needs each one:

| Extension | Required by |
|---|---|
| `intl` | codeigniter4/framework |
| `mbstring` | framework, hashids, laminas-escaper |
| `ctype` | laminas-escaper (used by `esc()`) |
| `iconv` | bacon/bacon-qr-code (2FA QR codes) |
| `mysqlnd` / `pdo_mysql` | database |

PHP **8.2+** (the framework's floor); 8.3 is what this was developed against.

**Node.js is not needed on the server.** `public/css/app.css` is committed to
git. It only has to be rebuilt (`npm run build`) and committed **on your
machine** whenever `app/Views/**` gains a new Tailwind class — Tailwind's JIT
only emits classes it can see at build time.

Verify the PHP config with the framework's own checker:

```bash
php spark phpini:check
```

---

## 2. First deploy

```bash
# as the deploy user
sudo mkdir -p /var/www/office.xcn.vn
sudo chown $USER:$USER /var/www/office.xcn.vn
git clone <repo-url> /var/www/office.xcn.vn
cd /var/www/office.xcn.vn
git checkout office          # or main, once merged

composer install --no-dev --optimize-autoloader
```

`--no-dev` skips PHPUnit/phpstan/php-cs-fixer. Do not run the test suite against
the production database — `tests/` uses `$refresh = true`, which **drops and
rebuilds every table**.

### Permissions

The code itself only needs to be readable:

```bash
sudo chown -R $USER:www-data /var/www/office.xcn.vn
sudo find /var/www/office.xcn.vn -type d -exec chmod 750 {} \;
sudo find /var/www/office.xcn.vn -type f -exec chmod 640 {} \;
```

`writable/` is the exception, and it needs more than a chmod: git cannot store an
empty directory, so `writable/logs`, `writable/cache` and `writable/debugbar`
**do not exist in a fresh clone** (their contents are gitignored and no file
inside them is tracked). The first request would die trying to write a log.

```bash
sudo scripts/prepare-writable.sh
```

The script creates whatever is missing, sets the group to the web user with mode
`2775` (setgid, so files PHP-FPM creates stay readable to the deploy user), drops
an `index.html` guard in each directory, then **proves** each one is writable by
actually writing to it as the web user. It exits non-zero if any check fails, so
a deploy script can stop instead of serving a broken site. Safe to re-run on
every deploy — pass an explicit user (`sudo scripts/prepare-writable.sh nginx`)
if PHP-FPM does not run as `www-data`.

Ownership deliberately stays with the deploy user and the web server gets in
through the group. Handing `writable/` to `www-data` outright would let a
compromised PHP process rewrite files it should only be able to append to.

---

## 3. Production `.env`

Create `/var/www/office.xcn.vn/.env`. It is gitignored, so it never arrives from
the repo — write it on the server.

```ini
CI_ENVIRONMENT = production

app.baseURL   = 'https://office.xcn.vn/'
app.indexPage = ''

# Optional site-wide gate in front of the login page. Leave both empty to
# disable it. (Empty values are also why 4 BasicAuthGateTest tests fail locally.)
app.basic.auth.username = ''
app.basic.auth.password = ''

database.default.hostname = 127.0.0.1
database.default.database = db_office_xcn_vn
database.default.username = office
database.default.password = '<strong-password>'
database.default.DBDriver = MySQLi
database.default.DBPrefix =
database.default.port     = 3306

# Sessions live in the `ci_sessions` table (migration 2026-07-15-020000).
session.driver            = 'CodeIgniter\Session\Handlers\DatabaseHandler'
session.cookieName        = 'ci_session'
session.expiration        = 7200
session.savePath          = 'ci_sessions'
session.matchIP           = false
session.timeToUpdate      = 300
session.regenerateDestroy = false

# Password hashing (see §0.2). bcrypt is the safe default; raise the cost as
# hardware improves — existing rows re-hash themselves on next login.
auth.password_algorithm = 'bcrypt'
auth.password_cost      = 12

# Outbound mail. Read by Config\Mail; nothing dispatches yet (§8). `region` is
# required for SES and has no default — SES is regional, and the wrong region
# fails silently: the credentials look valid and nothing arrives.
mail.sending_enable = 'false'        # accepts true/false/yes/no/on/off/1/0

mail.sender.name     = 'No-Reply'
mail.sender.email    = 'no-reply@example.com'
mail.supporter.email = 'admin@example.com'

mail.aws.access_key_id     = ''
mail.aws.secret_access_key = ''
mail.aws.region            = ''      # e.g. ap-northeast-1

# Cache-busting token appended to /css/app.css. **Bump this on every deploy that
# changes the stylesheet.** Leave it set: with no value, asset_version()
# randomises per render, which disables browser caching of the CSS entirely.
asset.version = 'v1'

encryption.key    =            # fill via `php spark key:generate`
encryption.driver = OpenSSL

security.csrfProtection = 'session'
security.tokenRandomize = true
security.tokenName      = 'csrf_token_name'
security.headerName     = 'X-CSRF-TOKEN'
security.cookieName     = 'csrf_cookie_name'
security.expires        = 7200
security.regenerate     = true
security.redirect       = false
cookie.samesite         = 'Lax'   # Config\Cookie, not Config\Security

hashid_salt = '<fresh-random-string>'   # signs /api/v1 keys; see note below

language.source = 'db'

app.rpp_default = 25
```

Then:

```bash
php spark key:generate      # writes encryption.key into .env
chmod 640 .env
sudo chown $USER:www-data .env
```

Notes on individual keys:

- **`hashid_salt`** signs the `/api/v1` Bearer keys. Set it once and never
  change it — rotating it invalidates every issued API key. Any long random
  string is fine on a fresh install.
- **`Config\App::$proxyIPs` must stay empty.** nginx talks to PHP-FPM over a
  local socket, so `getIPAddress()` already sees the real client address.
  Populating `proxyIPs` would make the app trust a client-supplied
  `X-Forwarded-For`, which would let anyone forge their address past the
  attendance office-IP gate and into the access log. Only set it if you later
  put an ALB or CloudFront in front — and then list *that* proxy's addresses.
- **Every key above is read by something.** The starter's dead keys are gone —
  `RUNNING_MODE`, `app.cost_mode`, `app.rpp`, `compress.output`,
  `language.source`, `sparkpost.*` and `security.2fa.*` were all inert. Two were
  actively misleading: `security.samesite` was ignored because SameSite lives on
  `Config\Cookie` (it is `cookie.samesite` above), and `security.2fa.name` looked
  like it set the authenticator label while the real label was hardcoded in
  `Google2faService`.
- **The `mail.*` group** is read by `Config\Mail` but has no consumer yet: no
  code dispatches mail, and no AWS SDK or mail library is installed (see §8).
  Configure it now if you like; it will not send anything until the pipeline
  exists.
- **Do not copy `database.tests.*`** to the server. It exists so the test suite
  can wipe a throwaway schema.

---

## 4. Database and migrations

```bash
sudo mysql -e "CREATE DATABASE db_office_xcn_vn CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER 'office'@'127.0.0.1' IDENTIFIED BY '<strong-password>';"
sudo mysql -e "GRANT ALL PRIVILEGES ON db_office_xcn_vn.* TO 'office'@'127.0.0.1'; FLUSH PRIVILEGES;"

cd /var/www/office.xcn.vn
php spark migrate
php spark migrate:status      # confirm every migration is applied
```

`php spark migrate` builds the whole schema: users, roles/permissions, sessions,
site variables, translations, email templates, notifications, scheduled tasks,
and the attendance stack (`attendance_days`, `work_schedules`, `holidays`,
`leave_requests`, `access_logs`).

**Run this before the first request.** Sessions are stored in `ci_sessions`; if
that table does not exist yet, logging in fails.

Keep MySQL bound to localhost (`bind-address = 127.0.0.1`) and never open 3306
in the EC2 security group.

### Only now: turn on production caching

```bash
php spark optimize      # caches config + the file locator
```

Run this **after** `.env` is final, never before — it snapshots the resolved
config, so an `optimize` run against a half-written `.env` bakes in the wrong
values. Re-run it (or `php spark cache:clear`) after **any** later change to
`.env` or to anything under `app/Config/`.

---

## 5. nginx

`/etc/nginx/sites-available/office.xcn.vn`:

```nginx
server {
    listen 80;
    server_name office.xcn.vn;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name office.xcn.vn;

    # Document root is public/ — app/, writable/ and .env sit above it and are
    # therefore unreachable over HTTP.
    root /var/www/office.xcn.vn/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/office.xcn.vn/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/office.xcn.vn/privkey.pem;

    client_max_body_size 8m;

    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options SAMEORIGIN always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;

    # `app.indexPage = ''` means clean URLs: everything not on disk goes to CI4.
    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    # Never serve dotfiles (.env, .git) even if the root is ever misconfigured.
    location ~ /\. { deny all; }

    location ~* \.(css|js|svg|png|jpg|jpeg|gif|ico|woff2?)$ {
        expires 30d;
        access_log off;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/office.xcn.vn /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d office.xcn.vn
```

---

## 6. Create the admin users

### 6.1 The first admin (CLI, because nobody can log in yet)

`AdminUserSeeder` reads its target from `.env`, is idempotent per email, and
always creates a `zone = admin` account wired to the `admin` role.

```bash
# add temporarily to .env
app.admin.email    = 'sinh@xcn.vn'
app.admin.password = '<a-long-generated-password>'
```

```bash
php spark db:seed AdminUserSeeder
```

### 6.2 A second (or third) admin

Same seeder, different email — it skips any address that already exists:

```bash
# edit .env
app.admin.email    = 'hr@xcn.vn'
app.admin.password = '<another-generated-password>'
```

```bash
php spark db:seed AdminUserSeeder
```

**Then delete `app.admin.email` and `app.admin.password` from `.env`.** Leaving a
plaintext password in a file that PHP-FPM reads on every request has no upside.

### 6.3 Everyone after that: use the UI

Log in as the first admin → **Admin → Users → + Add user**:

- **Zone: Admin** for administrators, **User** for employees.
- Press **Generate** for a policy-compliant password, then **Copy password** and
  hand it over.

Do not leave the password field blank. A blank field auto-generates a password
that is never displayed anywhere, producing an account nobody can sign into
until it is reset.

### 6.4 Changing a password from the CLI

Both commands act on an **existing** user; neither creates one:

```bash
php spark user:password <email> <new-password>
php spark user:reset-password <id-or-email>
```

### 6.5 Never run `DevAdminSeeder` on the server

`app/Database/Seeds/DevAdminSeeder.php` hardcodes the password `Proxy@2026!`.
It exists for local development only.

---

## 7. Scheduled tasks (cron)

Reminders, digests and purges all run through one dispatcher. Add exactly one OS
cron entry:

```cron
* * * * * cd /var/www/office.xcn.vn && php spark schedule:run >> writable/logs/schedule.log 2>&1
```

```bash
sudo crontab -u www-data -e     # same user as PHP-FPM, so writable/ stays writable
```

Handlers are a fixed in-code allowlist (`app/Libraries/ScheduledTaskRunner.php`)
— the database chooses *which* named job runs and when, never what code exists.
Tasks are seeded **disabled**; enable the ones you want in
`/admin/scheduled-tasks`:

| Task | Purpose |
|---|---|
| `attendance.flag_missing_checkout` | marks yesterday's un-checked-out days |
| `attendance.remind_checkout` | nudges anyone still checked in at the end of the day |
| `attendance.missing_checkout_digest` | weekly manager summary of uncorrected days |
| `attendance.absence_digest` | daily summary of who is absent |
| `requests.pending_digest` | reminds approvers about waiting leave requests |
| `access_logs.purge` | drops access-log rows older than 90 days |
| `emails.purge`, `notifications.purge` | starter retention jobs |

The full allowlist is `app/Libraries/ScheduledTaskRunner.php`; the settings each
one reads (`attendance_checkout_reminder`, `attendance_absence_digest`, …) are
under **Settings**.

---

## 8. What this app does *not* do yet

- **Password reset by the user.** Admins reset passwords; there is no
  self-service "forgot password" flow. `Config\Email::$protocol` is
  still the PHP `mail` default and nothing calls it. Attendance reminders are
  delivered **in-app only** (`NotificationModel`) — deliberately, so no setting
  promises an email that never arrives. Wiring SMTP is a separate change.
- **Password reset by the user.** Admins reset passwords; there is no
  self-service "forgot password" flow.
- `docs/CNR/` and `docs/JPRS/` are stale documentation from the starter's
  previous life as an API proxy. Nothing in `app/` uses them.

---

## 8b. Email (Amazon SES)

Check-in and check-out email the office administrator. The transport is
`App\Libraries\Mailer` (AWS SDK, `aws/aws-sdk-php`), and every attempt — sent,
failed or suppressed — is recorded in `/admin/emails`.

**Credentials live in `.env`, behaviour lives in Settings.** An AWS secret key has
no business in a table `/admin/settings` prints on screen, and the recipient needs
changing without a deploy.

`.env` (§3): `mail.sending_enable = 'true'` plus all three of
`mail.aws.access_key_id`, `mail.aws.secret_access_key`, `mail.aws.region`.
`Config\Mail::configured()` requires all three, so a missing region is reported
rather than silently swallowed.

`/admin/settings`:

| Setting | Meaning |
|---|---|
| `mail_notify_recipient` | who receives check-in/out mail (`admin@xconnecting.com`) |
| `mail_notify_attendance` | `1` to send, `0` to stop |

Two things to get right on the AWS side:

1. **Verify the sender.** `mail.sender.email` must be a verified SES identity, or
   every send is refused. In the SES sandbox the *recipient* must be verified too.
2. **Do not use an IAM user if the box has an instance role.** Give the EC2 role
   `ses:SendEmail` and leave `mail.aws.*` empty — but note the current `Mailer`
   passes credentials explicitly, so it needs the keys. Either way, **never** put
   them in `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY`: the SDK's default
   credential chain reads those and they then shadow the instance role for *every*
   AWS client in the process, which is how a sibling project broke its S3 uploads.

Nothing is queued: the mail goes out inline on the employee's request. One SES
call is fast, and a failure can never fail the check-in — but if the office grows
enough for that latency to show, move it behind `schedule:run`.

## 8c. Languages

English and Vietnamese, switchable from the account menu in the header.

`lang('group.key')` resolves from the `translation` table, so `/admin/translations`
genuinely changes what the app says — that screen used to be CRUD over a table
nothing read. Database rows layer *over* the file strings in `app/Language/`, so
CodeIgniter's own messages keep working and an administrator can override any
single string without a deploy.

The table's `location` column is the group and `slug` is the key: a row
`('vi', 'nav', 'dashboard', 'Trang chủ')` answers `lang('nav.dashboard')`.

Which language a request renders in, most specific first:

1. the switcher's choice, held in the session;
2. the signed-in user's `users.language`, so it follows them to another browser;
3. `Config\App::$defaultLocale`.

Anything outside `Config\App::$supportedLocales` is ignored rather than trusted —
CodeIgniter interpolates the locale into a filesystem path when loading language
files, so an unchecked value from a form is a path-traversal primitive.

To add a third language: add its code to `$supportedLocales`, add a label to
`$navLocaleNames` in `app/Views/layouts/app.php`, then add its rows in
`/admin/translations` (or import them — the screen has a JSON export to copy the
shape from).

**What is translated today:** the sidebar navigation, the header and the account
menu. Everything else still holds literal English — page headings, table columns,
buttons, form labels. The mechanism is finished and proven; converting the
remaining views is mechanical work, one `lang()` call and one row per string.

## 9. First-run configuration (in the admin UI)

1. **Settings → attendance** — confirm the workday (08:30–18:00, 90 min lunch,
   Mon–Fri), the grace period, and `leave_annual_days` (the default annual leave
   quota; individual employees can override it).
2. **Office IP allowlist.** Put the office's public address in
   `attendance_office_ips` (comma-separated; CIDR accepted), then switch
   `attendance_ip_enforce` on. Until it is on, attendance can be recorded from
   anywhere. The attendance page shows the address the server sees for you,
   which is the value to allowlist.
3. **Holidays** — enter this year's public holidays, or every one of them counts
   as an absence.
4. **Work schedules** — add any shift that differs from the default, then assign
   it per employee.

---

## 10. Post-deploy smoke test

```bash
curl -I https://office.xcn.vn/          # 200 or 302 to /login, never 500
```

1. `/login` renders; log in as the first admin.
2. Top-right header shows the avatar and name.
3. `/attendance` — **Check in** is offered (not "Check out"), and pressing it
   records a time.
4. `/admin/attendance/today` lists the check-in.
5. `/admin/access-logs` shows the `login` and `checkin` events with IP, browser,
   OS, device and screen size.
6. `/admin/users` — create a test employee with **Generate**, then log in as
   them in a private window.
7. **View source on any page**: there must be no `?debugbar` script and no stack
   traces. If you see either, `CI_ENVIRONMENT` is not `production`.
8. `tail writable/logs/log-$(date +%Y-%m-%d).php` — no unexpected errors.

---

## 11. Updating a running deployment

```bash
cd /var/www/office.xcn.vn
git pull
composer install --no-dev --optimize-autoloader
sudo scripts/prepare-writable.sh    # new writable/ dirs, and re-assert permissions
php spark migrate
php spark cache:clear                # drops the config cache from `optimize`
php spark optimize                   # rebuild it from the new code
sudo systemctl reload php8.3-fpm     # clears OPcache
```

There is no maintenance-mode command in this CI4 version (`spark down`/`up` do
not exist). For a long migration, either accept the brief inconsistency or make
nginx return 503 from a `location /` override while you work.

Every migration that touches a pre-existing table is guarded by
`tableExists()` / `fieldExists()` and no-ops, so `php spark migrate` is safe to
re-run.

Two things that bite:

- **OPcache serves stale PHP** until PHP-FPM is reloaded. `/admin/system` also
  exposes an OPcache reset.
- **Browsers cache `/css/app.css`.** After a view change, rebuild and commit the
  CSS on your machine (`scripts/build-assets.sh`), then **bump `asset.version` in
  the server's `.env`** — that is what changes the `?v=` on the link and makes
  every browser fetch the new file. Without the bump, returning visitors keep the
  old stylesheet.
