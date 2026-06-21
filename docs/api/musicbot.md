# Musicbot API

This document describes the current REST API for the Musicbot module. All paths are relative to the panel host and return JSON unless noted otherwise.

> **Placeholder status:** TeamSpeak voice output, Discord voice output, web radio stream output and real audio decoding are currently prepared as placeholder/runtime-control integrations. The API exposes configuration and status fields for these features, but it must not be interpreted as a guarantee that real audio is already streamed to TeamSpeak, Discord or an Icecast/Shoutcast-compatible endpoint.

## Conventions

### Authentication and roles

- Customer endpoints require an authenticated user with `UserType::Customer` and are scoped to that customer's own Musicbot instances.
- Admin endpoints require an authenticated admin user.
- Example header:

```http
Authorization: Bearer <access-token>
Accept: application/json
Content-Type: application/json
```

### Response envelopes

Most successful responses use one of these envelopes:

```json
{ "data": { "id": 123 } }
```

```json
{ "data": [{ "id": 123 }] }
```

Errors use:

```json
{ "error": "Human-readable error message." }
```

### Common error codes

| Code | Meaning |
| --- | --- |
| `400` | Invalid request body, unsupported action or malformed input. |
| `401` | Missing/invalid customer authentication. |
| `403` | Admin-only endpoint, missing permission or quota/feature gate denial. |
| `404` | Musicbot, queue item, track, playlist, plugin, schedule, workflow or connection not found in the caller's scope. |
| `415` | Non-JSON body on JSON endpoints, or unsupported upload media type where enforced. |
| `422` | Semantic validation failure or quota exceeded. |

### Shared object shapes

#### Musicbot instance

```json
{
  "id": 42,
  "name": "Radio Bot",
  "status": "installing|running|stopped|error|unknown",
  "customer": { "id": 7, "email": "customer@example.test" },
  "node": { "id": "node-1", "name": "node-1" },
  "service_name": "musicbot-radio-bot-a1b2c3",
  "install_path": "/var/lib/easywi/musicbot/musicbot-radio-bot-a1b2c3",
  "limits": { "cpu": 0, "ram": 0, "disk": 0 },
  "connections": [],
  "current_track": null,
  "queue_length": 0,
  "auto_dj_enabled": false,
  "stream_enabled": false,
  "stream_ready": false,
  "stream_url_placeholder": "/stream/radio-bot",
  "created_at": "2026-06-21T00:00:00+00:00",
  "updated_at": "2026-06-21T00:00:00+00:00"
}
```

`stream_ready` is currently `false` for the placeholder stream backend.

#### Track

```json
{
  "id": 100,
  "title": "Intro",
  "artist": "Example Artist",
  "duration_seconds": 180,
  "source_type": "upload",
  "mime_type": "audio/mpeg",
  "metadata": {}
}
```

#### Queue item

```json
{
  "id": 500,
  "position": 1,
  "status": "queued",
  "track": { "id": 100, "title": "Intro" },
  "requested_by": { "id": 7, "email": "customer@example.test" },
  "created_at": "2026-06-21T00:00:00+00:00"
}
```

#### Connection

Secrets are always normalized/masked. Plain secret values are never returned.

```json
{
  "id": 12,
  "platform": "teamspeak|discord",
  "enabled": true,
  "status": "pending|connected|failed|disabled",
  "last_connected_at": null,
  "last_error": null,
  "secrets": { "bot_token": { "configured": true, "masked": true } },
  "capability_status": "client_backend_required|voice_backend_required|placeholder|ready|error"
}
```

For Discord, `config.application_id`, `config.guild_id`, `config.voice_channel_id`, `config.command_mode`, `config.slash_commands_enabled` and `config.reconnect_policy` may be present. `slash_commands_status` is currently `placeholder`.

---

## Customer endpoints

### Musicbots list/show

| Method | Path | Auth | Permission | Request body | Response | Errors |
| --- | --- | --- | --- | --- | --- | --- |
| `GET` | `/api/v1/customer/musicbots` | Customer | Customer owns returned instances | None | `data: MusicbotInstance[]` | `401` |
| `GET` | `/api/v1/customer/musicbots/{id}` | Customer | Customer owns `{id}` | None | `data: MusicbotInstance` plus `queue`, `playlists`, `plugins` | `401`, `404` |

