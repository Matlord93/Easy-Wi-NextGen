# Canonical Gameserver Install Path

## Goal

Every gameserver (Instance) uses exactly one persisted, canonical absolute install path (`instances.install_path`).
All runtime file operations use this path via resolver only.


## A) Inventory (path assumptions found)

| Component | File | Mechanism (before) | Classification |
|---|---|---|---|
| Core legacy resolver | `core/src/Module/Core/Application/InstanceFilesystemResolver.php` | builds `<base_dir>/gs{customer}{instance}` | **BUG** (implicit path building for runtime risk) |
| Agent file API path root | `agent/internal/fileapi` | derived from `X-Server-Root` + base dir validation | **OK** (canonical persisted path) |
| Agent disk resolver | `agent/cmd/agent/disk.go` | fallback builds from `instance_id/customer_id` | **BUG** (implicit assumption) |
| Core job enqueue | `core/src/Module/PanelCustomer/UI/Controller/Api/AgentApiController.php` | sent heuristic instance path via old resolver | **BUG** (could diverge from real install path) |

All runtime path access has been moved to canonical `install_path` resolution and explicit header propagation.

## Single Source of Truth

- DB column: `instances.install_path`.
- Resolver: `App\Module\Gameserver\Application\GameServerPathResolver`.
- Runtime policy:
  - **DO NOT build server file paths in runtime code**.
  - Resolve from persisted `install_path` only.

## Where path is set

- On server creation, `GameServerInstallPathManager` bootstraps path once and persists it.
- Legacy bootstrap source (creation/migration only): `InstanceFilesystemResolver`.
- Backfill command for existing servers:
  - `php bin/console app:gameserver:install-path:migrate`

## Runtime flow

1. Core resolves canonical root:
   - `GameServerPathResolver::resolveRoot()`
2. Core forwards root to Agent File API for every request:
   - header `X-Server-Root`
3. Agent validates root:
   - absolute path
   - under allowed root zone (`base_dir`)
   - exists and accessible
4. All file operations run relative to validated root.

## File API validation errors

- `INVALID_SERVER_ROOT`
- `SERVER_ROOT_NOT_FOUND`
- `SERVER_ROOT_NOT_ACCESSIBLE`

Payload shape:

```json
{
  "error": {
    "code": "INVALID_SERVER_ROOT",
    "message": "invalid or missing canonical server root",
    "request_id": "..."
  }
}
```

## Diagnostics

- `php bin/console app:diagnose:files --instance-id=ID --path="."`
  - shows `resolved_server_root`, accessibility, owner uid, permissions.
- `GET /system/health`
  - includes `server_root_validity.invalid_count`.

## Migration strategy

1. Run `app:gameserver:install-path:migrate`.
2. Review `install_path_state` (`OK` / `BROKEN`) per instance.
3. Fix `BROKEN` roots on host and rerun migration command.

## Typical failures

- Empty/missing `install_path` -> `INVALID_SERVER_ROOT`
- Path deleted on host -> `SERVER_ROOT_NOT_FOUND`
- Permission denied on host path -> `SERVER_ROOT_NOT_ACCESSIBLE`
