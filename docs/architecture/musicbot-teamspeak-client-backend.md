# Musicbot TeamSpeak Client Backend

## Scope

The Musicbot TeamSpeak integration is prepared as a real client-backend layer. It is intentionally **not** a ServerQuery audio implementation: TeamSpeak ServerQuery can administer/query servers, but it cannot join as an audio client or send voice packets.

## Backend model

TeamSpeak 3 and TeamSpeak 6 use the same `ts3_client_compatible` path with a profile flag:

- `profile=ts3` for TeamSpeak 3.
- `profile=ts6` for TeamSpeak 6 using the same client-compatible backend abstraction.

Runtime backend types:

- `placeholder`: default, validates configuration but is not an audio client.
- `native_sdk`: prepared adapter for an installed TeamSpeak native SDK/library backend.
- `external_client_bridge`: prepared adapter for an explicitly configured local bridge binary.
- `disabled`: connector intentionally disabled at backend level.

Configuration fields include `host`, `port`, `nickname`, `identity_path`, `channel_id`, optional `backend_path`, and secret-managed `server_password` / `channel_password`.

## Native SDK backend

`native_sdk` checks for configured SDK/library files before it can be used. If those files are missing, runtime reports:

> TeamSpeak native SDK backend is not installed.

No fake native implementation is provided. Audio sending only becomes possible once a real SDK-backed client implementation is wired in and reports ready.

## External bridge backend

`external_client_bridge` requires a validated `backend_path`. The runtime does not start arbitrary or missing processes. Known third-party musicbot binaries such as SinusBot and TS3AudioBot are rejected by name.

If no valid bridge is configured, runtime reports:

> TeamSpeak external client bridge is not configured.

This is the currently implemented real TeamSpeak client-layer integration path. The runtime starts the configured bridge executable directly (no shell) and communicates over newline-delimited JSON on stdin/stdout. The bridge protocol supports `connect`, `disconnect`, `join_channel`, `send_opus_frame`, `status`, and `reconnect`. Opus frames are base64 encoded in `send_opus_frame` requests.

`native_sdk` remains a guarded stub until official SDK/library files and bindings are installed. It must continue to fail clearly instead of pretending that audio is available.

## Non-goals and safety boundaries

- No reverse engineering.
- No SinusBot dependency.
- No TS3AudioBot dependency.
- No ServerQuery audio claims.
- No false ready state: `connected=true` and `voice_client_available=true` are only valid once a real client backend is connected.
- Secrets are stored through `SecretConfig` and must never be rendered in status payloads or UI.

## Status semantics

Customer-facing status should map runtime fields as follows:

- `capability_status=client_backend_required`: **TeamSpeak Client Backend fehlt**.
- `capability_status=ready` and `connected=false`: **TeamSpeak Backend bereit**.
- `capability_status=ready` and `connected=true`: **TeamSpeak verbunden**.

The placeholder backend is never a real audio client and must remain clearly distinguishable from installed backends.

## Easy-Wi TeamSpeak Integration Plugin

The first-party `easywi.teamspeak.integration` plugin is an internal Musicbot runtime bridge. It is **not** a SinusBot plugin, **not** a TS3AudioBot plugin, and **not** a TeamSpeak server plugin.

Prepared runtime components:

- `TeamSpeakIntegrationPlugin` owns instance-scoped command/event handling.
- `TeamSpeakCommandRouter` parses prefixed TeamSpeak text commands.
- `TeamSpeakPermissionMapper` maps TeamSpeak server/channel groups to Musicbot permissions.
- `TeamSpeakEventBridge` forwards TeamSpeak/musicbot events to workflow triggers.
- `TeamSpeakChatResponder` sends sanitized replies back through the TeamSpeak client bridge.

Supported commands are `!help`, `!play`, `!pause`, `!resume`, `!stop`, `!skip`, `!queue`, `!volume`, `!shuffle`, `!repeat`, `!playlist`, `!autodj`, and `!status`; the prefix is configurable. Command execution remains routed through runtime/core control paths so commands cannot control another Musicbot instance.

Workflow-capable event names include `user.joined`, `user.left`, `channel.joined`, `channel.left`, `text.message`, `bot.connected`, `bot.disconnected`, `playback.started`, `playback.stopped`, `queue.empty`, and `text.command`.
