# API Versionierung (v1) — Regeln, Inventar & Migrationsplan

## Ziele
- Alle neuen Endpoints nutzen `/api/v1/...`.
- Legacy-Endpoints unter `/api/...` bleiben in Phase A verfügbar, sind aber deprecated.
- RBAC, Rate Limits und AuditLog bleiben unverändert (Security by default).

## Regeln
- **Neue Endpoints:** ausschließlich `/api/v1/...`.
- **Legacy:** `/api/...`, `/agent/...` sowie `/mail-aliases...` liefern `Deprecation: true` und `Sunset` Header, plus serverseitiges Warning-Log.
- **RBAC:** gleiche Guards/Session-Checks wie bei Legacy (keine doppelten Policies).
- **Logging:** keine Secrets im Log; Warnungen enthalten nur Pfad, Methode, Route-Name.
- **CI Guardrail:** Legacy-Pfade sind allowlisted; neue `/api/...` ohne `/api/v1/...` brechen den Test.

## Route Inventory (Stand jetzt)

### Admin API
Legacy `/api/...` ➜ v1 Alias:
- `POST /api/admin/users` ➜ `POST /api/v1/admin/users`
- `POST /api/admin/shop/provision` ➜ `POST /api/v1/admin/shop/provision`
- `GET /api/admin/port-pools` ➜ `GET /api/v1/admin/port-pools`
- `POST /api/admin/port-pools` ➜ `POST /api/v1/admin/port-pools`
- `GET /api/port-blocks` ➜ `GET /api/v1/admin/port-blocks` (shared mit Customer)
- `POST /api/admin/port-blocks` ➜ `POST /api/v1/admin/port-blocks`
- `POST /api/admin/instances` ➜ `POST /api/v1/admin/instances`
- `DELETE /api/admin/instances/{id}` ➜ `DELETE /api/v1/admin/instances/{id}`
- `POST /api/admin/instances/{id}/update-settings` ➜ `POST /api/v1/admin/instances/{id}/update-settings`
- `POST /api/admin/webspaces` ➜ `POST /api/v1/admin/webspaces`
- `POST /api/ts3/instances` ➜ `POST /api/v1/admin/ts3/instances`
- `POST /api/ts3/instances/{id}/actions` ➜ `POST /api/v1/admin/ts3/instances/{id}/actions`

