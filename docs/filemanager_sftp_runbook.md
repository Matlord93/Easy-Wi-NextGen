# Filemanager/SFTP Runbook (Easy-Wi NextGen)

This runbook documents how the file manager and SFTP access are wired in this repository, how to diagnose failures, and how to bring the feature up in **dev** and **prod**.

## 1) Architektur-Überblick (Zielzustand)

### Gameserver-Instanzen (Kunden → `/instances/{id}/files`)
* **UI/Routes:** `CustomerInstanceFileManagerController` (`/instances/{id}/files`) & JS in `customer/instances/files/index.html.twig`.
* **API:** `CustomerInstanceFileApiController` (`/api/instances/{id}/files/*`).
* **Backend-Pfad:** `FileServiceClient` → Agent File API (HMAC-Signatur, keine SFTP-Fallbacks).

### Webspaces (Kunden → `/files`)
* **UI/Routes:** `CustomerFileManagerController` (`/files`, `/files/browse`, `/files/health`).
* **Backend:** `SftpFilesystemService` (Flysystem SFTP Adapter, direkt instanziert – **kein** Flysystem-Bundle-Storage notwendig).

### Wichtig
* Das Projekt **nutzt SFTP weiterhin für Webspaces** über App-eigene Services (`SftpFilesystemService`).
* Für Gameserver-Instanzen erfolgt File-IO **ausschließlich über den Agent**.

## 2) Voraussetzungen

* PHP 8.x mit folgenden Extensions:
  * `ext-ssh2` ist **nicht** erforderlich (phpseclib wird genutzt).
  * `ext-zip` für Archive-Extraktion (SFTP extract) wird empfohlen.
* Zugriff des Webservers/CLI auf den SFTP-Host (Firewall/Netzwerk prüfen).
* Schreibrechte in `var/` (Logs/Cache).
* Für Gameserver-File-API:
  * Agent erreichbar (Port `APP_AGENT_SERVICE_PORT`, Default `8087`).

## 3) Konfiguration

### Global (App Settings)
Diese Werte werden über `AppSettingsService` gelesen und in der Datenbank gespeichert (verschlüsselt für Secrets):

* SFTP Host
* SFTP Port (Default `22`)
* SFTP Username (nur benötigt, wenn keine instanzspezifischen Credentials existieren)
* SFTP Password / Private Key / Private Key Passphrase

> Hinweis: Für Webspaces werden **Credentials aus der DB** genutzt (siehe unten).

### Webspace-Credentials (Panel-Customer)
* Tabelle: `webspace_sftp_credentials` (siehe Migrationen in `core/migrations`).
* Werden per Provisioner erzeugt (`WebspaceSftpProvisioner`).
* Health-Check erwartet, dass diese Credentials existieren.

## 4) Deployment-Schritte (Dev/Prod)

1. **SFTP Settings in der Admin-UI setzen** (globaler Host/Port & optional Username/Password/Key).
2. **Datenbank prüfen** (Webspace Credentials vorhanden?):
   * `webspace_sftp_credentials`
3. **Cache leeren & warmen**:
   ```bash
   php bin/console cache:clear
   php bin/console cache:warmup
   ```
4. **Berechtigungen**:
   * `var/log`, `var/cache` für den Webserver-User schreibbar machen.

## 5) Tests / Smoke Checks

### CLI
```bash
php bin/console app:diagnose:files --instance-id=123 --path="."
php bin/console app:diagnose:files --webspace-id=42 --path=""
```

### HTTP
```bash
curl -s "https://<host>/files/health?webspace=42" | jq
curl -s "https://<host>/api/instances/123/files/diagnostics" | jq
```

### UI
* `/instances/{id}?tab=files` → Upload/Download/Edit/Rename/Delete
* `/files` → Browse/Upload/Edit

## 6) Troubleshooting

| Fehlermeldung | Ursache | Fix |
|---|---|---|
| `SFTP host is not configured` | globaler SFTP Host fehlt | Settings setzen |
| `SFTP credentials are not provisioned yet` | `webspace_sftp_credentials` fehlt | Provisioner/Reset ausführen |
| `sftp_auth_failed` | Nutzer/Passwort/Key falsch | Credentials prüfen |
| `agent_misconfigured` | Agent-URL fehlt | Node-Config setzen |
| `File is too large to edit (max 1 MB)` | Editor-Limit | Datei runterladen/extern editieren |
| `Binary files cannot be edited` | Binärdatei | Nur Textdateien im Editor |
| `Path traversal is not allowed` | `..`/absolute Pfade | Nur relative Pfade verwenden |

**Logfiles**
* Dev: `var/log/dev.log`
* Prod: `var/log/prod.log`

**Netzwerk-Checks**
```bash
nc -vz <sftp-host> 22
ssh -vvv <user>@<sftp-host>
```

## 7) Diagnose-Checkliste (Kurzfassung)

1. `/files/health?webspace=<id>` prüfen (zeigt `config`, `missing`, `sftp_reachable`).
2. `php bin/console app:diagnose:files --instance-id=<id>`.
3. `php bin/console app:diagnose:files --webspace-id=<id>`.
4. `var/log/dev.log`/`prod.log` auf `files.*` Einträge prüfen.

---

**Hinweis:** Die SFTP-Anbindung wird in diesem Projekt über App-eigene Services durchgeführt (`SftpFilesystemService`). Ein Flysystem-Bundle-Storage namens `sftp.storage` wird **nicht** benötigt.
