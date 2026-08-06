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
SSH_BIN=(ssh -p "${SSH_PORT}")
RSYNC_RSH=(ssh -p "${SSH_PORT}")
REMOTE_HOST="${SERVER_USER}@${SERVER_HOST}"
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
  REMOTE_UPLOADS="${SERVER_USER}@[${SERVER_HOST}]:${SERVER_APP_DIR}/storage/uploads/"
  REMOTE_OUTPUTS="${SERVER_USER}@[${SERVER_HOST}]:${SERVER_APP_DIR}/storage/outputs/"
  REMOTE_REPORTS="${SERVER_USER}@[${SERVER_HOST}]:${SERVER_APP_DIR}/storage/reports/"
  REMOTE_TAXONOMIES="${SERVER_USER}@[${SERVER_HOST}]:${SERVER_APP_DIR}/storage/taxonomies/"
else
  REMOTE_UPLOADS="${SERVER_USER}@${SERVER_HOST}:${SERVER_APP_DIR}/storage/uploads/"
  REMOTE_OUTPUTS="${SERVER_USER}@${SERVER_HOST}:${SERVER_APP_DIR}/storage/outputs/"
  REMOTE_REPORTS="${SERVER_USER}@${SERVER_HOST}:${SERVER_APP_DIR}/storage/reports/"
  REMOTE_TAXONOMIES="${SERVER_USER}@${SERVER_HOST}:${SERVER_APP_DIR}/storage/taxonomies/"
fi

"${SSH_BIN[@]}" "${REMOTE_HOST}" "mkdir -p '${SERVER_APP_DIR}/storage/uploads' '${SERVER_APP_DIR}/storage/outputs' '${SERVER_APP_DIR}/storage/reports' '${SERVER_APP_DIR}/storage/taxonomies'"

rsync -az --progress -e "${RSYNC_RSH[*]}" "${ROOT_DIR}/storage/uploads/" "${REMOTE_UPLOADS}"
rsync -az --progress -e "${RSYNC_RSH[*]}" "${ROOT_DIR}/storage/outputs/" "${REMOTE_OUTPUTS}"
rsync -az --progress -e "${RSYNC_RSH[*]}" "${ROOT_DIR}/storage/reports/" "${REMOTE_REPORTS}"
rsync -az --progress -e "${RSYNC_RSH[*]}" "${ROOT_DIR}/storage/taxonomies/" "${REMOTE_TAXONOMIES}"

echo "Runtime data synced."
