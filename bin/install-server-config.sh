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

SERVER_NAME="${SERVER_NAME:-_}"
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

scp "${scp_args[@]}" \
  "${ROOT_DIR}/deploy/nginx/feedtools.conf.example" \
  "${ROOT_DIR}/deploy/php/feedtools.ini" \
  "${ROOT_DIR}/deploy/systemd/feedtools-worker.service" \
  "${REMOTE}:/tmp/"

"${SSH_BIN[@]}" "${REMOTE_SSH}" "APP_DIR='${SERVER_APP_DIR}' SERVER_NAME='${SERVER_NAME}' bash -s" <<'REMOTE_SCRIPT'
set -euo pipefail

sed -i "s#server_name .*;#server_name ${SERVER_NAME};#" /tmp/feedtools.conf.example
sed -i "s#/var/www/feedtools#${APP_DIR}#g" /tmp/feedtools.conf.example
PHP_FPM_SERVICE="$(systemctl list-unit-files --type=service 'php*-fpm.service' --no-legend | awk '{print $1}' | sort -V | tail -n 1)"
if [[ -z "${PHP_FPM_SERVICE}" ]]; then
  echo "PHP-FPM service was not found" >&2
  exit 1
fi
PHP_VERSION="${PHP_FPM_SERVICE#php}"
PHP_VERSION="${PHP_VERSION%-fpm.service}"
PHP_FPM_SOCKET="/run/php/php${PHP_VERSION}-fpm.sock"
sed -i "s#unix:/run/php/php8\\.3-fpm\\.sock#unix:${PHP_FPM_SOCKET}#g" /tmp/feedtools.conf.example
install -o root -g root -m 0644 /tmp/feedtools.conf.example /etc/nginx/sites-available/feedtools
ln -sf /etc/nginx/sites-available/feedtools /etc/nginx/sites-enabled/feedtools
rm -f /etc/nginx/sites-enabled/default

install -d -o root -g root -m 0755 "/etc/php/${PHP_VERSION}/fpm/conf.d"
install -o root -g root -m 0644 /tmp/feedtools.ini "/etc/php/${PHP_VERSION}/fpm/conf.d/99-feedtools.ini"

sed -i "s#/var/www/feedtools#${APP_DIR}#g" /tmp/feedtools-worker.service
install -o root -g root -m 0644 /tmp/feedtools-worker.service /etc/systemd/system/feedtools-worker.service

nginx -t
systemctl daemon-reload
systemctl restart "${PHP_FPM_SERVICE}"
systemctl restart nginx
systemctl enable feedtools-worker.service
REMOTE_SCRIPT

echo "Server nginx/PHP/systemd config installed."
