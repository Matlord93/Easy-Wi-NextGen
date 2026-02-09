# Runbook: Filemanager über Agent (File API)

Dieses Runbook beschreibt die End-to-End-Kette für den Dateimanager (Gameserver-Dateien) mit dem Agent als einziger File-IO-Komponente.

## 1) Komponenten & Datenfluss (Zielzustand)

### Symfony Core
- **UI (Customer)**: `CustomerInstanceFileManagerController` (`/instances/{id}/files`), rendert UI + HTMX-Partial-Requests.
- **API**: `CustomerInstanceFileApiController` (`/api/instances/{id}/files/*`).
- **Core → Agent**: `FileServiceClient` spricht den Agenten direkt an (HMAC-Signatur, Agent-Secret).

### Go
- **Agent**: Pollt Jobs, sendet Heartbeats an den Core und stellt die File-API bereit.
- **File API**: `GET/POST /v1/servers/{id}/*` mit Aktionen `files`, `read`, `download`, `write`, `upload`, `mkdir`, `delete`, `rename`, `chmod`, `extract`.

## 2) Konfiguration (Core)

**Agent Ziel** kommt aus der Node:
- `agent_base_url` (Admin UI) oder Fallback `lastHeartbeatIp` + `APP_AGENT_SERVICE_PORT`.

**HMAC Auth**: `FileServiceClient` signiert Requests mit dem Agent-Secret (aus DB, verschlüsselt).
**Serverseitige Verifikation**: Agent prüft HMAC, Agent-ID und Timestamp (Skew).

## 2.1) Sicherheitsprüfungen (Pfad/Editor)
- **Pfadvalidierung (Core)**: Relative Pfade werden normalisiert, `..` und absolute Pfade sind nicht erlaubt.
- **Pfadvalidierung (Agent)**: Zusätzliches Server-Side-Sandboxing stellt sicher, dass Pfade im `file_base_dir` bleiben.
- **Editor-Grenzen**: API blockiert zu große Dateien (Editor-Limit) und verweigert binäre Dateien.

## 3) Konfiguration (Agent)

### Agent (`/etc/easywi/agent.conf`)
```ini
agent_id=<AGENT_ID>
secret=<SECRET>
api_url=https://panel.example.com
service_listen=0.0.0.0:8087
file_base_dir=/home
file_cache_size=256
file_max_skew_seconds=45
file_read_timeout_seconds=15
file_write_timeout_seconds=30
file_idle_timeout_seconds=60
# optional
# file_max_upload_mb=512
```

## 4) Services (systemd)

```bash
sudo systemctl enable --now easywi-agent.service
sudo systemctl status easywi-agent.service
```

## 5) Healthchecks

### Agent (File API + Health)
```bash
curl -s http://127.0.0.1:8087/health | jq
```

### Core (aggregiert)
```bash
curl -s https://<core-host>/system/health | jq
```

Erwartete Keys in `/system/health`:
- `agent_health`
- `file_api_health`
- `app_env`, `app_debug`

## 5.1) Quickstart Smoke Test

```bash
# Agent erreichbar (inkl. file_api capability)
curl -s http://127.0.0.1:8087/health | jq

# End-to-end Diagnose (Agent + Auth + List + Read/Write + Upload/Download)
php bin/console app:diagnose:files --server=ID --path="."
```

Beispielausgaben:
- `Agent health OK (..., HTTP 200)`
- `File API health OK (..., HTTP 200)`
- `Auth/signature check: OK (list returned 200)`
- `Wrote, verified, and deleted ...`
- `Uploaded, downloaded, and deleted ...`

## 6) Smoke Tests (Core CLI)

```bash
php bin/console app:diagnose:files --instance-id=<ID>
```

Der Command prüft:
- Agent-Health
- File-API-Health
- Signed Auth-Header (maskiert) + List
- Read (optional: erstes Textfile aus List oder `--read-path`)
- Write + read-back + delete test file
- Upload + download + delete test file

Optionaler Read-Test:
```bash
php bin/console app:diagnose:files --instance-id=<ID> --read-path=path/to/file.txt
```

## 7) Direkt-Tests (File API)

Die File-API benötigt HMAC-Header (`X-Agent-ID`, `X-Customer-ID`, `X-Timestamp`, `X-Signature`).
Für direkte Requests am besten den Core verwenden (`app:diagnose:files`).

## 8) Troubleshooting

| Problem | Ursache | Lösung |
|---|---|---|
| `agent_unreachable` | Agent nicht erreichbar | Agent-Service/Netzwerk prüfen |
| `agent_misconfigured` | Agent-URL fehlt | Node-Config setzen |
| `agent_timeout` | Netzwerk/Timeouts | PHP-FPM/NGINX Timeouts prüfen |
| `INVALID_SERVER_ROOT` | Root fehlt/ungültig | instances.install_path prüfen |
| `SERVER_ROOT_NOT_FOUND` | Pfad existiert nicht | Installation prüfen |
| `SERVER_ROOT_NOT_ACCESSIBLE` | Rechte/Access | Dateisystem-ACL/Owner prüfen |
| `PERMISSION_DENIED` | Datei-Rechte | Datei/Verzeichnisrechte prüfen |
| `INVALID_PATH` | Pfad ungültig | UI-Pfad prüfen |

### Troubleshooting by status code

| HTTP Code | Bedeutung | Fix |
|---|---|---|
| 200 | OK | — |
| 401/403 | Auth fehlgeschlagen | Agent-ID/Secret prüfen, Agent neu registrieren |
| 404 | Falscher Pfad | Instance-ID/Path prüfen |
| 413 | Upload zu groß | `client_max_body_size`, `post_max_size`, `upload_max_filesize` erhöhen |
| 502/503 | Agent down | Agent-Service/Netzwerk prüfen |
| 504 | Timeout | `fastcgi_read_timeout`, `max_execution_time` erhöhen |

## 9) Typische Ursachen
- **Token/Signature** falsch oder fehlt (Agent-Secret veraltet).
- **Server Root** (`instances.install_path`) passt nicht zum `file_base_dir`.
- **Uploads**: Reverse proxy `client_max_body_size` zu klein.
- **Timeouts**: `fastcgi_read_timeout`/`max_execution_time` zu niedrig.
