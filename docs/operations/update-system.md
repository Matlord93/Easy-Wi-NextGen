# Update-System (Core)

## Aktivierung

Das Panel aktualisiert das tatsächlich laufende Symfony-Projekt (`kernel.project_dir`). Bei einer direkten Installation werden die Dateien dort mit `rsync --checksum` ersetzt. Dadurch werden auch Änderungen übernommen, deren Dateigröße und Zeitstempel identisch geblieben sind. Ein unbenutzter `current`-Link wird nicht angelegt und beeinflusst weder Versionsanzeige noch Migrationen.

Nur wenn die Anwendung bereits über den konfigurierten `current`-Link läuft, wird ein neues Release vorbereitet und der Link atomar ersetzt. Laufzeitdaten (`var`, `srv`, `storage`, `uploads`, `public/uploads`) werden dabei gemeinsam genutzt. Bereits laufende Update-Ticks können ihren Jobstatus dadurch weiterhin schreiben.

Nach Cache-Aufbereitung und Migrationen wird der OPcache des ausführenden Webprozesses zurückgesetzt. Bei PHP-Preloading ist ein Reload-Agent auf dem Panel-Host erforderlich. CLI-Updates benötigen ebenfalls einen konfigurierten Reload-Agent, da der CLI-Prozess den OPcache des Webservers nicht zurücksetzen kann.

Ein angeforderter Agent-Reload bleibt als ausstehender Schritt sichtbar. Fehlgeschlagene, verschwundene oder abgelaufene Reload-Jobs führen zu einem fehlgeschlagenen Update. Der Agent lädt alle aktiven PHP-FPM-Dienste beziehungsweise Apache und gegebenenfalls nginx neu; bei einem gescheiterten Reload versucht er einen Neustart.

Nach erfolgreichem Abschluss lädt die Browseransicht einmal neu. Lokale CSS- und JavaScript-Assets erhalten eine Inhaltsversion, damit Browser neue Dateien laden.

## Daten und Wiederherstellung

Lokale Umgebungsdateien, lokale Konfiguration und Laufzeitdaten bleiben beim Austausch und beim dateibasierten Rollback erhalten. Ein fehlgeschlagener Deployment-Check stellt den vorherigen Code beziehungsweise Release-Link wieder her. Datenbankmigrationen werden nicht automatisch zurückgerollt; vor Updates bleibt ein Datenbankbackup erforderlich.

Jobs und Logs liegen in den konfigurierten Update-Verzeichnissen. Das Panel zeigt den aktuellen Schritt und einen eventuellen Reload-Fehler an. Vor der ersten Verwendung muss diese Korrektur über den regulären Installationsweg eingespielt werden: Ein bereits laufender alter Updater kann seine eigene fehlerhafte Aktivierungslogik nicht rückwirkend ersetzen.

## Release-Kanäle

Core- und Agent-Updates unterscheiden `stable`, `beta` und `dev`. Releases können in den Release Notes `easywi-channel: stable|beta|dev` angeben. Ohne Marker werden die GitHub-Pre-release-Einstellung und das Tag-Suffix verwendet. `alpha` bleibt als Alias für `dev` lesbar.
