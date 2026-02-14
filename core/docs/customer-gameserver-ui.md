# Customer Gameserver UI (Dashboard Revamp)

## Route map (customer-visible)

- `/instances`
- `/instances/{id}/overview`
- `/instances/{id}/console`
- `/instances/{id}/files`
- `/instances/{id}/backups`
- `/instances/{id}/tasks`
- `/instances/{id}/settings`

Legacy compatibility routes remain available (`/instances/{id}?tab=...`, `/instances/{id}/files/legacy`) but are not the primary navigation.

## Shared frontend modules

All new customer gameserver apps use:

- `public/js/gameserver/shared/apiClient.js`
- `public/js/gameserver/shared/errors.js`
- `public/js/gameserver/shared/domMount.js`

Per-tab mount apps:

- `overview-app.js`
- `console-app.js`
- `backups-app.js`
- `tasks-app.js`
- `settings-app.js`
- `gameserver-files-app.js`

## API envelope contract

Success:

```json
{
  "ok": true,
  "data": { "...": "..." },
  "request_id": "..."
}
```

Error:

```json
{
  "ok": false,
  "error_code": "FORBIDDEN",
  "message": "Forbidden.",
  "request_id": "...",
  "context": {}
}
```

## Standard error codes

- `FORBIDDEN`
- `UNAUTHORIZED`
- `NOT_FOUND`
- `INVALID_INPUT`
- `INSTANCE_OFFLINE`
- `RATE_LIMITED`
- `AGENT_UNREACHABLE`
- `INTERNAL_ERROR`

## Health endpoints

- Console: `GET /api/instances/{id}/console/health`
- Backups: `GET /api/instances/{id}/backups/health`
- Tasks: `GET /api/instances/{id}/tasks/health`
- Settings: `GET /api/instances/{id}/settings/health`
- Files: `GET /api/instances/{id}/files/health`

## Troubleshooting checklist

1. Open browser devtools and confirm the tab app mount element exists with all required `data-url-*` attributes.
2. Check health endpoint first; if health fails, read `error_code`, `message`, and `request_id` from inline panel/toast.
3. Ensure API responses follow envelope shape for routes used by new tabs.
4. Confirm ownership checks (`customer owns instance`) are enforced server-side when reproducing with alternate user sessions.