Example:

```bash
curl -H "Authorization: Bearer $TOKEN" https://panel.example/api/v1/customer/musicbots/42
```

### Limits

| Method | Path | Auth | Permission | Request body | Response | Errors |
| --- | --- | --- | --- | --- | --- | --- |
| `GET` | `/api/v1/customer/musicbots/limits` | Customer | Customer account | None | Effective plan limits and override fields | `401` |

Example response fields include `max_musicbots`, `max_tracks`, `max_storage_mb`, `max_playlists`, `max_plugins`, `max_queue_items`, `max_connections`, `max_upload_size_mb`, `allow_teamspeak`, `allow_discord`, `allow_webradio`, `allow_plugins`, `allow_workflows`, `allow_scheduler` and `granted_permissions`.

### Queue

| Method | Path | Auth | Permission | Request body | Response | Errors |
| --- | --- | --- | --- | --- | --- | --- |
| `GET` | `/api/v1/customer/musicbots/{id}/queue` | Customer | Own instance | None | `data: QueueItem[]` | `401`, `404` |
| `POST` | `/api/v1/customer/musicbots/{id}/queue` | Customer | Own instance; queue quota | `{ "track_id": 100 }` | `201`, `data: QueueItem`, `job_id` | `401`, `404`, `422` |
| `DELETE` | `/api/v1/customer/musicbots/{id}/queue/{queueItemId}` | Customer | Own instance and queue item | None | `data.deleted`, `data.job_id` | `401`, `404` |

Examples:

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"track_id":100}' \
  https://panel.example/api/v1/customer/musicbots/42/queue
```

```bash
curl -X DELETE -H "Authorization: Bearer $TOKEN" \
  https://panel.example/api/v1/customer/musicbots/42/queue/500
```

> REST API note: queue clear/reorder are available in the customer panel controller, but no `/api/v1/customer/.../queue/clear` or `/reorder` REST endpoint exists in the current API controller. API clients should delete individual queue items or use playlist queue loading until REST clear/reorder endpoints are added.

### Playback actions

| Method | Path | Auth | Permission | Request body | Response | Errors |
| --- | --- | --- | --- | --- | --- | --- |
| `POST` | `/api/v1/customer/musicbots/{id}/playback` | Customer | Own instance | `{ "action": "play|pause|resume|stop|skip|volume|shuffle|repeat", ...options }` | `202`, `data.job_id`, `data.action` | `400`, `401`, `404` |

Example:

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"action":"volume","value":65}' \
  https://panel.example/api/v1/customer/musicbots/42/playback
```

Playback commands are dispatched to the agent/runtime-control path. With the placeholder runtime they may be queued as state-file commands rather than producing real audio output.

### Track library

| Method | Path | Auth | Permission | Request body | Response | Errors |
| --- | --- | --- | --- | --- | --- | --- |
| `GET` | `/api/v1/customer/musicbots/{id}/tracks` | Customer | Own instance | None | `data: Track[]` for customer library | `401`, `404` |
| `POST` | `/api/v1/customer/musicbots/{id}/tracks` | Customer | Own instance; storage/upload quota | `multipart/form-data` with `track_file`, optional `title`, `artist` | `201`, `data: Track` | `400`, `401`, `404`, `422` |
| `DELETE` | `/api/v1/customer/musicbots/{id}/tracks/{trackId}` | Customer | Own track | None | `data.deleted` | `401`, `404` |

Example upload:

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" \
  -F track_file=@intro.mp3 -F title=Intro -F artist="Example Artist" \
  https://panel.example/api/v1/customer/musicbots/42/tracks
