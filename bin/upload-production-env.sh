#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
TARGET_FILE="${TARGET_FILE:-${ROOT_DIR}/deploy/local/deploy-target.env}"
ENV_FILE="${ENV_FILE:-${ROOT_DIR}/deploy/local/production.env}"

if [[ ! -f "${TARGET_FILE}" ]]; then
  echo "Missing ${TARGET_FILE}" >&2
  exit 1
fi

if [[ ! -f "${ENV_FILE}" ]]; then
  echo "Missing ${ENV_FILE}" >&2
  exit 1
fi

# shellcheck disable=SC1090
source "${TARGET_FILE}"

: "${SERVER_HOST:?}"
: "${SERVER_USER:?}"
: "${SERVER_ENV_PATH:?}"

SSH_PORT="${SERVER_SSH_PORT:-22}"
scp_args=(-P "${SSH_PORT}")
if [[ -n "${SERVER_SSH_KEY_PATH:-}" ]]; then
  scp_args+=( -i "${SERVER_SSH_KEY_PATH}" )
fi
if [[ "${SERVER_IPV6:-0}" == "1" ]]; then
  scp_args=(-6 -P "${SSH_PORT}")
  if [[ -n "${SERVER_SSH_KEY_PATH:-}" ]]; then
    scp_args+=( -i "${SERVER_SSH_KEY_PATH}" )
  fi
  scp "${scp_args[@]}" "${ENV_FILE}" "${SERVER_USER}@[${SERVER_HOST}]:${SERVER_ENV_PATH}"
else
  scp "${scp_args[@]}" "${ENV_FILE}" "${SERVER_USER}@${SERVER_HOST}:${SERVER_ENV_PATH}"
fi

echo ".env uploaded to ${SERVER_ENV_PATH}"
