#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
TARGET_FILE="${TARGET_FILE:-${ROOT_DIR}/deploy/local/deploy-target.env}"
ARTIFACTS_DIR="${ARTIFACTS_DIR:-${ROOT_DIR}/deploy/local/artifacts}"
MYSQL_INIT_FILE="${MYSQL_INIT_FILE:-${ROOT_DIR}/deploy/local/mysql-init.sql}"

if [[ ! -f "${TARGET_FILE}" ]]; then
  echo "Missing ${TARGET_FILE}" >&2
  exit 1
fi

if [[ ! -d "${ARTIFACTS_DIR}" ]]; then
  echo "Missing ${ARTIFACTS_DIR}" >&2
  exit 1
fi

if [[ ! -f "${MYSQL_INIT_FILE}" ]]; then
  echo "Missing ${MYSQL_INIT_FILE}" >&2
  exit 1
fi

# shellcheck disable=SC1090
source "${TARGET_FILE}"

: "${SERVER_HOST:?}"
: "${SERVER_USER:?}"
: "${SERVER_MIGRATION_DIR:?}"

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
  REMOTE="${SERVER_USER}@[${SERVER_HOST}]:${SERVER_MIGRATION_DIR}/"
else
  REMOTE="${SERVER_USER}@${SERVER_HOST}:${SERVER_MIGRATION_DIR}/"
fi

"${SSH_BIN[@]}" "${SERVER_USER}@${SERVER_HOST}" "mkdir -p '${SERVER_MIGRATION_DIR}'"

scp_args=(-P "${SSH_PORT}")
if [[ -n "${SERVER_SSH_KEY_PATH:-}" ]]; then
  scp_args+=( -i "${SERVER_SSH_KEY_PATH}" )
fi
if [[ "${SERVER_IPV6:-0}" == "1" ]]; then
  scp_args=(-6 -P "${SSH_PORT}")
  if [[ -n "${SERVER_SSH_KEY_PATH:-}" ]]; then
    scp_args+=( -i "${SERVER_SSH_KEY_PATH}" )
  fi
fi

scp "${scp_args[@]}" "${ARTIFACTS_DIR}"/* "${REMOTE}"
scp "${scp_args[@]}" "${MYSQL_INIT_FILE}" "${REMOTE}"

echo "Migration artifacts uploaded to ${REMOTE}"
