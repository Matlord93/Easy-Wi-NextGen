# TeamSpeak External Client Bridge Protocol

Version: 1  
Status: specification  
Scope: `external_client_bridge` backend in `agent/internal/musicbot/runtime`

---

## 1. Purpose and scope

The External Client Bridge is the currently implemented real TeamSpeak client-layer integration path for the Musicbot. It allows a separately built, locally installed bridge binary to act as the TeamSpeak client without the Musicbot runtime containing any TeamSpeak client library directly.

The runtime starts the configured bridge binary as a subprocess, sends commands over stdin, and reads responses from stdout. This document defines the complete interface a bridge binary must implement.

**What this protocol is:**
- A local IPC protocol between the Musicbot runtime and a bridge subprocess.
- Defined only for `external_client_bridge` backend type.
- The runtime-side implementation is in `teamspeak_backends.go` (`ExternalBridgeTeamspeakVoiceClient`).

**What this protocol is not:**
- Not the TeamSpeak network protocol.
- Not an HTTP or TCP API.
- Not a plugin system for third-party musicbot software.

---

## 2. Process lifecycle

### 2.1 Start

The runtime starts the bridge binary using a direct `exec.Command(path)` call. No shell is involved. The bridge receives:

- **stdin**: the command channel (newline-delimited JSON).
- **stdout**: the response channel (newline-delimited JSON).
- **stderr**: available for bridge-internal logging. The runtime does not read stderr.
- **Environment**: the full environment of the agent process plus `EASYWI_TS_BRIDGE=1`.

The bridge must be ready to accept the first command on stdin immediately after startup. It must not print any non-JSON lines to stdout before responding to the first command.

### 2.2 Shutdown sequence

Graceful shutdown is initiated by the runtime in one of two ways:

1. The runtime sends a `disconnect` command and waits for the response, then closes stdin (EOF).
2. If the bridge does not exit after stdin is closed, the runtime sends `SIGKILL` and calls `Wait`.

The bridge must:
1. Handle EOF on stdin as a signal to clean up and exit.
2. Exit promptly after sending the `disconnect` response or after receiving EOF, whichever comes first.
3. Never block shutdown on in-flight audio delivery.

### 2.3 Process management invariants

- The bridge must not spawn shell child processes as intermediaries.
- The bridge must exit when the runtime exits or kills it. Orphaned bridge processes are not supported.
- The runtime may kill the bridge at any time without prior notice (for example, on agent shutdown or context cancellation).

---

## 3. Protocol fundamentals

### 3.1 Framing

All messages are JSON objects serialized as a single UTF-8 line, terminated by a `\n` (LF, 0x0A). No multi-line JSON. No leading or trailing whitespace on lines, except the terminating `\n`.

```
<json-object>\n
```

### 3.2 Direction

```
runtime  ──stdin──▶  bridge    (requests)
runtime  ◀──stdout──  bridge   (responses)
```

One request produces exactly one response. The protocol is synchronous: the runtime sends one request and blocks reading until it receives one response line. The bridge must not send unsolicited messages.

### 3.3 Character encoding

UTF-8 throughout. Binary data (Opus frames) is base64-encoded in JSON string fields.

### 3.4 Synchronous call model

The runtime sends requests one at a time. The bridge never sees two concurrent requests. No request ID field is used because each response is unambiguously correlated to the last request sent. The bridge must respond to each request before the next one arrives.

---

## 4. Message format

### 4.1 Request

```json
{
  "action": "<string>",
  "<field>": <value>,
  ...
}
```

The `action` field is always present. All other fields are optional and action-specific. Fields not relevant to the action must be ignored by the bridge.

Full request field reference:

| Field             | Type    | Description |
|-------------------|---------|-------------|
| `action`          | string  | Command name (required). |
| `host`            | string  | TeamSpeak server hostname or IP. |
| `port`            | integer | TeamSpeak server UDP port (default 9987). |
| `profile`         | string  | Client profile: `"ts3"` or `"ts6"`. |
| `nickname`        | string  | Client display name on the server. |
| `identity_path`   | string  | Filesystem path to a TS3 identity file. May be empty if the bridge manages identity internally. |
| `server_password` | string  | Server join password. Only present when the Musicbot instance has one configured. Contains the secret value; see section 9 for handling rules. |
| `channel_id`      | string  | Target channel ID (string representation of the numeric TS3 channel ID). |
| `channel_password`| string  | Channel join password. Only present when configured. Contains the secret value. |
| `format`          | string  | Audio frame codec identifier. Always `"opus"` for `send_opus_frame`. |
| `payload`         | string  | Base64-encoded audio frame bytes. |
| `duration_ms`     | integer | Declared frame duration in milliseconds. |

