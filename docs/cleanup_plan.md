# Cleanup Plan (D) — no deletion in this change-set

## Method / evidence sources

- Reference scans split by scope (`core/` vs `docs/`) to separate runtime evidence from documentation mentions.
- Container wiring: `php bin/console debug:container ... --show-hidden` and `--types --show-hidden`.
- Router map: `php bin/console debug:router --show-controllers | rg ...`.

---

## Safe-to-delete candidates (low risk, **proposal only**)

### Candidate A: `core/src/Module/Files/**` (placeholder module)

**Current finding**
- Contains only `.gitkeep` files, no executable/runtime code.

**Evidence commands**
1. Namespace/module references:
   - Core/runtime only:
     - `rg -n "App\\\\Module\\\\Files|Module/Files" core/src core/config`
     - Expected: **0 Treffer in `core/`**.
   - Documentation visibility:
     - `rg -n "App\\\\Module\\\\Files|Module/Files" docs`
     - Expected: docs hits are allowed for this plan.
2. Service type map (container types):
   - `cd core && php bin/console debug:container --types --show-hidden | rg -n "Module\\\\Files|Files\\\\"`
   - Expected: **0 application service types** from `App\Module\Files\...`.
3. Route map:
   - `cd core && php bin/console debug:router --show-controllers | rg -n "App\\\\Module\\\\Files|Module\\\\Files"`
   - Expected: routes belong to `PanelCustomer` / `Gameserver` controllers, **not** `App\Module\Files`.

**Risk**
- Low, because no runtime entrypoint/service/route ownership in this module.

### Delete procedure (safe + measurable)

1. Create dedicated cleanup branch:
   - `git checkout -b cleanup/remove-module-files-placeholder`
2. Git safety checks (working tree must be intentional before cleanup commit):
   - `git status --porcelain`
   - `git clean -nd`
3. Remove candidate path only:
   - `git rm -r core/src/Module/Files`
4. Re-run evidence commands and require explicit “0 matches” criteria:
   - `rg -n "App\\\\Module\\\\Files|Module/Files" core/src core/config | wc -l` → expected `0`
   - `rg -n "App\\\\Module\\\\Files|Module/Files" docs | wc -l` → docs hits allowed, must be reviewed
   - `cd core && php bin/console debug:container --types --show-hidden | rg -n "Module\\\\Files|Files\\\\" | wc -l` → expected `0`
   - `cd core && php bin/console debug:router --show-controllers | rg -n "App\\\\Module\\\\Files|Module\\\\Files" | wc -l` → expected `0`
5. Smoke checks:
   - `cd core && composer validate`
   - `cd core && composer dump-autoload`
   - `cd core && php bin/console cache:clear`
   - `cd core && php bin/console lint:container`
   - `cd core && php bin/console app:diagnose:routes`
   - `cd core && php -l src/Module/Gameserver/UI/Controller/Customer/CustomerInstanceFileApiController.php`
6. Commit only if all expected counts match.

---

## Merge candidates

### Merge candidate #1: Shared HTTP error payload + request-id helpers

**Problem**
- Mehrere Controller/Services bauen Error-Payloads und Request-ID Zugriff lokal nach, was Divergenzen erzeugt.

**Target structure (minimal, 3 Klassen unter `core/src/Infrastructure/Http/*`)**

1. `core/src/Infrastructure/Http/RequestIdResolver.php`
   - Responsibility: `resolve(Request): string` aus Header `X-Request-ID` + Request-Attribute.
2. `core/src/Infrastructure/Http/ApiErrorPayloadFactory.php`
   - Responsibility: erstellt standardisiertes Array:
     - `{ "error": { "code": string, "message": string, "request_id": string, "details"?: array } }`
3. `core/src/Infrastructure/Http/ApiErrorResponseFactory.php`
   - Responsibility: baut `JsonResponse` aus `ApiErrorPayloadFactory` + HTTP Statuscode.

**Minimal-invasive migration in 3 steps**

1. **Introduce only**
   - Neue 3 Klassen hinzufügen, ohne bestehenden Controller-Code umzubauen.
   - Unit-Test nur für Payload/Response-Shape (kein Verhalten ändern).
2. **Adopt at high-churn points first**
   - Zuerst `CustomerInstanceFileApiController` auf Factory umstellen.
   - Danach optional `InstanceSftpCredentialApiController`.
   - Kein Routing, kein Domain-Flow, keine Auth-Änderung.
3. **Consolidate + remove local helpers**
   - Lokale `getRequestId()`/`errorResponse()` Duplikate entfernen, sobald beide Consumer migriert sind.
   - Abnahme über gleiche JSON-Shape in API-Fehlerszenarien.

---

## Do-not-touch list (critical runtime paths)

- `core/src/Module/Core/Application/FileServiceClient.php` (signature + agent file API transport).
- `agent/internal/fileapi/*`.
- `core/src/Module/Gameserver/UI/Controller/Customer/CustomerInstanceFileApiController.php`.
- `core/src/Module/Core/Command/{DatabaseDiagnoseCommand.php,FilesDiagnoseCommand.php}`.

---

## Strangler cleanup plan (safe path)

1. Legacy SFTP fallback for game instance file manager has been removed.
2. Webspace SFTP manager remains isolated as separate feature.

### Exit criterion (measurable)

- **Exit criterion:** Game instance file manager uses Agent File API only, with no SFTP fallback paths.

---

## Removed filesvc artifacts (final)

### Deleted files

- `agent/cmd/filesvc/archive.go`
- `agent/cmd/filesvc/auth.go`
- `agent/cmd/filesvc/cache.go`
- `agent/cmd/filesvc/config.go`
- `agent/cmd/filesvc/filesvc_test.go`
- `agent/cmd/filesvc/handlers.go`
- `agent/cmd/filesvc/main.go`
- `agent/cmd/filesvc/paths.go`
- `agent/cmd/filesvc/version.go`

### Removed systemd/service entries

- `easywi-filesvc.service` (installer/systemd setup removed)
- `/etc/easywi/filesvc.conf` (installer config removed)
