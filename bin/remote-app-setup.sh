#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
TARGET_FILE="${TARGET_FILE:-${ROOT_DIR}/deploy/local/deploy-target.env}"

if [[ ! -f "${TARGET_FILE}" ]]; then
  echo "Missing ${TARGET_FILE}" >&2
  exit 1
fi

# shellcheck disable=SC1090
source "${TARGET_FILE}"

: "${SERVER_HOST:?}"
: "${SERVER_USER:?}"
: "${SERVER_APP_DIR:?}"

SSH_PORT="${SERVER_SSH_PORT:-22}"
SSH_CMD=(ssh -p "${SSH_PORT}")
REMOTE_HOST="${SERVER_USER}@${SERVER_HOST}"
if [[ -n "${SERVER_SSH_KEY_PATH:-}" ]]; then
  SSH_CMD+=( -i "${SERVER_SSH_KEY_PATH}" )
fi
if [[ "${SERVER_IPV6:-0}" == "1" ]]; then
  SSH_CMD=(ssh -6 -p "${SSH_PORT}")
  if [[ -n "${SERVER_SSH_KEY_PATH:-}" ]]; then
    SSH_CMD+=( -i "${SERVER_SSH_KEY_PATH}" )
  fi
fi

"${SSH_CMD[@]}" "${REMOTE_HOST}" "mkdir -p '${SERVER_APP_DIR}' && cd '${SERVER_APP_DIR}' && php bin/init-runtime.php && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader && php bin/preflight.php"
