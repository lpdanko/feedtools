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
: "${SERVER_MIGRATION_DIR:?}"

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

"${SSH_CMD[@]}" "${REMOTE_HOST}" "
set -e
cd '${SERVER_MIGRATION_DIR}'
TARGET_DB=\$(sed -n 's/^DB_NAME=//p' '${SERVER_APP_DIR}/.env' | tail -n 1)
if [ -z \"\$TARGET_DB\" ]; then
  echo 'DB_NAME is missing in .env' >&2
  exit 1
fi
FULL_DUMP=\$(ls -1t \"\${TARGET_DB}\"_*_full.sql.gz 2>/dev/null | head -n 1 || true)
if [ -z \"\$FULL_DUMP\" ]; then
  FULL_DUMP=\$(ls -1t *_full.sql.gz | head -n 1)
fi
RUNTIME_TAR=\$(ls -1t storage_*.tar.gz | head -n 1)
echo \"Restoring DB dump: \$FULL_DUMP\"
echo \"Restoring runtime archive: \$RUNTIME_TAR\"
mysql < '${SERVER_MIGRATION_DIR}/mysql-init.sql'
gunzip -c \"\$FULL_DUMP\" | sed '/^CREATE DATABASE /d;/^USE /d' | mysql \"\$TARGET_DB\"
mkdir -p '${SERVER_APP_DIR}'
rm -rf '${SERVER_APP_DIR}/storage/uploads' '${SERVER_APP_DIR}/storage/outputs' '${SERVER_APP_DIR}/storage/reports' '${SERVER_APP_DIR}/storage/taxonomies'
tar -xzf \"\$RUNTIME_TAR\" -C '${SERVER_APP_DIR}'
chown -R www-data:www-data '${SERVER_APP_DIR}/storage'
echo 'Restore completed'
"
