#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
TARGET_FILE="${TARGET_FILE:-${ROOT_DIR}/deploy/local/deploy-target.env}"
ARTIFACTS_DIR="${ARTIFACTS_DIR:-${ROOT_DIR}/deploy/local/artifacts}"

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
SSH_BIN=(ssh -p "${SSH_PORT}")
RSYNC_RSH=(ssh -p "${SSH_PORT}")
REMOTE_HOST="${SERVER_USER}@${SERVER_HOST}"
REMOTE_RSYNC_HOST="${REMOTE_HOST}"
if [[ -n "${SERVER_SSH_KEY_PATH:-}" ]]; then
  SSH_BIN+=( -i "${SERVER_SSH_KEY_PATH}" )
  RSYNC_RSH+=( -i "${SERVER_SSH_KEY_PATH}" )
fi
if [[ "${SERVER_IPV6:-0}" == "1" ]]; then
  SSH_BIN=(ssh -6 -p "${SSH_PORT}")
  RSYNC_RSH=(ssh -6 -p "${SSH_PORT}")
  if [[ -n "${SERVER_SSH_KEY_PATH:-}" ]]; then
    SSH_BIN+=( -i "${SERVER_SSH_KEY_PATH}" )
    RSYNC_RSH+=( -i "${SERVER_SSH_KEY_PATH}" )
  fi
  REMOTE_RSYNC_HOST="${SERVER_USER}@[${SERVER_HOST}]"
fi

mkdir -p "${ARTIFACTS_DIR}"

REMOTE_OUT="$("${SSH_BIN[@]}" "${REMOTE_HOST}" "APP_DIR='${SERVER_APP_DIR}' MIGRATION_DIR='${SERVER_MIGRATION_DIR}' bash -s" <<'REMOTE_SCRIPT'
set -euo pipefail

ENV_FILE="${APP_DIR}/.env"
if [[ ! -f "${ENV_FILE}" ]]; then
  echo "Missing ${ENV_FILE}" >&2
  exit 1
fi

env_get() {
  local key="$1"
  awk -F= -v k="${key}" '$1 == k {
    sub(/^[^=]*=/, "")
    sub(/\r$/, "")
    print
    exit
  }' "${ENV_FILE}"
}

DB_NAME="$(env_get DB_NAME)"
DB_USER="$(env_get DB_USER)"
DB_PASS="$(env_get DB_PASS)"
DB_HOST="$(env_get DB_HOST)"
DB_PORT="$(env_get DB_PORT)"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
if [[ -z "${DB_NAME}" || -z "${DB_USER}" ]]; then
  echo "DB_NAME or DB_USER is missing in ${ENV_FILE}" >&2
  exit 1
fi
TS="$(date +%Y%m%d_%H%M%S)"
OUT="${MIGRATION_DIR}/export_${TS}"
mkdir -p "${OUT}"

DUMP_BIN="$(command -v mariadb-dump || command -v mysqldump || true)"
if [[ -z "${DUMP_BIN}" ]]; then
  echo "mariadb-dump/mysqldump not found" >&2
  exit 1
fi

MYSQL_PWD="${DB_PASS:-}" "${DUMP_BIN}" \
  --single-transaction \
  --quick \
  --routines \
  --triggers \
  --events \
  -h "${DB_HOST}" \
  -P "${DB_PORT}" \
  -u "${DB_USER}" \
  "${DB_NAME}" \
  | gzip -1 > "${OUT}/${DB_NAME}_${TS}_full.sql.gz"

tar -czf "${OUT}/storage_${TS}.tar.gz" \
  -C "${APP_DIR}" \
  storage/uploads \
  storage/outputs \
  storage/reports \
  storage/taxonomies

cp "${ENV_FILE}" "${OUT}/production_${TS}.env"
(cd "${OUT}" && sha256sum ./* > SHA256SUMS)

echo "${OUT}"
REMOTE_SCRIPT
)"

rsync -az --progress -e "${RSYNC_RSH[*]}" "${REMOTE_RSYNC_HOST}:${REMOTE_OUT}/" "${ARTIFACTS_DIR}/"
chmod 600 "${ARTIFACTS_DIR}"/production_*.env 2>/dev/null || true

echo "Artifacts exported to ${ARTIFACTS_DIR}"
echo "Remote export directory: ${REMOTE_OUT}"