### 4.2 Response

```json
{
  "ok": <bool>,
  "error": "<string>",
  "state": "<string>",
  "client_id": "<string>",
  "channel_id": "<string>"
}
```

Full response field reference:

| Field       | Type    | Present when | Description |
|-------------|---------|--------------|-------------|
| `ok`        | boolean | Always       | `true` on success, `false` on failure. |
| `error`     | string  | `ok=false`   | Human-readable error message. Must not contain secret values (passwords, tokens). |
| `state`     | string  | Optional     | Current connection state: `"connected"`, `"disconnected"`, `"connecting"`, or `"error"`. |
| `client_id` | string  | After connect | The bridge client's assigned ID on the TeamSpeak server. |
| `channel_id`| string  | After join   | The channel ID the client is currently in. |

### 4.3 Error format

When a command fails, the bridge must respond with:

```json
{"ok": false, "error": "human-readable description"}
```

Error messages must not contain:
- Server passwords.
- Channel passwords.
- Identity file contents.
- Any other credential or secret value.

Redact credentials with `[redacted]` if they would otherwise appear in the message (for example, in a server error string that echoes the password). The runtime applies its own masking layer on top of this, but the bridge must not rely on it.

---

## 5. Commands

### 5.1 `connect`

Connects the bridge client to a TeamSpeak server.

**Request:**
```json
{
  "action": "connect",
  "host": "ts.example.com",
  "port": 9987,
  "profile": "ts3",
  "nickname": "Musicbot",
  "identity_path": "/opt/easywi/musicbot/identity.ini",
  "server_password": "[from SecretConfig]"
}
```

`port` defaults to 9987 if absent or zero.  
`profile` is `"ts3"` or `"ts6"`.  
`identity_path` may be empty; the bridge may generate or maintain its own identity.  
`server_password` is absent when no password is configured.

**Success response:**
```json
{
  "ok": true,
  "state": "connected",
  "client_id": "42"
}
```

`client_id` is the client's numeric ID assigned by the server, as a string. The runtime stores it and uses it to identify the bot in subsequent operations.

**Failure response:**
```json
{
  "ok": false,
  "error": "connection refused: server unreachable"
}
```

After a failed `connect`, the bridge remains in disconnected state. The runtime may retry by sending another `connect` or may fall back to `reconnect`.

---

### 5.2 `disconnect`

Disconnects from the server and initiates graceful shutdown of the bridge process.

**Request:**
```json
{"action": "disconnect"}
```

**Success response:**
```json
{"ok": true, "state": "disconnected"}
```

After sending the response, the bridge must release all server connections, then exit when stdin reaches EOF. The runtime closes stdin immediately after receiving the response.

**Failure response:**
```json
{"ok": false, "error": "bridge was already disconnected"}
```

A disconnect failure does not block process exit. The runtime will still close stdin and kill the process if necessary.

---

### 5.3 `reconnect`

Attempts a reconnect without restarting the bridge process. The bridge should re-use its existing configuration (from the previous `connect`) to re-establish the server connection.

**Request:**
```json
{"action": "reconnect"}
```

**Success response:**
```json
{
  "ok": true,
  "state": "connected",
  "client_id": "43"
}
```

`client_id` may change after a reconnect if the server assigns a new ID.

**Failure response:**
```json
{"ok": false, "error": "reconnect failed: server returned error 520"}
```

If `reconnect` fails, the runtime falls back to `Disconnect` followed by a fresh `Connect` call (which restarts the bridge process).

---

### 5.4 `join_channel`

Joins the client into a specific channel.

**Request:**
```json
{
  "action": "join_channel",
  "channel_id": "5",
  "channel_password": "[from SecretConfig, may be absent]"
}
```

`channel_id` is always present and non-empty. `channel_password` is absent when no password is configured.

**Success response:**
```json
{
  "ok": true,
  "channel_id": "5"
}
```

The `channel_id` in the response should echo the channel the client actually joined (which may differ if the server redirected the client).

**Failure response:**
```json
{"ok": false, "error": "channel not found or access denied"}
```

---

### 5.5 `leave_channel`

Moves the client to the server's default channel (typically channel 1) or a designated AFK channel, effectively leaving the current audio channel.

**Request:**
```json
{"action": "leave_channel"}
```

**Success response:**
```json
{"ok": true}
```

**Failure response:**
```json
{"ok": false, "error": "not in a channel"}
```

---

### 5.6 `set_nickname`

