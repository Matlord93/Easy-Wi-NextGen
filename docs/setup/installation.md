# Installation / Update Pakete

## Neuinstallation
- Verwende **full**-Pakete (`core-full-*.zip` oder `.tar.gz`), da `vendor/` enthalten ist.

## Updates
- Verwende **novendor**-Pakete (`core-novendor-*`).
- Nach Entpacken: `composer install --no-dev` (wird im Runner automatisch ausgeführt).

## Panel-Test (lokal)
1. `APP_CORE_UPDATE_MANIFEST_URL` auf `feed.json` setzen.
2. Im Panel unter **Admin → Updates** Job `update` oder `both` starten.
3. Logs in `srv/update/logs/<job>.log` prüfen.
