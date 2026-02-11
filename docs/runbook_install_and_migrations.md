# Runbook: Installation, Environment, and Migrations (Symfony Core)

This runbook documents the minimum steps to install, configure, and operate the Symfony 8 core, including secure DB config and migrations.

## 1) Prerequisites

- PHP 8.4+ with extensions: `pdo_mysql`, `json`, `mbstring`, `intl` (optional: `pdo_sqlite` for installer fallback).
- A writable filesystem for the web server user (`www-data` by default).
- A DB server reachable from the Core host.

## 2) Directory permissions (required)

Create and secure the directories used by the installer and runtime:

```bash
sudo mkdir -p /opt/easywi/core/var
sudo mkdir -p /opt/easywi/core/var/cache
sudo mkdir -p /opt/easywi/core/srv/setup/state
sudo chown -R www-data:www-data /opt/easywi/core/var /opt/easywi/core/srv
sudo chmod -R 775 /opt/easywi/core/var /opt/easywi/core/srv
```

Paths that must be writable:
- `core/var/`
- `core/var/cache/`
- `core/srv/setup/`
- `core/srv/setup/state/`

## 3) Secure config files (required)

The core uses encrypted files in `/etc/easywi` to store DB credentials.

### 3.1 Encryption key

```bash
sudo mkdir -p /etc/easywi
sudo openssl rand -base64 32 | tr -d '\n' | sudo tee /etc/easywi/secret.key >/dev/null
sudo chown root:root /etc/easywi/secret.key
sudo chmod 600 /etc/easywi/secret.key
```

### 3.2 Bootstrap DB config

The installer writes an encrypted bootstrap config to:

- `/etc/easywi/bootstrap-db.json`
- (fallback) `/etc/easywi/db.json`

These files are used to set `DATABASE_URL` at runtime and to unlock migrations.

## 4) PHP-FPM environment (required for production)

Ensure FPM passes environment variables and defines the application env/debug flags:

**`/etc/php/8.4/fpm/pool.d/www.conf` (example)**

```ini
; Ensure env is not cleared
clear_env = no

; Set Symfony env for production
env[APP_ENV] = prod
env[APP_DEBUG] = 0
```

Reload FPM after changes:

```bash
sudo systemctl reload php8.4-fpm
```

> Note: exporting `APP_ENV`/`APP_DEBUG` in your shell does **not** affect PHP-FPM requests. Always set them in the FPM pool (or container) environment.

## 5) Linting (YAML tags)

The config uses custom YAML tags (e.g. `!tagged_iterator`). Run YAML linting with tag parsing enabled:

```bash
php bin/console lint:yaml config --parse-tags
```

## 5) Install & migrate

From the `core/` directory:

```bash
composer install --no-interaction
php bin/console app:diagnose:config
php bin/console app:diagnose:db
php bin/console doctrine:migrations:status
php bin/console doctrine:migrations:migrate
```

## 6) Smoke tests (required)

Run **exactly** these checks after setup:

```bash
php bin/console about
php bin/console app:diagnose:db -vvv
php bin/console doctrine:migrations:status
php bin/console doctrine:migrations:migrate -vvv --no-interaction
curl -s http://<host>/system/health | jq
```

Expected JSON keys from `/system/health`:
- `app_env`
- `app_debug`
- `setup_locked`
- `setup_state_dir`
- `encryption_key`
- `bootstrap_config`
- `database_config`
- `sftp_config`

## 7) Health endpoint

Check the system status:

```bash
curl -s http://<host>/system/health | jq
```

This shows:
- `app_env`, `app_debug`
- `setup_state_dir` (path + writable)
- encryption key readability
- bootstrap config existence
- database config status

## 8) Troubleshooting

- **`DATABASE_URL is not set`**: verify `/etc/easywi/bootstrap-db.json` and `/etc/easywi/secret.key` permissions.
- **`Database config could not be decrypted`**: key mismatch or invalid JSON.
- **Installer requirements fail**: confirm the `core/srv/setup/state` directory is writable for the web server user.
- **Prod running in dev/debug**: ensure `APP_ENV`/`APP_DEBUG` are set in FPM (not only in your shell).

## 9) Recommended checks

```bash
php -v
php bin/console about
php bin/console lint:yaml config --parse-tags
php bin/console lint:container
php bin/console debug:router
```

## 10) Localhost vs 127.0.0.1 (DB host)

MySQL can resolve `localhost` via a UNIX socket, while `127.0.0.1` forces TCP. The installer defaults to `127.0.0.1` for consistency and will try `localhost` as a fallback if connection tests fail. Use `127.0.0.1` unless you explicitly require socket-based connections.

## CMS Themes, Module Toggles und Rollen (Step 11 Hardening)

### Theme wechseln
- Admin-Bereich: `Admin -> CMS Settings`.
- `active_theme` unterstützt: `esports`, `minimal`, `fantasy`.
- Fallback-Reihenfolge:
  1. `cms_site_settings.active_theme`
  2. `sites.cms_template_key` (Legacy)
  3. `minimal`
- Vorschau ohne Umschalten der Live-Settings: `/preview/{theme}` (z. B. `/preview/minimal`).

### Module-Toggles
- Schalter in CMS-Settings:
  - `blog`
  - `events`
  - `team`
  - `forum`
- Wenn deaktiviert:
  - Theme-Navigation blendet den Link aus.
  - Öffentliche Route liefert weiterhin `404`.

### Rollenmodell (Kurzüberblick)
- `ROLE_ADMIN`:
  - Zugriff auf Admin-CMS inkl. Einstellungen, Forum-Moderation, Theme-Settings.
- `ROLE_MEMBER`:
  - Zugriff auf Public-Forum (lesen/schreiben, sofern Modul aktiv).
- `ROLE_CUSTOMER`:
  - Standard-Customer ohne automatischen Forumzugriff (außer zusätzlich `ROLE_MEMBER`).

### Test-Hinweis
Für lokale Regressionstests (Theme/Toggle/Forum) im Test-Env:

```bash
cd core
APP_ENV=test vendor/bin/phpunit \
  tests/Controller/Phase11RegressionSmokeTest.php \
  tests/Controller/PublicCmsBlogToggleTest.php \
  tests/Controller/PublicCmsEventsTeamToggleTest.php \
  tests/Controller/PublicForumToggleAccessTest.php \
  tests/Controller/ForumPostFlowSmokeTest.php \
  tests/Controller/ThemePreviewRouteTest.php \
  tests/Service/ThemeResolverTest.php
```
