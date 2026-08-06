# UpCloud Migration Runbook

Target server:

- UpCloud Cloud Server
- Location: Helsinki
- OS: Ubuntu 24.04 LTS
- Size: 4 GB RAM / 1 CPU
- Public IPv4 and IPv6 enabled

## Before creating the server

Make sure the UpCloud SSH key is the public key from:

```bash
deploy/local/id_ed25519.pub
```

Refresh the private local copy of the production environment:

```bash
bin/fetch-production-env.sh
```

Create a fresh pre-migration export from the current production server:

```bash
bin/export-production-artifacts.sh
```

This creates fresh files in:

```bash
deploy/local/artifacts/
```

## After UpCloud gives an IPv4

Create the local target file:

```bash
cp deploy/local/deploy-target-upcloud.env.example deploy/local/deploy-target-upcloud.env
```

Edit `deploy/local/deploy-target-upcloud.env`:

```env
SERVER_HOST=<UPCloud IPv4>
SERVER_HOST_IPV4=<UPCloud IPv4>
SERVER_HOST_IPV6=<UPCloud IPv6 if available>
SERVER_NAME=_
SERVER_APP_BASE_URL=http://<UPCloud IPv4>
```

Check the IP before migrating:

```bash
IP=<UPCloud IPv4>
curl -s "https://ipinfo.io/${IP}/json"
curl -s "http://ip-api.com/json/${IP}?fields=status,country,countryCode,city,isp,org,as,proxy,hosting,query"
curl -s "https://ifconfig.co/json?ip=${IP}"
curl -s "https://stat.ripe.net/data/geoloc/data.json?resource=${IP}"
```

Bootstrap the new server:

```bash
TARGET_FILE=deploy/local/deploy-target-upcloud.env bin/bootstrap-new-server.sh
```

Upload code:

```bash
TARGET_FILE=deploy/local/deploy-target-upcloud.env bin/deploy-rsync.sh
```

Upload the current production `.env`:

```bash
TARGET_FILE=deploy/local/deploy-target-upcloud.env bin/upload-production-env.sh
```

Patch server-specific `.env` values:

```bash
TARGET_FILE=deploy/local/deploy-target-upcloud.env bin/patch-server-env.sh
```

Install nginx, PHP, and systemd config:

```bash
TARGET_FILE=deploy/local/deploy-target-upcloud.env bin/install-server-config.sh
```

Upload migration artifacts:

```bash
TARGET_FILE=deploy/local/deploy-target-upcloud.env bin/upload-migration-artifacts.sh
```

Restore DB and runtime storage:

```bash
TARGET_FILE=deploy/local/deploy-target-upcloud.env bin/restore-on-server.sh
```

Finalize and start services:

```bash
TARGET_FILE=deploy/local/deploy-target-upcloud.env bin/finalize-new-server.sh
```

Run smoke checks:

```bash
TARGET_FILE=deploy/local/deploy-target-upcloud.env bin/smoke-test-server.sh
```

## Final cutover

When the smoke test is clean:

1. Put the old app into a short maintenance window.
2. Run one final fresh export:

```bash
bin/export-production-artifacts.sh
```

3. Upload and restore the new latest artifacts:

```bash
TARGET_FILE=deploy/local/deploy-target-upcloud.env bin/upload-migration-artifacts.sh
TARGET_FILE=deploy/local/deploy-target-upcloud.env bin/restore-on-server.sh
TARGET_FILE=deploy/local/deploy-target-upcloud.env bin/finalize-new-server.sh
TARGET_FILE=deploy/local/deploy-target-upcloud.env bin/smoke-test-server.sh
```

4. Point DNS or the public entry point to the UpCloud IPv4.
5. Keep the old server untouched until the new server has run cleanly for at least a day.
