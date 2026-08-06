#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "Run as root" >&2
  exit 1
fi

export DEBIAN_FRONTEND=noninteractive

if [[ -f /etc/apt/sources.list.d/ubuntu.sources ]]; then
  sed -i 's#URIs: http://archive.ubuntu.com/ubuntu/#URIs: http://fi.archive.ubuntu.com/ubuntu/#g' /etc/apt/sources.list.d/ubuntu.sources
  sed -i 's#URIs: http://security.ubuntu.com/ubuntu/#URIs: http://fi.archive.ubuntu.com/ubuntu/#g' /etc/apt/sources.list.d/ubuntu.sources
fi

apt-get update
apt-get install -y \
  nginx \
  mariadb-server \
  php-fpm \
  php-cli \
  php-mysql \
  php-curl \
  php-gd \
  php-xml \
  php-mbstring \
  php-zip \
  php-sqlite3 \
  php-ftp \
  php-intl \
  composer \
  unzip \
  rsync \
  curl \
  ca-certificates \
  certbot \
  python3-certbot-nginx

systemctl enable --now nginx
systemctl enable --now mariadb
PHP_FPM_SERVICE="$(systemctl list-unit-files --type=service 'php*-fpm.service' --no-legend | awk '{print $1}' | sort -V | tail -n 1)"
if [[ -z "${PHP_FPM_SERVICE}" ]]; then
  echo "PHP-FPM service was not found" >&2
  exit 1
fi
systemctl enable --now "${PHP_FPM_SERVICE}"

mkdir -p /var/www/feedtools
mkdir -p /root/feedtools-migration

chown -R www-data:www-data /var/www/feedtools

echo "Bootstrap completed."
echo "Next steps:"
echo "1. Upload code to /var/www/feedtools"
echo "2. Upload .env to /var/www/feedtools/.env"
echo "3. Upload migration artifacts to /root/feedtools-migration"
echo "4. Restore DB and storage"