```

Only local uploaded tracks are used. The API does not scrape YouTube, Spotify or other external audio services.

### Playlists

| Method | Path | Auth | Permission | Request body | Response | Errors |
| --- | --- | --- | --- | --- | --- | --- |
| `GET` | `/api/v1/customer/musicbots/{id}/playlists` | Customer | Own instance | None | `data: Playlist[]` | `401`, `404` |
| `POST` | `/api/v1/customer/musicbots/{id}/playlists` | Customer | Playlist quota | `{ "name": "Fallback", "visibility": "private|shared" }` | `201`, `data: Playlist` | `400`, `401`, `404`, `422` |
| `PATCH` | `/api/v1/customer/musicbots/{id}/playlists/{playlistId}` | Customer | Own playlist | `{ "name": "New name", "visibility": "private|shared" }` | `data: Playlist` | `401`, `404` |
| `DELETE` | `/api/v1/customer/musicbots/{id}/playlists/{playlistId}` | Customer | Own playlist | None | `data.deleted` | `401`, `404` |
| `POST` | `/api/v1/customer/musicbots/{id}/playlists/{playlistId}/tracks` | Customer | Own playlist and track | `{ "track_id": 100 }` | `201`, `data: PlaylistItem` | `401`, `404` |
| `DELETE` | `/api/v1/customer/musicbots/{id}/playlists/items/{itemId}` | Customer | Own playlist item | None | `data.deleted` | `401`, `404` |
| `POST` | `/api/v1/customer/musicbots/{id}/playlists/{playlistId}/queue` | Customer | Own playlist; queue quota | None | `data.queued_tracks` | `401`, `404`, `422` |

Example:

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"name":"Fallback","visibility":"private"}' \
  https://panel.example/api/v1/customer/musicbots/42/playlists
```

### Plugins

| Method | Path | Auth | Permission | Request body | Response | Errors |
| --- | --- | --- | --- | --- | --- | --- |
| `GET` | `/api/v1/customer/musicbots/{id}/plugins` | Customer | Own instance; plugin feature if enforced by service | None | `data.available`, `data.assigned` | `401`, `404` |
| `POST` | `/api/v1/customer/musicbots/{id}/plugins` | Customer | Own instance; plugin quota | `{ "identifier": "metadata.example" }` | `201`, `data: Plugin` | `400`, `401`, `404`, `422` |
| `PATCH` | `/api/v1/customer/musicbots/{id}/plugins/{pluginId}` | Customer | Own plugin | `{ "enabled": true, "config": {} }` | `data: Plugin` | `400`, `401`, `404` |

Example:

```bash
curl -X PATCH -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"enabled":false}' \
  https://panel.example/api/v1/customer/musicbots/42/plugins/9
```

### Logs/runtime events

| Method | Path | Auth | Permission | Query | Response | Errors |
| --- | --- | --- | --- | --- | --- | --- |
| `GET` | `/api/v1/customer/musicbots/{id}/logs` | Customer | Own instance | `limit` optional, max `200` | `data: RuntimeEvent[]` | `401`, `404` |

Example:

```bash
curl -H "Authorization: Bearer $TOKEN" \
  "https://panel.example/api/v1/customer/musicbots/42/logs?limit=50"
```

### Schedules

| Method | Path | Auth | Permission | Request body | Response | Errors |
| --- | --- | --- | --- | --- | --- | --- |
| `GET` | `/api/v1/customer/musicbots/{id}/schedules` | Customer | Own instance; scheduler feature if enforced by service | None | `data: Schedule[]` | `401`, `404` |
| `POST` | `/api/v1/customer/musicbots/{id}/schedules` | Customer | Own instance | `name`, `action`, `cron_expression`, optional `timezone`, `payload`, `enabled` | `201`, `data: Schedule` | `400`, `401`, `404`, `422` |
| `GET` | `/api/v1/customer/musicbots/{id}/schedules/{scheduleId}` | Customer | Own schedule | None | `data: Schedule` | `401`, `404` |
| `PATCH` | `/api/v1/customer/musicbots/{id}/schedules/{scheduleId}` | Customer | Own schedule | Partial schedule fields | `data: Schedule` | `401`, `404`, `422` |
| `DELETE` | `/api/v1/customer/musicbots/{id}/schedules/{scheduleId}` | Customer | Own schedule | None | `204` empty | `401`, `404` |
| `POST` | `/api/v1/customer/musicbots/{id}/schedules/{scheduleId}/toggle` | Customer | Own schedule | `{ "enabled": true }` (optional; toggles if omitted) | `data: Schedule` | `401`, `404` |
| `POST` | `/api/v1/customer/musicbots/{id}/schedules/{scheduleId}/test` | Customer | Own schedule | Optional test payload | `data` with dispatched/test result | `401`, `404`, `422` |