Changes the client's displayed nickname on the server.

**Request:**
```json
{
  "action": "set_nickname",
  "nickname": "Musicbot [DJ]"
}
```

`nickname` is always present and non-empty.

**Success response:**
```json
{"ok": true}
```

**Failure response:**
```json
{"ok": false, "error": "nickname rejected by server"}
```

---

### 5.7 `send_opus_frame`

Sends a single Opus audio frame to the current channel. The client must be connected and in a channel before frames can be sent.

**Request:**
```json
{
  "action": "send_opus_frame",
  "format": "opus",
  "payload": "T2dnUwAC...",
  "duration_ms": 20
}
```

`format` is always `"opus"`.  
`payload` is the raw Opus packet bytes, base64-encoded (standard encoding, `+` and `/`, with `=` padding).  
`duration_ms` is the declared frame duration in milliseconds. See section 6 for timing requirements.

**Success response:**
```json
{"ok": true}
```

**Failure response:**
```json
{"ok": false, "error": "not in a voice channel"}
```

The bridge must respond to each `send_opus_frame` promptly. If the bridge cannot deliver the frame within the frame's declared duration, it should drop the frame and respond with `ok=true` rather than accumulating backpressure. Late audio is worse than missing audio.

---

### 5.8 `status`

Queries the current state of the bridge client. Used by the runtime after `connect` to verify the connection and periodically for health checks.

**Request:**
```json
{"action": "status"}
```

**Success response (connected):**
```json
{
  "ok": true,
  "state": "connected",
  "client_id": "42",
  "channel_id": "5"
}
```

**Success response (disconnected):**
```json
{
  "ok": true,
  "state": "disconnected"
}
```

`channel_id` is omitted when the client is not in a channel.  
`client_id` is omitted when the client is not connected.

The `status` command must always return `ok=true` with the actual state; it must not return `ok=false` unless the bridge itself has an unrecoverable internal error that prevents it from reporting state.

---

## 6. Audio format

### 6.1 Codec

The bridge receives Opus encoded audio only. The runtime produces Opus frames from its internal audio pipeline (FFmpeg → Opus encoder or direct Opus source). The bridge does not receive PCM.

### 6.2 Standard parameters

| Parameter    | Value    | Notes |
|--------------|----------|-------|
| Codec        | Opus     | RFC 6716 |
| Sample rate  | 48000 Hz | Opus standard |
| Channels     | 1 (mono) | TeamSpeak voice is mono |
| Frame size   | 20 ms    | 960 samples at 48000 Hz |
| Bitrate      | Variable | Determined by the runtime's encoder |

The `duration_ms` field in `send_opus_frame` carries the per-frame duration and is always a positive integer. The runtime currently produces 20 ms frames.

### 6.3 Base64 encoding

Frames are encoded with standard base64 (RFC 4648 §4): characters `A–Z`, `a–z`, `0–9`, `+`, `/`, with `=` padding. The bridge decodes this to a byte slice and passes it to the TeamSpeak client layer as a raw Opus packet.

### 6.4 Timing

The runtime sends frames at approximately the frame rate derived from `duration_ms` (one frame per 20 ms for standard 20 ms frames). The bridge must transmit frames without buffering them in a queue that could cause drift. If the bridge cannot deliver a frame in time, it must drop it silently and acknowledge the request immediately.

The runtime does not rely on the bridge to pace audio. All pacing is done by the runtime's audio pipeline.

---

## 7. Connection state machine

The bridge should maintain and report the following states in `state` response fields:

```
                  ┌──────────────┐
       start/EOF  │ disconnected │ ◀──── disconnect / leave/error
                  └──────┬───────┘
                         │ connect (ok)
                         ▼
                  ┌──────────────┐
                  │  connected   │ ──── join_channel ──▶ in channel
                  └──────────────┘ ◀── leave_channel ────────────┘
                         │
                         │ reconnect (ok)
                         ▼
                  ┌──────────────┐
                  │  connected   │  (re-established)
                  └──────────────┘
```

State values used in responses:

| State          | Meaning |
|----------------|---------|
| `connected`    | Client is connected to the server and authenticated. |
| `disconnected` | Client is not connected. |
| `connecting`   | Client is in the process of connecting (may be returned by `status` if asynchronous connect is supported). |
| `error`        | Bridge has encountered an unrecoverable error. |

The runtime considers `state=connected` as the only valid state for sending audio frames.

---

## 8. Status field reference

Fields the bridge should provide in `status` responses and their mapping to the connector status reported to the panel:

