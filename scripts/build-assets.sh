#!/usr/bin/env bash
#
# Build (or watch) the Tailwind stylesheet.
#
# Wraps `npm run build` so a fresh checkout does not need anyone to remember the
# `npm install` first, and so the reminder to commit the output is where you will
# actually read it.
#
#   scripts/build-assets.sh          # one-off production build (minified)
#   scripts/build-assets.sh --watch  # rebuild on change, for development
#
# Tailwind's JIT only emits classes it can see at build time (the content glob is
# ./app/Views/**/*.php), so any new utility in a view — `min-h-[500px]` and
# friends especially — needs a rebuild before it exists in the CSS.

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd)"

cd "${APP_ROOT}"

if ! command -v npm >/dev/null 2>&1; then
    printf 'error: npm is not installed.\n' >&2
    printf 'The compiled public/css/app.css is committed, so a *deploy* needs no npm —\n' >&2
    printf 'only rebuilding after a view change does.\n' >&2
    exit 1
fi

# node_modules is gitignored, so a fresh clone has none.
if [[ ! -d node_modules ]]; then
    printf '==> installing dev dependencies\n'
    npm install
fi

if [[ "${1:-}" == "--watch" || "${1:-}" == "-w" ]]; then
    printf '==> watching app/Views for class changes (Ctrl-C to stop)\n'
    exec npm run dev
fi

printf '==> building public/css/app.css\n'
npm run build

printf '\n'
printf 'Done. public/css/app.css is tracked by git — commit it, or production\n'
printf 'will serve the previous stylesheet.\n'