Example:

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"name":"Morning start","action":"playback.play","cron_expression":"0 8 * * *","timezone":"Europe/Berlin","payload":{}}' \
  https://panel.example/api/v1/customer/musicbots/42/schedules
```

### Auto-DJ

| Method | Path | Auth | Permission | Request body | Response | Errors |
| --- | --- | --- | --- | --- | --- | --- |
| `GET` | `/api/v1/customer/musicbots/{id}/autodj` | Customer | Own instance | None | Auto-DJ settings | `401`, `404` |
| `PUT`/`PATCH` | `/api/v1/customer/musicbots/{id}/autodj` | Customer | Own instance; local playlist/track scope | `enabled`, `fallback_playlist_id`, `mode`, `avoid_repeats`, `min_queue_size`, `genre_filter` | Auto-DJ settings | `401`, `404`, `422` |
| `POST` | `/api/v1/customer/musicbots/{id}/autodj/enable` | Customer | Own instance | None | Auto-DJ settings | `401`, `404` |
| `POST` | `/api/v1/customer/musicbots/{id}/autodj/disable` | Customer | Own instance | None | Auto-DJ settings | `401`, `404` |
| `POST` | `/api/v1/customer/musicbots/{id}/autodj/trigger` | Customer | Own instance; queue quota | None | `data.tracks_added`, `data.settings` | `401`, `404`, `422` |
| `POST` | `/api/v1/customer/musicbots/{id}/autodj/run` | Customer | Alias of `/trigger` | None | Same as `/trigger` | `401`, `404`, `422` |

Example:

```bash
curl -X PATCH -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"enabled":true,"fallback_playlist_id":12,"min_queue_size":3,"avoid_repeats":true}' \
  https://panel.example/api/v1/customer/musicbots/42/autodj
```

Auto-DJ only selects local uploaded tracks/playlists. It does not scrape external music services.

### Workflows

| Method | Path | Auth | Permission | Request body | Response | Errors |
| --- | --- | --- | --- | --- | --- | --- |
| `GET` | `/api/v1/customer/musicbots/{id}/workflows` | Customer | Own instance; workflow feature if enforced by service | None | `data: Workflow[]` | `401`, `404` |
| `POST` | `/api/v1/customer/musicbots/{id}/workflows` | Customer | Own instance; workflow quota | `name`, `trigger_type`, optional `trigger_config`, `description`, `enabled`, `conditions`, `actions` | `201`, `data: Workflow` | `401`, `403`, `404`, `422` |
| `GET` | `/api/v1/customer/musicbots/{id}/workflows/{wid}` | Customer | Own workflow | None | `data: Workflow` | `401`, `404` |
| `PUT`/`PATCH` | `/api/v1/customer/musicbots/{id}/workflows/{wid}` | Customer | Own workflow | Partial workflow fields | `data: Workflow` | `401`, `404`, `422` |
| `DELETE` | `/api/v1/customer/musicbots/{id}/workflows/{wid}` | Customer | Own workflow | None | `204` empty | `401`, `404` |
| `POST` | `/api/v1/customer/musicbots/{id}/workflows/{wid}/toggle` | Customer | Own workflow | `{ "enabled": true }` (optional; toggles if omitted) | `data: Workflow` | `401`, `404` |
| `POST` | `/api/v1/customer/musicbots/{id}/workflows/{wid}/test` | Customer | Own workflow | `{ "context": {} }` | `data: WorkflowExecution` | `401`, `404`, `422` |
| `GET` | `/api/v1/customer/musicbots/{id}/workflows/{wid}/executions` | Customer | Own workflow | Query `limit`, max `100` | `data: WorkflowExecution[]` | `401`, `404` |

Example:

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"name":"Queue empty Auto-DJ","trigger_type":"queue.empty","actions":[{"type":"autodj.run"}]}' \
  https://panel.example/api/v1/customer/musicbots/42/workflows
```

### Webradio/stream settings