| Bridge field  | Type   | Maps to connector status field | Notes |
|---------------|--------|-------------------------------|-------|
| `ok`          | bool   | — (protocol level)            | Must be `true` for a valid status response. |
| `state`       | string | `state`                        | `"connected"` → `ConnectionStateConnected`. |
| `client_id`   | string | `client_id`                    | Cleared on disconnect. |
| `channel_id`  | string | `channel_id`                   | Cleared on leave/disconnect. |

The runtime derives the following higher-level status fields from the bridge state:

| Connector status field   | Value when bridge is `connected` | Value when bridge is `disconnected` |
|--------------------------|----------------------------------|-------------------------------------|
| `connected`              | `true`                           | `false`                             |
| `voice_client_available` | `true`                           | `false`                             |
| `capability_status`      | `ready`                          | `client_backend_required`           |
| `output_backend`         | `teamspeak_voice`                | `null`                              |

`capability_status=ready` and `output_backend=teamspeak_voice` are only ever set when the bridge reports `state=connected`. These fields must never be set without a real, confirmed bridge connection.

---

## 9. Security rules

### 9.1 Path validation

The runtime validates `backend_path` before starting the bridge:

1. The file must exist and not be a directory.
2. The file must be executable (mode bits include at least one execute bit).
3. The filename (basename, lowercased) must not contain `sinusbot` or `ts3audiobot`.

A bridge that fails these checks is rejected before process start with:
> TeamSpeak external client bridge is not configured.

### 9.2 No shell

The bridge is started with `exec.Command(path)` — no shell interpreter. The bridge binary must be a directly executable file (ELF binary, script with a valid shebang line, etc.).

### 9.3 Secret handling

- `server_password` and `channel_password` are passed in request fields when configured. They originate from the `SecretConfig` store; they are never stored in plaintext in the panel database, log files, or status payloads.
- The bridge must not log secret values to stderr or any other output.
- The bridge must not include secret values in `error` response fields. If a server error string echoes a password, replace it with `[redacted]` before including it in the error field.
- The runtime applies its own masking pass on all error strings from the bridge, but this is a safety net, not a substitute for bridge-side redaction.

### 9.4 Environment isolation

The bridge receives `EASYWI_TS_BRIDGE=1` in its environment. This marker can be used by the bridge to confirm it is running under the expected caller. The bridge must not rely on other environment variables for configuration; all runtime configuration is passed through the `connect` request.

### 9.5 stdout is JSON-only

The bridge must write only valid JSON lines to stdout. Any non-JSON output on stdout will cause a parse error in the runtime, which will treat it as a bridge failure. All human-readable log output must go to stderr.

### 9.6 Secrets in stderr

The bridge may write diagnostic logs to stderr. These logs must not contain server passwords, channel passwords, or identity file content. Log connection success/failure without credential details.

---

## 10. Logging rules

| Output   | Purpose                        | Allowed content |
|----------|--------------------------------|-----------------|
| stdout   | Protocol responses only        | JSON lines conforming to section 4.2. No other output. |
| stderr   | Bridge-internal diagnostics    | Connection events, errors, timing. No secret values. |

The runtime does not capture or forward stderr. It is available to the bridge for local debugging only.

---

## 11. Example protocol exchanges

### 11.1 Full connect → join → audio → leave → disconnect sequence

```
→ stdin:  {"action":"connect","host":"ts.example.com","port":9987,"profile":"ts3","nickname":"Musicbot","identity_path":"/opt/easywi/musicbot/identity.ini"}
← stdout: {"ok":true,"state":"connected","client_id":"42"}

→ stdin:  {"action":"join_channel","channel_id":"5"}
← stdout: {"ok":true,"channel_id":"5"}

→ stdin:  {"action":"send_opus_frame","format":"opus","payload":"T2dnUwACAAAAAAAAAAA...","duration_ms":20}
← stdout: {"ok":true}

→ stdin:  {"action":"send_opus_frame","format":"opus","payload":"T2dnUwACAAAAAAAAAAB...","duration_ms":20}
← stdout: {"ok":true}

→ stdin:  {"action":"leave_channel"}
← stdout: {"ok":true}

→ stdin:  {"action":"disconnect"}
← stdout: {"ok":true,"state":"disconnected"}
[stdin closed by runtime → bridge exits]
```

### 11.2 Status query while connected

```
→ stdin:  {"action":"status"}
← stdout: {"ok":true,"state":"connected","client_id":"42","channel_id":"5"}
```

### 11.3 Status query while disconnected

```
→ stdin:  {"action":"status"}
← stdout: {"ok":true,"state":"disconnected"}
```

