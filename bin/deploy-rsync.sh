#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
TARGET_FILE="${TARGET_FILE:-${ROOT_DIR}/deploy/local/deploy-target.env}"
EXCLUDE_FILE="${ROOT_DIR}/deploy/rsync-exclude.txt"

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
if [[ -n "${SERVER_SSH_KEY_PATH:-}" ]]; then
  SSH_BIN+=( -i "${SERVER_SSH_KEY_PATH}" )
fi
if [[ "${SERVER_IPV6:-0}" == "1" ]]; then
  SSH_BIN=(ssh -6 -p "${SSH_PORT}")
  if [[ -n "${SERVER_SSH_KEY_PATH:-}" ]]; then
    SSH_BIN+=( -i "${SERVER_SSH_KEY_PATH}" )
  fi
  REMOTE="${SERVER_USER}@[${SERVER_HOST}]:${SERVER_APP_DIR}/"
  SSH_TARGET="${SERVER_USER}@${SERVER_HOST}"
else
  REMOTE="${SERVER_USER}@${SERVER_HOST}:${SERVER_APP_DIR}/"
  SSH_TARGET="${SERVER_USER}@${SERVER_HOST}"
fi

mkdir -p "${ROOT_DIR}/storage/logs"

rsync -az --progress \
  --exclude-from="${EXCLUDE_FILE}" \
  -e "${SSH_BIN[*]}" \
  "${ROOT_DIR}/" "${REMOTE}"

echo "Code synced to ${REMOTE}"

"${SSH_BIN[@]}" "${SSH_TARGET}" "cd '${SERVER_APP_DIR}' && php bin/init-runtime.php >/dev/null && chown -R www-data:www-data storage/reports"
echo "Runtime report storage initialized"

if [[ "${SERVER_RELOAD_SERVICES:-1}" == "1" ]]; then
  "${SSH_BIN[@]}" "${SSH_TARGET}" 'bash -s' <<'REMOTE_SCRIPT'
set -euo pipefail

if systemctl list-unit-files --type=service feedtools-worker.service --no-legend >/dev/null 2>&1; then
  if systemctl is-enabled feedtools-worker.service >/dev/null 2>&1 || systemctl is-active feedtools-worker.service >/dev/null 2>&1; then
    RUNNING_OPS="0"
    if [[ -d /var/www/feedtools ]]; then
      RUNNING_OPS="$(cd /var/www/feedtools && php -r 'require "app/config.php"; require "app/db.php"; echo (int)db()->query("SELECT COUNT(*) FROM feedtools_operations WHERE status=\"running\"")->fetchColumn();' 2>/dev/null || echo 0)"
    fi
    if [[ "${RUNNING_OPS}" == "0" ]]; then
      systemctl restart feedtools-worker.service
      echo "Restarted feedtools-worker.service"
    else
      echo "Skipped feedtools-worker.service restart: ${RUNNING_OPS} operation(s) running"
    fi
  fi
fi

PHP_FPM_SERVICE="$(systemctl list-unit-files --type=service 'php*-fpm.service' --no-legend 2>/dev/null | awk '{print $1}' | sort -V | tail -n 1 || true)"
if [[ -n "${PHP_FPM_SERVICE}" ]] && systemctl is-active "${PHP_FPM_SERVICE}" >/dev/null 2>&1; then
  systemctl reload "${PHP_FPM_SERVICE}" || systemctl restart "${PHP_FPM_SERVICE}"
  echo "Reloaded ${PHP_FPM_SERVICE}"
fi
REMOTE_SCRIPT
fi