| Method | Path | Auth | Permission | Request body | Response | Errors |
| --- | --- | --- | --- | --- | --- | --- |
| `GET` | `/api/v1/customer/musicbots/{id}/stream` | Customer | `musicbot.webradio.manage`; `allow_webradio` where quota service enforces | None | Stream settings | `401`, `403`, `404` |
| `PUT`/`PATCH` | `/api/v1/customer/musicbots/{id}/stream` | Customer | `musicbot.webradio.manage`; `allow_webradio` | `enabled`, `public_slug`, `access_mode`, `stream_title`, `bitrate`, `format`, `current_mount_path` | Stream settings | `400`, `401`, `403`, `404`, `422` |
| `POST` | `/api/v1/customer/musicbots/{id}/stream/enable` | Customer | `musicbot.webradio.manage`; `allow_webradio` | None | Stream settings | `401`, `403`, `404`, `422` |
| `POST` | `/api/v1/customer/musicbots/{id}/stream/disable` | Customer | `musicbot.webradio.manage` | None | Stream settings | `401`, `403`, `404` |
| `POST` | `/api/v1/customer/musicbots/{id}/stream/rotate-token` | Customer | `musicbot.webradio.manage`; `allow_webradio` | None | Stream settings plus one-time `new_token` | `401`, `403`, `404`, `422` |
| `GET` | `/api/v1/customer/musicbots/{id}/stream/status` | Customer | `musicbot.webradio.manage` | None | Stream status | `401`, `403`, `404` |

Example:

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" \
  https://panel.example/api/v1/customer/musicbots/42/stream/rotate-token
```

The returned `new_token` is shown only on rotation. Stored stream tokens are hashed. The current stream backend is a placeholder; status fields such as `backend_available`, `stream_ready` or `placeholder_notice` indicate that real stream output is not active yet.

### Customer secrets

There is currently no customer REST endpoint for Musicbot connection secrets. Secret read/update/rotate endpoints are admin-only. Customer-facing secret updates, where allowed, must use the panel workflows currently implemented outside the `/api/v1/customer/.../secrets` REST surface.

---

## Admin endpoints

### Musicbots list/create/show/update/delete

| Method | Path | Auth | Permission | Request body | Response | Errors |
| --- | --- | --- | --- | --- | --- | --- |
| `GET` | `/api/v1/admin/musicbots` | Admin | Admin-only | Optional filters if added by caller; current implementation returns repository list | `data: MusicbotInstance[]` | `403` |
| `POST` | `/api/v1/admin/musicbots` | Admin | Admin-only; customer limits | `customer_id`, `node_id`, `name`, optional resource limits and connection flags/config | `201`, `data: MusicbotInstance`, `job_id` | `400`, `403`, `422` |
| `GET` | `/api/v1/admin/musicbots/{id}` | Admin | Admin-only | None | `data: MusicbotInstance` | `403`, `404` |
| `PATCH` | `/api/v1/admin/musicbots/{id}` | Admin | Admin-only | Partial instance fields such as `name`, `cpu_limit`, `ram_limit`, `disk_limit` | `data: MusicbotInstance` | `403`, `404` |
| `DELETE` | `/api/v1/admin/musicbots/{id}` | Admin | Admin-only | None | `data.deleted`, `data.job_id` | `403`, `404` |

Example create:

```bash
curl -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{"customer_id":7,"node_id":"node-1","name":"Radio Bot","teamspeak_enabled":false,"discord_enabled":false}' \
  https://panel.example/api/v1/admin/musicbots