### Customer API
Legacy `/api/...` ➜ v1 Alias:
- `POST /api/auth/login` ➜ `POST /api/v1/auth/login` (public)
- `GET /api/instances` ➜ `GET /api/v1/customer/instances`
- `GET /api/instances/{id}/sftp-credentials` ➜ `GET /api/v1/customer/instances/{id}/sftp-credentials`
- `POST /api/instances/{id}/sftp-credentials/reset` ➜ `POST /api/v1/customer/instances/{id}/sftp-credentials/reset`
- `POST /api/instances/{id}/addons/*` ➜ `POST /api/v1/customer/instances/{id}/addons/*`
- `GET|POST /api/instances/{id}/backups` ➜ `GET|POST /api/v1/customer/instances/{id}/backups`
- `POST /api/instances/{id}/backups/{backupId}/restore` ➜ `POST /api/v1/customer/instances/{id}/backups/{backupId}/restore`
- `PATCH /api/instances/{id}/schedules/{action}` ➜ `PATCH /api/v1/customer/instances/{id}/schedules/{action}`
- `POST /api/instances/{id}/console/commands` ➜ `POST /api/v1/customer/instances/{id}/console/commands`
- `POST /api/instances/{id}/console/logs` ➜ `POST /api/v1/customer/instances/{id}/console/logs`
- `PATCH /api/instances/{id}/settings` ➜ `PATCH /api/v1/customer/instances/{id}/settings`
- `POST /api/instances/{id}/reinstall` ➜ `POST /api/v1/customer/instances/{id}/reinstall`
- `GET|POST /api/instances/{id}/files/*` ➜ `GET|POST /api/v1/customer/instances/{id}/files/*`
- `GET|PUT|POST /api/customer/instances/{id}/configs/*` ➜ `GET|PUT|POST /api/v1/customer/instances/{id}/configs/*`
- `POST /api/customer/instances/{id}/actions` ➜ `POST /api/v1/customer/instances/{id}/actions`
- `GET /api/customer/jobs/{jobId}` ➜ `GET /api/v1/customer/jobs/{jobId}`
- `GET /api/customer/jobs/{jobId}/logs` ➜ `GET /api/v1/customer/jobs/{jobId}/logs`
- `POST /api/customer/jobs/{jobId}/cancel` ➜ `POST /api/v1/customer/jobs/{jobId}/cancel`
- `GET /api/backups` ➜ `GET /api/v1/customer/backups`
- `POST /api/backups` ➜ `POST /api/v1/customer/backups`
- `PATCH /api/backups/{id}/schedule` ➜ `PATCH /api/v1/customer/backups/{id}/schedule`
- `GET /api/databases` ➜ `GET /api/v1/customer/databases`
- `POST /api/databases` ➜ `POST /api/v1/customer/databases`
- `PATCH /api/databases/{id}/password` ➜ `PATCH /api/v1/customer/databases/{id}/password`
- `GET /api/mailboxes` ➜ `GET /api/v1/customer/mailboxes`
- `POST /api/mailboxes` ➜ `POST /api/v1/customer/mailboxes`
- `PATCH /api/mailboxes/{id}/quota` ➜ `PATCH /api/v1/customer/mailboxes/{id}/quota`
- `PATCH /api/mailboxes/{id}/status` ➜ `PATCH /api/v1/customer/mailboxes/{id}/status`
- `PATCH /api/mailboxes/{id}/password` ➜ `PATCH /api/v1/customer/mailboxes/{id}/password`
- `GET /mail-aliases` ➜ `GET /api/v1/customer/mail-aliases`
- `POST /mail-aliases` ➜ `POST /api/v1/customer/mail-aliases`
- `PATCH /mail-aliases/{id}` ➜ `PATCH /api/v1/customer/mail-aliases/{id}`
- `DELETE /mail-aliases/{id}` ➜ `DELETE /api/v1/customer/mail-aliases/{id}`
- `GET /api/tickets` ➜ `GET /api/v1/customer/tickets`
- `POST /api/tickets` ➜ `POST /api/v1/customer/tickets`
- `POST /api/tickets/{id}/messages` ➜ `POST /api/v1/customer/tickets/{id}/messages`
- `PATCH /api/tickets/{id}/status` ➜ `PATCH /api/v1/customer/tickets/{id}/status`
- `GET /api/tickets/{id}/messages` ➜ `GET /api/v1/customer/tickets/{id}/messages`
- `POST /api/dns/records` ➜ `POST /api/v1/customer/dns/records`
- `PUT /api/dns/records/{id}` ➜ `PUT /api/v1/customer/dns/records/{id}`
- `DELETE /api/dns/records/{id}` ➜ `DELETE /api/v1/customer/dns/records/{id}`
- `GET /api/ts3/instances` ➜ `GET /api/v1/customer/ts3/instances`
- `GET /api/webspaces` ➜ `GET /api/v1/customer/webspaces`
- `GET /api/port-blocks` ➜ `GET /api/v1/customer/port-blocks` (shared mit Admin)

### Agent API
Legacy `/agent/...` ➜ v1 Alias:
- `POST /agent/heartbeat` ➜ `POST /api/v1/agent/heartbeat`
- `GET /agent/jobs` ➜ `GET /api/v1/agent/jobs`
- `POST /agent/jobs/{id}/result` ➜ `POST /api/v1/agent/jobs/{id}/result`
- `POST /api/v1/agent/register` ist bereits v1
- `POST /api/v1/agent/bootstrap` ist neu (Bootstrap → Register Flow)

## Migrationsplan

### Phase A (Beta)
1. **v1 Aliases** für alle Legacy-Routen bereitstellen.
2. **Legacy markieren:** Response Header `Deprecation: true` und `Sunset` setzen.
3. **Logging:** Warning-Log für Legacy-Aufrufe (ohne Secrets).
4. **Docs & Manual Steps** aktualisieren (diese Datei).

### Phase B (nach stabiler Beta)
1. **Legacy entfernen:** `/api/...` (und `/agent/...`) Routen entfernen.
2. **Clients aktualisieren:** alle Consumer auf `/api/v1/...` umstellen.
3. **Monitoring:** 0% Legacy-Traffic vor Removal.

## Manual Test Steps
1. `POST /api/v1/auth/login` → Token erhalten.
2. Mit Token: `GET /api/v1/customer/instances` → 200 OK.
3. Legacy-Aufruf: `GET /api/instances` → 200 OK + `Deprecation: true` und `Sunset` Header.
4. Admin: `POST /api/v1/admin/port-pools` → RBAC greift, AuditLog wird geschrieben.
5. Agent: `POST /api/v1/agent/bootstrap` → 200 OK, Bootstrap-Token wird einmalig verbraucht.
6. Agent: `POST /api/v1/agent/register` → 201 Created, Agent-Secret wird ausgeliefert.
7. Agent: `POST /api/v1/agent/heartbeat` → 200 OK, Agent-Auth weiterhin gültig.
