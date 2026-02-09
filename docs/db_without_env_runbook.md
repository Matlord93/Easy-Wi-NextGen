# Runbook: Datenbankkonfiguration ohne ENV

## Überblick
Die Datenbankzugangsdaten werden ausschließlich über den Installer erfasst und verschlüsselt in `/etc/easywi/db.json` gespeichert. Der Schlüssel liegt in `/etc/easywi/secret.key` und wird von PHP-FPM gelesen.

## Pfade & Rechte
- **Secret Key:** `/etc/easywi/secret.key`
  - Owner: `root:root`
  - Rechte: `chmod 600`
  - PHP-FPM muss lesen dürfen (z. B. Gruppe `www-data` mit `chmod 640` oder ACL).
- **DB Config:** `/etc/easywi/db.json`
  - Owner: `root:root` (oder `root:www-data`)
  - Rechte: `chmod 640`
  - Inhalt ist verschlüsselt (libsodium secretbox).

## Installer Flow
1. Installer (/install) fragt Host, Port, Datenbankname, Benutzer und Passwort ab.
2. Verbindungstest und Privilegiencheck werden durchgeführt.
3. Erfolgreiche Konfiguration wird als verschlüsseltes JSON in `/etc/easywi/db.json` gespeichert.
4. Migrationen laufen und der Installer schreibt den Lock.

## Smoke Tests
- **Konfiguration prüfen (ohne DB):**
  - `php bin/console app:diagnose:db -vvv`
  - Erwartung ohne Konfiguration: Meldung „DB nicht konfiguriert.“
- **Doctrine-Config prüfen:**
  - `php bin/console debug:config doctrine`
  - Erwartung: keine `DATABASE_URL`-Env-Referenz und keine SQLite-Fallbacks.
- **Migrationen nach Konfiguration:**
  - `php bin/console doctrine:migrations:migrate`

## Troubleshooting
| Symptom | Ursache | Lösung |
| --- | --- | --- |
| `DB nicht konfiguriert.` | `/etc/easywi/db.json` fehlt | Installer ausführen und Schreibrechte prüfen |
| „Key file not readable“ | `/etc/easywi/secret.key` nicht lesbar | Rechte/Owner anpassen |
| „Database configuration could not be decrypted.“ | Falscher Schlüssel oder beschädigte Datei | Key/Datei prüfen, neu erstellen |
| `Database connection failed` | DB-Zugangsdaten falsch | Zugangsdaten im Installer korrigieren |

## Rollback-Plan
1. **Fallback auf ENV (nur Notfall, ohne Datenverlust):**
   - `DATABASE_URL` wieder in eine `.env.local` oder System-ENV setzen.
   - Doctrine auf ENV-Konfiguration zurückstellen (vorherige `doctrine.yaml` wiederherstellen).
2. **Rollback durchführen:**
   - Cache leeren: `php bin/console cache:clear`
   - PHP-FPM/Webserver neu starten.
3. **Zurück auf neuen Weg:**
   - ENV entfernen.
   - `/etc/easywi/db.json` neu per Installer schreiben.
