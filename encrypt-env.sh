#!/bin/bash
# Encrypt .env.<env> -> .env.<env>.encrypted using GPG symmetric AES256.
# Usage: ./encrypt-env.sh --env=<development|staging|production>
set -euo pipefail

usage() {
    echo "Usage: $0 --env=<development|staging|production>" >&2
    exit 1
}

ENV_NAME=""
for arg in "$@"; do
    case "$arg" in
        --env=*)    ENV_NAME="${arg#--env=}" ;;
        -h|--help)  usage ;;
        *)          echo "Unknown argument: $arg" >&2; usage ;;
    esac
done

case "$ENV_NAME" in
    development|staging|production) ;;
    *) usage ;;
esac

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="${SCRIPT_DIR}/.env.${ENV_NAME}"
DST="${SCRIPT_DIR}/.env.${ENV_NAME}.encrypted"
PASS_FILE="${SCRIPT_DIR}/.gpg.${ENV_NAME}.txt"

if [[ ! -f "$SRC" ]]; then
    echo "Error: $SRC not found" >&2
    exit 1
fi

if [[ ! -f "$PASS_FILE" ]]; then
    echo "Error: passphrase file $PASS_FILE not found" >&2
    exit 1
fi

gpg --batch --yes --pinentry-mode loopback \
    --passphrase-file "$PASS_FILE" \
    --symmetric --cipher-algo AES256 --output "$DST" "$SRC"
echo "Encrypted: $SRC -> $DST"