```

### Admin actions and connection tests

Admin service actions (`install`, `uninstall`, `start`, `stop`, `restart`, `status`, `repair`, `update`) and connection tests exist in the admin panel/controller flow, but the current `/api/v1/admin/musicbots/...` REST controller does not expose a generic action or connection-test endpoint. API clients should use the panel route or wait for a dedicated REST endpoint rather than assuming one exists.

### Connections and secrets

Connections are included in Musicbot instance responses. Dedicated REST endpoints currently cover admin secret read/update/rotate only.

| Method | Path | Auth | Permission | Request body | Response | Errors |
| --- | --- | --- | --- | --- | --- | --- |
| `GET` | `/api/v1/admin/musicbots/{id}/connections/{connId}/secrets` | Admin | Admin-only | None | `connection_id`, `platform`, masked `secrets` | `403`, `404` |
| `PATCH` | `/api/v1/admin/musicbots/{id}/connections/{connId}/secrets` | Admin | Admin-only | Allowed secret keys, e.g. `bot_token`, `server_password`, `channel_password` | Masked `secrets` | `400`, `403`, `404` |
| `POST` | `/api/v1/admin/musicbots/{id}/connections/{connId}/secrets/rotate` | Admin | Admin-only | `{ "key": "bot_token", "value": "new secret" }` | `rotated_key`, masked `secrets` | `400`, `403`, `404` |

Example:

```bash
curl -X PATCH -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{"bot_token":"discord-token-value"}' \
  https://panel.example/api/v1/admin/musicbots/42/connections/12/secrets
```

Tokens/passwords are encrypted or hashed server-side and are never returned in plaintext.

### Limits and overrides

| Method | Path | Auth | Permission | Request body | Response | Errors |
| --- | --- | --- | --- | --- | --- | --- |
| `GET` | `/api/v1/admin/musicbots/{id}/limits` | Admin | Admin-only | None | Effective limits plus customer overrides | `403`, `404` |
| `PATCH` | `/api/v1/admin/musicbots/{id}/limits` | Admin | Admin-only | Any override field: `max_musicbots`, `max_tracks`, `max_storage_mb`, `max_playlists`, `max_plugins`, `max_queue_items`, `max_connections`, `max_upload_size_mb`, `allow_teamspeak`, `allow_discord`, `allow_teamspeak6_profile`, `allow_webradio`, `allow_plugins`, `allow_workflows`, `allow_scheduler`, `granted_permissions` | Updated limits | `400`, `403`, `404` |

Example:

```bash
curl -X PATCH -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{"allow_webradio":true,"granted_permissions":["musicbot.webradio.manage"]}' \
  https://panel.example/api/v1/admin/musicbots/42/limits
