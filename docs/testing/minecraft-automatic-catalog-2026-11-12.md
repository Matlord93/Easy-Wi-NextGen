# Automatischer Minecraft-Versionskatalog – 12.11.2026

Die Minecraft-Verwaltung benötigt keine manuellen Versionsdatensätze, JSON-Uploads
oder initialen Import mehr. Der erste Zugriff bereitet fehlende Minecraft-Vorlagen
und einen mitgelieferten Startkatalog vor. Offizielle Anbieter werden im Hintergrund
abgerufen; offene Auswahllisten erhalten neue Versionen automatisch. Spigot und
BungeeCord werden zuerst abgefragt, weil sie keinen mitgelieferten Startkatalog
haben. Bestehende Instanzen werden durch eine Katalogaktualisierung nicht geändert.

## Nachweise

- PHP-Integration und Regression: **87 Tests, 355 Assertions**, keine Fehler oder
  fehlgeschlagenen Assertions. PHPUnit meldet 12 Hinweise in den bestehenden Tests.
  Abgedeckt sind die automatische Ersteinrichtung, alle fünf Servertypen, stabile
  Versionen, erhaltene Anpassungen und deaktivierte Einträge, fehlerhafte Quellen,
  die geschützte API, auslaufende manuelle URLs, Neuinstallation, Installation und
  Scheduler-Verträge.
- Eine frische, isolierte Datenbank erhält **940 Katalogeinträge** aus den drei
  mitgelieferten Quellen, ohne Netzwerkanfrage oder manuellen Einrichtungsschritt.
- Ein Prozess-Test startet einen echten PHP-CLI-Hintergrundprozess mit Leerzeichen
  im Releasepfad und prüft den Schutz vor unmittelbar doppeltem Start. Webanfragen
  verwenden einen CLI-PHP-Interpreter statt `php-fpm`; `EASYWI_PHP_BIN` wird beachtet.
- Chromium: fünf Auswahlkarten, kein Uploadformular, Snapshot-Filter,
  Versionsübernahme ins Anlegeformular einschließlich Vorschauversionen,
  nachträglich eintreffende Versionen und erhaltene Auswahl bei HTTP 503.
  Keine JavaScript-Laufzeitfehler. Die spätere Anbieterantwort wurde im Browser
  kontrolliert simuliert; dies ist kein Live-Downloadtest dieser Anbieter.
- Node: drei Tests für Aktualisierung, Ausfall und beendete Sitzung; bestehender
  Settings-Frontend-Smoke-Test erfolgreich.
- PHPStan ohne Befund; Produktions-DI-Container und sechs Twig-Dateien gültig.
- Release-Smoke-Test: alle drei Katalogdateien unter `core/resources/minecraft/`
  in TAR und ZIP vorhanden; Workflow-YAML und Shell-Syntax gültig.

## Betrieb

Die Dateien werden von beiden Release-Buildwegen automatisch mitgeliefert.
Source-Checkouts verwenden den vorhandenen Ordner `minecraft/`. Ältere
Release-Verzeichnisse werden zusätzlich als Fallback erkannt. Bei fehlenden
Startdateien bleibt der automatische Online-Abruf aktiv. Ein Anbieterausfall
entfernt keine zuvor verfügbaren Einträge. Ein fehlgeschlagener Dateiimport darf
keine bestehenden Versionen deaktivieren.

Der vorhandene Scheduler bleibt für regelmäßige Aktualisierung ohne geöffnete
Panelsitzung zuständig. Logs: `core/var/minecraft-catalog/sync.log` und die
Anwendungslogs. Eine funktionierende ausgehende HTTPS-Verbindung zu den offiziellen
Anbietern ist für neue Metadaten und spätere Installationsdownloads erforderlich.

Geprüft wurde im Cloud-Arbeitsbereich mit PHP 8.4 und SQLite. Die Änderungen wurden
nicht auf `ameserver` installiert; PHP-FPM, PHP 8.5, systemd und echte
Gameserver-Installationen auf dem HomeServer stehen als Live-Prüfung aus.
Die zuvor erarbeiteten Änderungen am Rechnungssystem bleiben erhalten.
