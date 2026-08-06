#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
TARGET_FILE="${ROOT_DIR}/deploy/local/deploy-target-stage.env"
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
else
  REMOTE="${SERVER_USER}@${SERVER_HOST}:${SERVER_APP_DIR}/"
fi

mkdir -p "${ROOT_DIR}/storage/logs"

rsync -az --progress \
  --exclude-from="${EXCLUDE_FILE}" \
  -e "${SSH_BIN[*]}" \
  "${ROOT_DIR}/" "${REMOTE}"

echo "Stage code synced to ${REMOTE}"