```

### Logs

| Method | Path | Auth | Permission | Query | Response | Errors |
| --- | --- | --- | --- | --- | --- | --- |
| `GET` | `/api/v1/admin/musicbots/{id}/logs` | Admin | Admin-only | `limit` optional, max `200` | `data: RuntimeEvent[]` | `403`, `404` |

### Plugins

| Method | Path | Auth | Permission | Request body | Response | Errors |
| --- | --- | --- | --- | --- | --- | --- |
| `GET` | `/api/v1/admin/musicbot-plugins` | Admin | Admin-only | None | Available plugin manifests | `403` |
| `POST` | `/api/v1/admin/musicbots/{id}/plugins` | Admin | Admin-only; plugin quota | `{ "identifier": "metadata.example" }` | `201`, assigned plugin | `400`, `403`, `404`, `422` |
| `PATCH` | `/api/v1/admin/musicbot-plugins/{pluginId}` | Admin | Admin-only | `{ "enabled": true, "config": {} }` | Updated plugin | `400`, `403`, `404` |

### Schedules

Customer-owned schedules are managed by customer instance endpoints. Admin REST endpoints are read/disable oriented:

| Method | Path | Auth | Permission | Request body/query | Response | Errors |
| --- | --- | --- | --- | --- | --- | --- |
| `GET` | `/api/v1/admin/musicbot-schedules` | Admin | Admin-only | Optional `customer_id`, `instance_id`, `enabled` if supported by repository query | `data: Schedule[]` | `403` |
| `GET` | `/api/v1/admin/musicbot-schedules/{id}` | Admin | Admin-only | None | `data: Schedule` | `403`, `404` |
| `POST` | `/api/v1/admin/musicbot-schedules/{id}/disable` | Admin | Admin-only | None | Disabled schedule | `403`, `404` |

### Workflows

Customer-owned workflows are managed by customer instance endpoints. Admin REST endpoints are read/disable oriented:

| Method | Path | Auth | Permission | Request body/query | Response | Errors |
| --- | --- | --- | --- | --- | --- | --- |
| `GET` | `/api/v1/admin/musicbot-workflows` | Admin | Admin-only | Optional filters if supported by repository query | `data: Workflow[]` | `403` |
| `GET` | `/api/v1/admin/musicbot-workflows/{id}` | Admin | Admin-only | None | `data: Workflow` | `403`, `404` |
| `POST` | `/api/v1/admin/musicbot-workflows/{id}/disable` | Admin | Admin-only | None | Disabled workflow | `403`, `404` |

### Auto-DJ

| Method | Path | Auth | Permission | Request body | Response | Errors |
| --- | --- | --- | --- | --- | --- | --- |
| `GET` | `/api/v1/admin/musicbots/{id}/autodj` | Admin | Admin-only | None | Auto-DJ settings | `403`, `404` |
| `PATCH` | `/api/v1/admin/musicbots/{id}/autodj` | Admin | Admin-only | Same fields as customer Auto-DJ settings | Auto-DJ settings | `403`, `404`, `422` |

### Webradio/stream

| Method | Path | Auth | Permission | Request body | Response | Errors |
| --- | --- | --- | --- | --- | --- | --- |
| `GET` | `/api/v1/admin/musicbots/{id}/stream` | Admin | Admin-only | None | Stream settings | `403`, `404` |
| `PATCH` | `/api/v1/admin/musicbots/{id}/stream` | Admin | Admin-only; customer `allow_webradio` still enforced by service | Stream setting fields | Stream settings | `400`, `403`, `404`, `422` |
| `POST` | `/api/v1/admin/musicbots/{id}/stream/enable` | Admin | Admin-only; customer `allow_webradio` still enforced by service | None | Stream settings | `403`, `404`, `422` |
| `POST` | `/api/v1/admin/musicbots/{id}/stream/disable` | Admin | Admin-only | None | Stream settings | `403`, `404` |
| `POST` | `/api/v1/admin/musicbots/{id}/stream/rotate-token` | Admin | Admin-only; customer `allow_webradio` still enforced by service | None | Stream settings plus one-time `new_token` | `403`, `404`, `422` |
| `GET` | `/api/v1/admin/musicbots/{id}/stream/status` | Admin | Admin-only | None | Stream status | `403`, `404` |

---

## Security model

### Customer isolation

Customer endpoints call customer-scoped lookup paths. A customer can only see or mutate Musicbot instances, queue items, tracks, playlists, plugins, schedules and workflows belonging to that customer. Cross-customer IDs are treated as not found.

### Admin-only endpoints

Admin routes require an admin user and can access Musicbot instances across customers. Admin endpoints must be protected at the API gateway/session layer and should be audited.

### Secrets

- Connection secret endpoints are admin-only in the current REST API.
- Secret values are accepted only in request bodies and normalized/masked in responses.
- Runtime status and connection status must not expose bot tokens, TeamSpeak passwords or stream tokens.
- Stream token rotation returns `new_token` once; the stored value is hashed.

### Quotas and feature gates

The API relies on Musicbot quota services for limits such as maximum Musicbots, tracks, storage, playlists, plugins, queue items, connections and upload size. Feature flags include TeamSpeak, Discord, TeamSpeak 6 profile, Webradio, plugins, workflows and scheduler. Exceeded quota or disabled feature responses are typically `403` or `422` depending on the service path.

### Permissions

The documented explicit permission check in the current customer REST API is `musicbot.webradio.manage` for stream management. Additional feature availability is enforced through quota/limit flags such as `allow_webradio`, `allow_plugins`, `allow_workflows` and `allow_scheduler`.

### Rate limits

No Musicbot-specific REST rate-limit headers or endpoint-specific throttles are exposed by the current controller. Any rate limiting is expected to be enforced by the global platform/API gateway configuration if enabled.

### Placeholder boundaries

- TeamSpeak and Discord connection capability status can report backend-required/placeholder states.
- Webradio stream status can expose placeholder URLs/notices, but real stream output is not active until a production stream backend is integrated.
- Audio decoding currently uses the prepared runtime pipeline/dummy components; the API must not claim production decoding of all uploaded formats beyond accepted upload/library behavior.
- No YouTube, Spotify or other external music scraping is provided or documented.
