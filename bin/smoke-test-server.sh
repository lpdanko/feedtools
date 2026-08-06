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

echo "== outbound IPv4 =="
curl -4 -s --max-time 12 https://ipinfo.io/json || true
echo
curl -4 -s --max-time 12 https://ifconfig.co/json || true
echo

echo "== outbound IPv6 =="
curl -6 -s --max-time 12 https://ipinfo.io/json || true
echo

echo "== app health =="
curl -fsS "http://127.0.0.1/healthz.php?db=1"
echo

cd "${APP_DIR}"

env_get() {
  local key="$1"
  awk -F= -v k="${key}" '$1 == k {
    sub(/^[^=]*=/, "")
    sub(/\r$/, "")
    print
    exit
  }' ./.env
}

GEMINI_API_KEY="$(env_get GEMINI_API_KEY)"

echo "== php checks =="
php bin/preflight.php
php bin/db-doctor.php

if [[ -n "${GEMINI_API_KEY:-}" ]]; then
  echo "== gemini IPv4 direct =="
  G4="$(curl -4 -sS --max-time 30 "https://generativelanguage.googleapis.com/v1beta/models?key=${GEMINI_API_KEY}" || true)"
  if grep -q '"models"' <<<"${G4}"; then
    echo "Gemini IPv4: OK"
  else
    echo "Gemini IPv4: FAILED"
    head -c 800 <<<"${G4}"
    echo
  fi

  echo "== gemini app client =="
  php -r 'require "app/llm/LLM.php"; $cfg=require "app/config.php"; $c=LLM::client($cfg,"gemini-2.5-flash"); $r=$c->generateText("gemini-2.5-flash","Return only OK",null,["_feedtools_no_response_cache"=>true]); echo trim((string)($r["output_text"] ?? "")), PHP_EOL;'
fi

echo "== services =="
PHP_FPM_SERVICE="$(systemctl list-unit-files --type=service 'php*-fpm.service' --no-legend | awk '{print $1}' | sort -V | tail -n 1)"
systemctl is-active nginx "${PHP_FPM_SERVICE}" mariadb feedtools-worker.service
REMOTE_SCRIPT
