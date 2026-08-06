# FeedTools Deployment

## Target stack

- Ubuntu 24.04 LTS
- nginx
- PHP-FPM 8.3+
- MariaDB/MySQL 10.6+
- systemd worker for `bin/worker.php --loop`
- `WORKER_MAX_PARALLEL` controls how many operations may run in parallel
  - recommended production value: `3`
  - the worker still runs at most one operation per dataset
  - background lanes for price sync, marketplace data sync, and supplier feed parsing run separately from dataset operation slots

## Required PHP extensions

- `pdo_mysql`
- `curl`
- `gd`
- `xml`
- `xmlreader`
- `simplexml`
- `dom`
- `mbstring`
- `zip`
- `ftp`
- `fileinfo`
- `sqlite3` (disk-backed cell cache for large Excel exports)

## Deploy steps

1. Copy the project to `/var/www/feedtools`.
   - helper: `bin/deploy-rsync.sh`
   - runtime data helper: `bin/sync-runtime-data.sh`
   - migration artifact helper: `bin/upload-migration-artifacts.sh`
   - server bootstrap helper: `deploy/server/bootstrap-ubuntu.sh`
2. Install Composer dependencies:
   - `composer install --no-dev --optimize-autoloader`
3. Create server config:
   - copy `.env.example` to `.env`
   - fill DB, OpenAI, Ozon and optional remote image settings
   - enable app-level auth with `APP_BASIC_AUTH_ENABLED=1` and configure a username/password or password hash
   - keep `WORKER_AUTO_SPAWN=0` on production when using systemd worker
   - set `WORKER_MAX_PARALLEL=3` to allow three different datasets to run in parallel
   - keep `WORKER_PRICE_TOOL_MAX_PARALLEL=1`, `WORKER_MARKETPLACE_DATA_MAX_PARALLEL=1`, and `WORKER_SUPPLIER_FEED_MAX_PARALLEL=1` unless you explicitly want concurrent background sync jobs
4. Optionally add an extra auth layer at nginx level if you want double protection.
5. Set permissions:
   - `chown -R www-data:www-data /var/www/feedtools`
   - ensure `storage/uploads`, `storage/outputs`, `storage/logs`, `storage/cache` are writable
6. Install nginx config from `deploy/nginx/feedtools.conf.example`.
7. Install PHP overrides from `deploy/php/feedtools.ini`.
8. Install systemd service from `deploy/systemd/feedtools-worker.service`.
9. Run preflight:
   - `composer run preflight`
   - `composer run init-runtime`
   - `composer run db-doctor`
10. Issue TLS certificate:
   - `certbot --nginx -d feedtools.example.com`

## Local private deploy files

- `deploy/local/server-access.md`
- `deploy/local/deploy-target.env`
- `deploy/local/deploy-target-stage.env`
- `deploy/local/production.env`
- `deploy/local/mysql-init.sql`

These files are ignored by git and can hold real server credentials and production secrets.

## Notes

- Safe staging layout:
  - code: `/var/www/feedtools-stage`
  - database: `feedtools_stage`
  - web URL: `http://SERVER_IP:8081`
  - worker: `feedtools-stage-worker.service`
- Stage deploy helper: `bin/deploy-rsync-stage.sh`
- Stage target template: `deploy/local/deploy-target-stage.env.example`
- Web root must be `/var/www/feedtools/public`.
- Do not deploy `app/config.local.php` from a development machine.
- Prefer `.env` on the server. `app/config.local.php` should be reserved for local overrides only.
- Runtime files in `storage/` should be backed up separately from code.
- The project currently expects an existing database schema. If this is a fresh server, migrate or restore the database before opening the app.
- Health endpoint is available at `/healthz.php` and can optionally check DB with `/healthz.php?db=1`.
- You can generate a password hash for app-level auth with `php bin/make-password-hash.php 'your-password'`.
