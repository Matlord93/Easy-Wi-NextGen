# Architecture Map: Filemanager / SFTP / GameServer (A)

## 1) Symfony Route -> Controller -> Service -> External call

### Instance File API (active path, P0)

| Feature | Route(s) | Controller | Service chain | External call |
|---|---|---|---|---|
| List files | `/api/instances/{id}/files` (+ v1) | `CustomerInstanceFileApiController::list` | `FileServiceClient::list()` | Agent File API `GET /v1/servers/{id}/files` |
| Read/download | `/api/instances/{id}/files/read`, `/download` | same | `FileServiceClient::readFile*()` | Agent File API `GET /v1/servers/{id}/read` / `download` |
| Save/edit | `/api/instances/{id}/files/save` | same | `FileServiceClient::writeFile()` | Agent File API `POST /v1/servers/{id}/write` |
| Upload | `/api/instances/{id}/files/upload` | same | `FileServiceClient::uploadFile()` | Agent File API `POST /v1/servers/{id}/upload` |
| Delete/rename/mkdir/chmod/extract | `/delete` `/rename` `/mkdir` `/chmod` `/extract` | same | `FileServiceClient::*` | Agent File API `POST /v1/servers/{id}/...` |
| Diagnostics | `/api/instances/{id}/files/diagnostics` | same | direct probe `FileServiceClient` | Agent File API |

### Customer webspace file manager (Legacy for game instances)

| Feature | Route(s) | Controller | Service chain | External call |
|---|---|---|---|---|
| Browse/upload/edit/download/delete | `/files/*` | `CustomerFileManagerController` | `WebspaceSftpProvisioner` + `SftpFilesystemService` | direct SFTP |
| Health | `/files/health` | same | `SftpFilesystemService::testConnection()` | direct SFTP |

### Health / diagnose routes

| Feature | Route/Command | Chain |
|---|---|---|
| System health | `GET /system/health` | `SystemHealthController` -> DB config + agent health + file API ping/list |
| DB diagnose | `php bin/console app:diagnose:db -vvv` | `DatabaseDiagnoseCommand` -> `DbConfigProvider` + DBAL migration status |
| Files diagnose | `php bin/console app:diagnose:files --server=ID --path="."` | `FilesAgentDiagnoseCommand` -> agent file API probes |
| Routes diagnose (new) | `php bin/console app:diagnose:routes` | `RoutesDiagnoseCommand` -> RouteCollection filter |

## 2) Go map (Agent)

### Agent File API endpoints

- Public health: `GET /health`, `GET /healthz`.
- Signed API root: `/v1/servers/{instanceId}/{action}` with actions:
  - `files`, `read`, `download`, `write`, `upload`, `mkdir`, `delete`, `rename`, `chmod`, `extract`.
- Auth/signature headers:
  - `X-Agent-ID`, `X-Customer-ID`, `X-Timestamp`, `X-Signature`.
- Signature payload:
  - `agentID + customerID + METHOD + requestURI + timestamp` (HMAC-SHA256).

## 3) Config source map

### DB config

- Loaded from encrypted DB config file (`EASYWI_DB_CONFIG_PATH`, default `/etc/easywi/db.json`, fallback project `var/easywi/db.json` when system path not writable).
- Key source from `SecretKeyLoader` (`var/easywi/secret.key` in dev fallback path if system key unavailable).

### Agent File API (Symfony core)

- Base URL resolution precedence:
  1. node `agent_base_url` (serviceBaseUrl)
  2. node `lastHeartbeatIp` + app default (`APP_AGENT_SERVICE_PORT`)

### SFTP config

- App defaults/settings in DB table via `AppSettingsService` keys (`sftp_host`, `sftp_port`, `sftp_username`, ...).

## 4) Single Source of Truth (A4)

### Active (target)

- **Filemanager for game instances: Core -> FileServiceClient -> Agent File API**.
- No SFTP fallback for game instance file manager.

### Legacy (kept, not deleted)

- `CustomerFileManagerController` + `SftpFilesystemService` webspace flow.

## 5) Canonical install path resolver

- Source of truth: `instances.install_path`.
- Resolver in Core: `App\Module\Gameserver\Application\GameServerPathResolver`.
- Bootstrap/migration helper (legacy only): `InstanceFilesystemResolver`.
- Core -> Agent File API propagation: `X-Server-Root` header on every file request.
- Agent validates root against allowed base zone and emits explicit error codes.
