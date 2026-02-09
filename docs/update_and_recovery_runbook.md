# Update & Recovery Runbook (Core)

## Voraussetzungen

- Core liegt unter `/var/www/easywi/core` (oder `APP_CORE_UPDATE_INSTALL_DIR`).
- Schreibrechte für:
  - `core/var`
  - `core/srv`
  - `core/srv/update/jobs`
  - `core/srv/update/logs`
  - `core/srv/update/backups`
- Runner-Binary installiert unter `/usr/local/bin/easywi-core-runner` (siehe unten).
- DB-Konfiguration vorhanden: `/etc/easywi/db.json` + `/etc/easywi/secret.key`.

## Core Update über UI

1. Als Superadmin einloggen.
2. **Admin → Updates** öffnen.
3. "Update installieren" (Core) oder "Update + DB migrieren" starten.
4. Den Job-Status und die Logs im UI prüfen (Tail der letzten 200 Zeilen).
5. Optional: Rollback über "Rollback starten" mit gewünschtem Backup.

**Ausgeschlossene Pfade beim Update:**
- `.env`
- `config/local*`
- `var/`
- `srv/`
- `storage/`
- `uploads/`

## DB Migration über UI

1. **Admin → Updates** öffnen.
2. "Datenbank aktualisieren" starten.
3. Logausgabe prüfen.

## Recovery Mode (DB-Mismatch)

Wenn nach einem manuellen Update die Datenbank nicht passt, wird automatisch die Recovery-Seite angezeigt:

- URL: `/system/recovery/database`
- Hinweis: Zugriff nur für **Superadmin** oder Allowlist-IP.
- Dort kann ein Migrations-Job gestartet werden (CSRF + Rate Limit).

## Plesk Post-Deploy Setup

`core/deploy/post-deploy.sh` in Plesk als Post-Deployment Script hinterlegen:

```bash
#!/usr/bin/env bash
set -euo pipefail
CORE_DIR="/var/www/easywi/core"
${CORE_DIR}/deploy/post-deploy.sh
```

Das Script ist idempotent und führt aus:
- `composer install`
- `cache:clear`
- `doctrine:migrations:migrate`
- `cache:warmup`
- Permissions fix für `var/` & `srv/`

## Runner Installation (Systemd)

Runner aus dem Repo kopieren:

```bash
install -m 0750 core/deploy/easywi-core-runner /usr/local/bin/easywi-core-runner
```

Beispiel `easywi-core-runner.service`:

```ini
[Unit]
Description=EasyWI Core Update Runner
After=network.target

[Service]
Type=oneshot
User=deploy
Group=www-data
Environment=EASYWI_CORE_DIR=/var/www/easywi/core
ExecStart=/usr/local/bin/easywi-core-runner --run-job %i

[Install]
WantedBy=multi-user.target
```

Optionaler Aufruf via sudoers (Webapp → Runner):

```
www-data ALL=(root) NOPASSWD: /usr/local/bin/easywi-core-runner --run-job *
```

## Deploy-Schritte (Core)

```bash
mkdir -p core/srv/update/{jobs,logs,backups}
chown -R www-data:www-data core/srv/update
chmod -R 0770 core/srv/update
```

## Smoke Tests

```bash
curl -s http://localhost/system/health | jq
php bin/console app:diagnose:update -vvv
php bin/console doctrine:migrations:status
```

**Recovery simulieren:**
- Migrationen in der DB auslassen → Seite `/system/recovery/database` wird angezeigt.

## Troubleshooting

- **Job startet nicht:** Prüfe `APP_CORE_UPDATE_RUNNER` und sudoers.
- **Runner-Lock aktiv:** `core/srv/update/lock` prüfen.
- **DB nicht erreichbar:** Prüfe `/etc/easywi/db.json` und `/etc/easywi/secret.key`.
- **Logs:** `core/srv/update/logs/<job>.log` oder `journalctl -u easywi-core-runner.service`.
