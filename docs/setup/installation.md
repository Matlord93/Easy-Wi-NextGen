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

## Installer: Panel-Domain und Let's Encrypt

Der Linux-Menüinstaller fragt bei der Panel-Installation nach der Panel-Domain (`EASYWI_WEB_HOSTNAME`) und kann anschließend direkt im Installer ein Let's-Encrypt-Zertifikat ausstellen (`EASYWI_SETUP_SSL=true`, `EASYWI_SSL_EMAIL=<adresse>`). Dabei installiert der Installer Certbot inklusive Nginx-Plugin, prüft vorab die A-Records der Domain gegen die lokalen Server-IP-Adressen und führt Certbot mit `--nginx`, `--keep-until-expiring` und `--redirect` aus. So wird die Domain bereits bei der Installation in Nginx und `DEFAULT_URI` hinterlegt und muss nicht nachträglich im Webinterface für die Panel-Erstkonfiguration gesetzt werden.

Für bereits installierte Panels gibt es im Linux-Menüinstaller den Punkt **„Panel-SSL nachträglich einrichten“**. Dieser fragt Installationsverzeichnis, Panel-Domain und Let's-Encrypt-E-Mail ab, aktualisiert die EasyWI-Nginx-Konfiguration, startet Certbot und schreibt `DEFAULT_URI=https://<domain>` in `core/.env.local`.

Vor dem Start muss der DNS-A-Record der Domain auf den Zielserver zeigen und Port 80/443 müssen von Let's Encrypt erreichbar sein. Falls Certbot meldet, dass das Nginx-Plugin fehlt, prüft der Installer zusätzlich, ob ein anderer Certbot aus Snap/pip im `PATH` vor `/usr/bin/certbot` liegt.