### 11.4 Connect with server password (secret value omitted from example)

```
→ stdin:  {"action":"connect","host":"ts.example.com","port":9987,"profile":"ts3","nickname":"Musicbot","identity_path":"","server_password":"[secret]"}
← stdout: {"ok":true,"state":"connected","client_id":"7"}
```

### 11.5 Reconnect after connection loss

```
→ stdin:  {"action":"reconnect"}
← stdout: {"ok":true,"state":"connected","client_id":"7"}
```

### 11.6 Reconnect failure followed by runtime-initiated full reconnect

```
→ stdin:  {"action":"reconnect"}
← stdout: {"ok":false,"error":"server unreachable"}

[Runtime calls Disconnect internally, kills bridge, starts new bridge process]

→ stdin:  {"action":"connect","host":"ts.example.com","port":9987,"profile":"ts3","nickname":"Musicbot","identity_path":""}
← stdout: {"ok":true,"state":"connected","client_id":"8"}
```

### 11.7 join_channel with password (secret value omitted)

```
→ stdin:  {"action":"join_channel","channel_id":"12","channel_password":"[secret]"}
← stdout: {"ok":true,"channel_id":"12"}
```

### 11.8 send_opus_frame failure (not in channel)

```
→ stdin:  {"action":"send_opus_frame","format":"opus","payload":"T2dnUwAC...","duration_ms":20}
← stdout: {"ok":false,"error":"not in a voice channel"}
```

### 11.9 Nickname change

```
→ stdin:  {"action":"set_nickname","nickname":"Musicbot [Paused]"}
← stdout: {"ok":true}
```

### 11.10 TS6 profile connect

```
→ stdin:  {"action":"connect","host":"ts6.example.com","port":9987,"profile":"ts6","nickname":"Musicbot","identity_path":""}
← stdout: {"ok":true,"state":"connected","client_id":"3"}
```

---

## 12. Bridge implementation checklist

A bridge implementation is considered conformant when it satisfies all of the following:

**Protocol**
- [ ] Reads one JSON line from stdin per command.
- [ ] Writes exactly one JSON line to stdout per command, terminated by `\n`.
- [ ] Writes nothing to stdout until a command is received.
- [ ] Handles EOF on stdin by cleaning up and exiting promptly.
- [ ] Never writes non-JSON content to stdout.
- [ ] All diagnostic output goes to stderr only.

**Commands**
- [ ] `connect`: connects to the TeamSpeak server, returns `client_id` in success response.
- [ ] `disconnect`: disconnects and prepares for process exit.
- [ ] `reconnect`: re-establishes connection using existing configuration without a new connect request.
- [ ] `join_channel`: joins the specified channel, returns `channel_id` in success response.
- [ ] `leave_channel`: moves client to default/lobby channel.
- [ ] `set_nickname`: changes nickname on the connected server.
- [ ] `send_opus_frame`: decodes base64 payload, delivers raw Opus packet to server, responds promptly.
- [ ] `status`: reports current `state`, `client_id`, and `channel_id` without error.
- [ ] Unknown `action` values are rejected with `{"ok":false,"error":"unknown action"}`.

**Audio**
- [ ] Decodes base64 payload with standard base64 (RFC 4648 §4).
- [ ] Passes raw Opus bytes to the TeamSpeak client without re-encoding.
- [ ] Responds to `send_opus_frame` within the frame duration (drops frame rather than blocking).
- [ ] Does not buffer more than one outstanding frame.

**Security**
- [ ] Does not log `server_password` or `channel_password` to stderr or any file.
- [ ] Does not include secret values in `error` response fields.
- [ ] Does not spawn a shell interpreter to handle commands.
- [ ] Does not start additional networked services.
- [ ] Accepts `EASYWI_TS_BRIDGE=1` as the sole runtime-provided configuration signal.

**State**
- [ ] Tracks `state` as `connected`, `disconnected`, `connecting`, or `error`.
- [ ] Reports `client_id` only when connected.
- [ ] Reports `channel_id` only when in a channel.
- [ ] Returns `state=connected` only when the TeamSpeak server has accepted the client.

---

## 13. Version and compatibility

This is version 1 of the bridge protocol. The runtime does not negotiate a version at startup. Future versions of this protocol will be documented separately. Bridge implementations should be built against a specific protocol version and updated when the runtime protocol version changes.

The runtime side is defined in `agent/internal/musicbot/runtime/teamspeak_backends.go`, struct types `teamspeakBridgeRequest` and `teamspeakBridgeResponse`, and method `bridgeRoundTrip`.
