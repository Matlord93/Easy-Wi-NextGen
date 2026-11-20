# Eigene Website oder eigenes CMS

`public/` ist das vorgesehene Webverzeichnis für eine eigene Website, zum Beispiel
WordPress. Der integrierte CMS-Schalter unter **Administration → CMS → Einstellungen**
deaktiviert die öffentliche Easy-Wi-Website. Das Panel bleibt über seinen bisherigen
Webroot `core/public/` verfügbar; seine Startseite führt dann zum Login bzw. in den persönlichen Panelbereich.

Für eine eigene Website einen getrennten VirtualHost einrichten:

- `www.example.com`: DocumentRoot `/var/www/easywi/core/website/public`
- `panel.example.com`: DocumentRoot `/var/www/easywi/core/public`

Den Panel-Host in der Site-/Domainverwaltung und den Zahlungs-Callback-URLs verwenden.
Der eigene CMS-Webroot wird separat durch dessen Installer befüllt. Easy-Wi bindet
keinen fremden PHP-Einstiegspunkt in seinen Frontcontroller ein.

Das gesamte Verzeichnis `website/` ist für Voll-, Delta- und Symlink-Updates als
Laufzeitinhalt geschützt. Eigene Dateien gehören zusätzlich in die regulären
Backups; Datenbank und Aktualisierung eines eigenen CMS werden von diesem verwaltet.

Bei Release-Installationen einen stabilen `current`-Pfad oder das gemeinsame
Verzeichnis `shared/website/public` als Webroot verwenden. Ein nummeriertes
Release-Verzeichnis kann später durch die Update-Aufbewahrung entfernt werden.
