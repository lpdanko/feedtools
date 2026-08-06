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
: "${SERVER_ENV_PATH:?}"

SERVER_NAME="${SERVER_NAME:-_}"
SERVER_APP_BASE_URL="${SERVER_APP_BASE_URL:-}"
SERVER_GEMINI_IP_RESOLVE="${SERVER_GEMINI_IP_RESOLVE:-}"

if [[ -z "${SERVER_APP_BASE_URL}" ]]; then
  if [[ "${SERVER_NAME}" != "_" && -n "${SERVER_NAME}" ]]; then
    SERVER_APP_BASE_URL="https://${SERVER_NAME}"
  else
    SERVER_APP_BASE_URL="http://${SERVER_HOST}"
  fi
fi

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

"${SSH_BIN[@]}" "${REMOTE_HOST}" \
  "ENV_FILE='${SERVER_ENV_PATH}' APP_BASE_URL_VALUE='${SERVER_APP_BASE_URL}' GEMINI_IP_RESOLVE_VALUE='${SERVER_GEMINI_IP_RESOLVE}' bash -s" <<'REMOTE_SCRIPT'
set -euo pipefail

set_env_key() {
  local key="$1"
  local value="$2"
  if grep -q "^${key}=" "${ENV_FILE}"; then
    sed -i "s#^${key}=.*#${key}=${value}#" "${ENV_FILE}"
  else
    printf '\n%s=%s\n' "${key}" "${value}" >> "${ENV_FILE}"
  fi
}

cp "${ENV_FILE}" "${ENV_FILE}.bak.$(date +%Y%m%d%H%M%S)"
set_env_key APP_BASE_URL "${APP_BASE_URL_VALUE}"

if [[ -n "${GEMINI_IP_RESOLVE_VALUE}" ]]; then
  set_env_key GEMINI_IP_RESOLVE "${GEMINI_IP_RESOLVE_VALUE}"
fi

grep -E '^(APP_BASE_URL|GEMINI_IP_RESOLVE)=' "${ENV_FILE}" || true
REMOTE_SCRIPT

echo "Server .env patched."
