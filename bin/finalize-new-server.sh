#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
TARGET_FILE="${TARGET_FILE:-${ROOT_DIR}/deploy/local/deploy-target-upcloud.env}"

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
SSH_BIN=(ssh -p "${SSH_PORT}")
REMOTE_HOST="${SERVER_USER}@${SERVER_HOST}"
if [[ -n "${SERVER_SSH_KEY_PATH:-}" ]]; then
  SSH_BIN+=( -i "${SERVER_SSH_KEY_PATH}" )
fi
if [[ "${SERVER_IPV6:-0}" == "1" ]]; then
  SSH_BIN=(ssh -6 -p "${SSH_PORT}")
  if [[ -n "${SERVER_SSH_KEY_PATH:-}" ]]; then
    SSH_BIN+=( -i "${SERVER_SSH_KEY_PATH}" )
  fi
fi

"${SSH_BIN[@]}" "${REMOTE_HOST}" "APP_DIR='${SERVER_APP_DIR}' bash -s" <<'REMOTE_SCRIPT'
set -euo pipefail

cd "${APP_DIR}"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader
php bin/init-runtime.php
php bin/preflight.php
php bin/db-doctor.php
chown -R www-data:www-data "${APP_DIR}"
PHP_FPM_SERVICE="$(systemctl list-unit-files --type=service 'php*-fpm.service' --no-legend | awk '{print $1}' | sort -V | tail -n 1)"
if [[ -z "${PHP_FPM_SERVICE}" ]]; then
  echo "PHP-FPM service was not found" >&2
  exit 1
fi
systemctl restart "${PHP_FPM_SERVICE}"
systemctl restart nginx
systemctl restart feedtools-worker.service
curl -fsS "http://127.0.0.1/healthz.php?db=1"
echo
systemctl --no-pager --full status feedtools-worker.service | sed -n '1,18p'
REMOTE_SCRIPT

echo "New server finalized."
