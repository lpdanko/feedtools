#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
TARGET_FILE="${TARGET_FILE:-${ROOT_DIR}/deploy/local/deploy-target-upcloud.env}"
BOOTSTRAP_FILE="${ROOT_DIR}/deploy/server/bootstrap-ubuntu.sh"

if [[ ! -f "${TARGET_FILE}" ]]; then
  echo "Missing ${TARGET_FILE}" >&2
  exit 1
fi
if [[ ! -f "${BOOTSTRAP_FILE}" ]]; then
  echo "Missing ${BOOTSTRAP_FILE}" >&2
  exit 1
fi

# shellcheck disable=SC1090
source "${TARGET_FILE}"

: "${SERVER_HOST:?}"
: "${SERVER_USER:?}"

SSH_PORT="${SERVER_SSH_PORT:-22}"
SSH_BIN=(ssh -p "${SSH_PORT}")
scp_args=(-P "${SSH_PORT}")
REMOTE="${SERVER_USER}@${SERVER_HOST}"
REMOTE_SSH="${SERVER_USER}@${SERVER_HOST}"
if [[ -n "${SERVER_SSH_KEY_PATH:-}" ]]; then
  SSH_BIN+=( -i "${SERVER_SSH_KEY_PATH}" )
  scp_args+=( -i "${SERVER_SSH_KEY_PATH}" )
fi
if [[ "${SERVER_IPV6:-0}" == "1" ]]; then
  SSH_BIN=(ssh -6 -p "${SSH_PORT}")
  scp_args=(-6 -P "${SSH_PORT}")
  if [[ -n "${SERVER_SSH_KEY_PATH:-}" ]]; then
    SSH_BIN+=( -i "${SERVER_SSH_KEY_PATH}" )
    scp_args+=( -i "${SERVER_SSH_KEY_PATH}" )
  fi
  REMOTE="${SERVER_USER}@[${SERVER_HOST}]"
  REMOTE_SSH="${SERVER_USER}@${SERVER_HOST}"
fi

scp "${scp_args[@]}" "${BOOTSTRAP_FILE}" "${REMOTE}:/root/feedtools-bootstrap-ubuntu.sh"
"${SSH_BIN[@]}" "${REMOTE_SSH}" "bash /root/feedtools-bootstrap-ubuntu.sh"

echo "New server bootstrap completed."
