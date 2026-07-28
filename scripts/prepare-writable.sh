#!/usr/bin/env bash
#
# Create and permission everything under writable/ that the app needs to run.
#
# Why this exists: git cannot store an empty directory, and writable/logs,
# writable/cache and writable/debugbar have no tracked files (their contents are
# gitignored). A fresh `git clone` therefore arrives *without* them, and the
# first request dies trying to write a log. This script makes a checkout runnable
# in one step, and is safe to re-run on every deploy.
#
# Usage:
#   scripts/prepare-writable.sh                 # local dev: create + chmod only
#   sudo scripts/prepare-writable.sh            # also set the group to the web user
#   sudo scripts/prepare-writable.sh nginx      # explicit web user
#   WEB_USER=nginx sudo -E scripts/prepare-writable.sh
#
# Exits non-zero if any directory is still not writable at the end, so a deploy
# script can stop rather than serve a broken site.

set -euo pipefail

# ---------------------------------------------------------------- locations ---

# Resolve the app root from this script's own path, so it works from any cwd.
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
WRITABLE="${APP_ROOT}/writable"

# Every directory the app writes to, and what writes there:
#   logs      Config\Logger + /admin/system/logs        (always)
#   cache     Config\Cache, handler = 'file'            (always)
#   session   Config\Session::$savePath fallback        (only with FileHandler,
#             but /admin/system reports on it, so it must exist)
#   uploads   file uploads
#   debugbar  the debug toolbar, when CI_ENVIRONMENT != production
DIRECTORIES=(
    logs
    cache
    session
    uploads
    debugbar
)

# 2775 = rwxrwxr-x + setgid. The setgid bit is the point: files PHP-FPM creates
# inherit the directory's group, so the deploy user can still read and rotate
# logs it did not create. 775 without it leaves root-owned files nobody else can
# touch. Never 777 — that grants write access to every account on the box.
DIR_MODE=2775
FILE_MODE=664

# ------------------------------------------------------------- the web user ---

detect_web_user() {
    # Whoever PHP-FPM actually runs as is the only answer that matters.
    local from_fpm
    from_fpm="$(ps -eo user:32,comm 2>/dev/null | awk '$2 ~ /^php-fpm/ && $1 != "root" { print $1; exit }' || true)"

    if [[ -n "${from_fpm}" ]]; then
        printf '%s' "${from_fpm}"
        return
    fi

    # No FPM running (a fresh box, or a local checkout) — fall back to whichever
    # conventional account exists.
    local candidate
    for candidate in www-data nginx apache http; do
        if id -u "${candidate}" >/dev/null 2>&1; then
            printf '%s' "${candidate}"
            return
        fi
    done

    printf '%s' "$(id -un)"
}

WEB_USER="${1:-${WEB_USER:-$(detect_web_user)}}"

if ! id -u "${WEB_USER}" >/dev/null 2>&1; then
    printf 'error: user %s does not exist on this host.\n' "${WEB_USER}" >&2
    exit 1
fi

WEB_GROUP="$(id -gn "${WEB_USER}")"
AM_ROOT=0
[[ "$(id -u)" -eq 0 ]] && AM_ROOT=1

printf 'App        : %s\n' "${APP_ROOT}"
printf 'Web user   : %s (group %s)\n' "${WEB_USER}" "${WEB_GROUP}"

if [[ "${AM_ROOT}" -eq 0 ]]; then
    printf 'Ownership  : skipped (not root) — run with sudo to set the group\n'
fi
printf '\n'

# ------------------------------------------------------------------- create ---

created=0

for name in "${DIRECTORIES[@]}"; do
    target="${WRITABLE}/${name}"

    if [[ ! -d "${target}" ]]; then
        mkdir -p "${target}"
        printf '  created  writable/%s\n' "${name}"
        created=$((created + 1))
    fi

    # A guard file in every directory. writable/.htaccess already denies access
    # under Apache, and nginx serves from public/ so these are unreachable
    # anyway — this is the third layer, for the day someone points a document
    # root at the project root by mistake.
    if [[ ! -f "${target}/index.html" ]]; then
        cat > "${target}/index.html" <<'HTML'
<!DOCTYPE html><html><head><title>403 Forbidden</title></head>
<body><p>Directory access is forbidden.</p></body></html>
HTML
    fi
done

if [[ "${created}" -eq 0 ]]; then
    printf '  all directories already present\n'
fi

# -------------------------------------------------------------- permissions ---

if [[ "${AM_ROOT}" -eq 1 ]]; then
    # Group, not owner: the files stay owned by whoever deploys, and the web
    # server gets in through the group. Handing ownership to www-data would let
    # a compromised PHP process rewrite its own code.
    chgrp -R "${WEB_GROUP}" "${WRITABLE}"

    find "${WRITABLE}" -type d -exec chmod "${DIR_MODE}" {} +
    find "${WRITABLE}" -type f -exec chmod "${FILE_MODE}" {} +

    printf '  permissions set: dirs %s, files %s\n\n' "${DIR_MODE}" "${FILE_MODE}"
else
    # Unprivileged: chmod only what this user owns. Logs and cache files written
    # by the web server belong to it, and trying to chmod those fails — which
    # under `set -e` would abort a run that is otherwise doing its job. The
    # directories are what matter, and those are ours.
    me="$(id -un)"

    find "${WRITABLE}" -user "${me}" -type d -exec chmod "${DIR_MODE}" {} +
    find "${WRITABLE}" -user "${me}" -type f -exec chmod "${FILE_MODE}" {} +

    printf '  permissions set on entries owned by %s: dirs %s, files %s\n' "${me}" "${DIR_MODE}" "${FILE_MODE}"

    foreign="$(find "${WRITABLE}" ! -user "${me}" | wc -l | tr -d ' ')"
    if [[ "${foreign}" -gt 0 ]]; then
        printf '  left alone: %s entries owned by another user (written by the web server)\n' "${foreign}"
    fi
    printf '\n'
fi

# -------------------------------------------------------------------- verify ---

# Creating the directory is not proof the web server can write to it — the
# mode, the group and every parent directory's traversal bit all have to line
# up. So actually try, as the web user when we can.
probe_as() {
    local dir="$1" probe="$1/.write-probe.$$"

    if [[ "${AM_ROOT}" -eq 1 && "${WEB_USER}" != "$(id -un)" ]]; then
        sudo -u "${WEB_USER}" -- touch "${probe}" 2>/dev/null && sudo -u "${WEB_USER}" -- rm -f "${probe}" && return 0
        return 1
    fi

    touch "${probe}" 2>/dev/null && rm -f "${probe}" && return 0
    return 1
}

failed=0

for name in "${DIRECTORIES[@]}"; do
    if probe_as "${WRITABLE}/${name}"; then
        printf '  OK    writable/%s\n' "${name}"
    else
        printf '  FAIL  writable/%s is not writable by %s\n' "${name}" "${WEB_USER}" >&2
        failed=$((failed + 1))
    fi
done

printf '\n'

if [[ "${failed}" -gt 0 ]]; then
    printf '%d directory/directories are not writable. The app will error on first use.\n' "${failed}" >&2

    if [[ "${AM_ROOT}" -eq 0 ]]; then
        printf 'Try again with sudo so the group can be set.\n' >&2
    else
        # The usual cause once the group is right.
        printf 'Check that %s can traverse every parent of %s (each needs +x).\n' "${WEB_USER}" "${WRITABLE}" >&2
    fi

    exit 1
fi

printf 'writable/ is ready.\n'
