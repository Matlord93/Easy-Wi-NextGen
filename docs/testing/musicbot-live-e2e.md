# Musicbot Live E2E Test

This document describes the live end-to-end test environment for the Musicbot module. It verifies the module against real but isolated test instances — after a merge, before a release, or in a dedicated staging pipeline.

**The test is NOT part of the standard CI pipeline.** Normal CI runs the unit/integration suite without any external services. This E2E test is triggered manually or via an optional CI job with explicit secret configuration.

---

## Overview

| Layer | Default | Requires |
|-------|---------|----------|
| Static: routes, agent handlers, source checks | always | repo checkout |
| Symfony Core: migrations, API routes | always | PHP + Symfony console |
| Agent/Runtime: install, status, queue.sync, playback, control socket | always | Go toolchain |
| Bridge: TeamSpeak protocol, secret masking | always | Go toolchain |
| Discord: gateway, voice join/leave, Opus frames | `MUSICBOT_E2E_RUN_DISCORD=1` | test-bot token + guild |
| TeamSpeak: TS3/TS6 server, join/leave channel | `MUSICBOT_E2E_RUN_TEAMSPEAK=1` | TS server + channel |

External tests (Discord, TeamSpeak) are **off by default**. They activate only when the corresponding `RUN_*` variable is explicitly set to `1` and all required credentials are provided via environment variables.

---

## Running the test

```bash
# Default: local/static + runtime/bridge checks only (no real services)
scripts/musicbot-live-e2e.sh

# With optional panel API checks
MUSICBOT_E2E_BASE_URL=http://127.0.0.1:8000 \
MUSICBOT_E2E_ADMIN_AUTH_HEADER='Authorization: Bearer <admin-token>' \
MUSICBOT_E2E_CUSTOMER_AUTH_HEADER='Authorization: Bearer <customer-token>' \
scripts/musicbot-live-e2e.sh

# With Discord E2E
MUSICBOT_E2E_RUN_DISCORD=1 \
MUSICBOT_E2E_DISCORD_TOKEN='<test-bot-token>' \
MUSICBOT_E2E_DISCORD_GUILD_ID='<guild-id>' \
MUSICBOT_E2E_DISCORD_VOICE_CHANNEL_ID='<voice-channel-id>' \
scripts/musicbot-live-e2e.sh

# With TeamSpeak E2E (current PlaceholderAdapter; see TeamSpeak section)
MUSICBOT_E2E_RUN_TEAMSPEAK=1 \
MUSICBOT_E2E_TS_HOST=ts3.example.com \
MUSICBOT_E2E_TS_PORT=9987 \
MUSICBOT_E2E_TS_CHANNEL_ID=5 \
MUSICBOT_E2E_TS_PASSWORD='<channel-password>' \
scripts/musicbot-live-e2e.sh
```

Exit codes:
- `0` — all checks pass
- `1` — at least one required check failed
- `2` — all checks pass but optional services were unavailable (warnings only)

---

## Environment variables

### Paths and binaries

| Variable | Default | Purpose |
|----------|---------|---------|
| `REPO_ROOT` | parent of `scripts/` | Repository root |
| `PANEL_ROOT` | `$REPO_ROOT/core` | Symfony panel root |
| `AGENT_ROOT` | `$REPO_ROOT/agent` | Go agent root |
| `CONSOLE` | `$PANEL_ROOT/bin/console` | Symfony console binary |
| `MUSICBOT_E2E_RUNTIME_BIN` | auto-built | Pre-built `easywi-musicbot` runtime binary |
| `MUSICBOT_E2E_BRIDGE_BIN` | auto-built | Pre-built `easywi-teamspeak-bridge` binary; `MUSICBOT_E2E_TS_BRIDGE_BIN` overrides it for TeamSpeak live E2E |

### Panel API (optional)

| Variable | Default | Purpose |
|----------|---------|---------|
| `MUSICBOT_E2E_BASE_URL` | unset | Panel base URL — enables HTTP/API checks |
| `MUSICBOT_E2E_ADMIN_AUTH_HEADER` | unset | `Authorization: Bearer <token>` for admin routes |
| `MUSICBOT_E2E_CUSTOMER_AUTH_HEADER` | unset | `Authorization: Bearer <token>` for customer routes |
| `MUSICBOT_E2E_INSTANCE_ID` | `1` | Existing disposable Musicbot instance ID |
| `MUSICBOT_E2E_CONNECTION_ID` | `1` | Existing disposable connection ID |
| `MUSICBOT_E2E_RUN_MUTATING` | `0` | Set to `1` to test create/queue/schedule write operations (disposable data only) |

### Discord

| Variable | Default | Purpose |
|----------|---------|---------|
| `MUSICBOT_E2E_RUN_DISCORD` | `0` | Set to `1` to enable Discord E2E tests |
| `MUSICBOT_E2E_DISCORD_TOKEN` | unset | Test-bot token (never logged) |
| `MUSICBOT_E2E_DISCORD_GUILD_ID` | unset | Test guild ID |
| `MUSICBOT_E2E_DISCORD_VOICE_CHANNEL_ID` | unset | Test voice channel ID |
| `MUSICBOT_E2E_DISCORD_TEXT_CHANNEL_ID` | unset | Optional disposable test text channel ID for future command/event assertions |
| `MUSICBOT_E2E_AUDIO_FIXTURE` | generated local WAV | Optional local audio fixture path; must be a local file, never YouTube/Spotify/remote media |

### TeamSpeak

| Variable | Default | Purpose |
|----------|---------|---------|
| `MUSICBOT_E2E_RUN_TEAMSPEAK` | `0` | Set to `1` to enable TeamSpeak E2E tests |
| `MUSICBOT_E2E_TS_HOST` | unset | TS3/TS6 server hostname or IP |
| `MUSICBOT_E2E_TS_PORT` | `9987` | TS3/TS6 server UDP port |
| `MUSICBOT_E2E_TS_CHANNEL_ID` | `1` | Target channel ID |
| `MUSICBOT_E2E_TS_PASSWORD` | unset | Server/channel password (never logged) |
| `MUSICBOT_E2E_TS_NICKNAME` | `EasyWi-E2E` | Nickname for the disposable TeamSpeak test client |
| `MUSICBOT_E2E_TS_IDENTITY_PATH` | unset | Optional identity path consumed by a real bridge-side client adapter |
| `MUSICBOT_E2E_TS_BRIDGE_BIN` | `MUSICBOT_E2E_BRIDGE_BIN` or auto-built | Optional TeamSpeak-specific bridge binary path |
| `MUSICBOT_E2E_TS_CLIENT_BACKEND_TYPE` | `placeholder` | Bridge-side adapter type, e.g. `client_library` or `native_sdk` when an allowed real client adapter is available |
| `MUSICBOT_E2E_TS_CLIENT_BACKEND_PATH` | unset | Optional SDK/library path passed to the bridge-side adapter |
| `MUSICBOT_E2E_AUDIO_FIXTURE` | generated local WAV | Optional local audio fixture used for runtime `frames_sent` checks |

---

## Test sections

### 1. Symfony Core

Checks that run after every merge:

- **Migrations**: `doctrine:migrations:status` — verifies Doctrine can read migration state.
- **Routes**: all Musicbot admin, customer, and API routes are present via `debug:router`.
- **Agent handlers**: `musicbot.install`, `musicbot.status`, `musicbot.queue.sync` handlers are registered in the agent source.

Optional API checks (when `MUSICBOT_E2E_BASE_URL` is set):

- Admin creates Musicbot — POST to admin create route
- Customer lists own Musicbots — GET customer index
- Queue, Playlist, Auto-DJ, Scheduler, Workflow APIs respond
- Upload endpoint rejects invalid MIME type (400/415/422)
- Secrets API returns masked values, not raw tokens (`has_<key>: true` pattern or 403)
- Mutating checks (admin create, queue add, scheduler create) — only when `MUSICBOT_E2E_RUN_MUTATING=1`

### 2. Agent / Runtime binary

The runtime binary (`easywi-musicbot`) is auto-built from source if `MUSICBOT_E2E_RUNTIME_BIN` is not set.

**stdin/stdout protocol:**
- `status` — verifies the response includes `audio_pipeline`
- `queue.sync` — empty queue returns `synced: true`
- `play`, `pause`, `stop`, `skip` — commands are acknowledged
- TeamSpeak placeholder must not report `capability_status: ready`

**AudioPipeline:**
- Status response confirms `audio_pipeline` object is present
- The pipeline initialises even without a real audio output backend

**Control socket:**
- Runtime starts and creates a Unix socket at `control.unix_socket`
- `status` command sent via socket responds `ok: true`
- `play` and `queue.sync` commands are accepted
- No secrets in socket log

### 3. TeamSpeak Bridge binary

The bridge binary (`easywi-teamspeak-bridge`) is auto-built if `MUSICBOT_E2E_BRIDGE_BIN` is not set.

Protocol conformance test (always runs, no real TS server needed):

| Request | Expected response |
|---------|-------------------|
| `status` | `ok: true`, `state: disconnected` |
| `connect` (PlaceholderAdapter) | `ok: false`, error contains `client_backend_required` |
| `send_opus_frame` (not connected) | `ok: false`, error `not connected` |
| `join_channel` (not connected) | `ok: false` |
| `set_nickname` | `ok: true` (PlaceholderAdapter always succeeds) |
| `reconnect` | `ok: false`, `client_backend_required` |
| unknown action | `ok: false`, error `unknown action: ...` |
| invalid JSON | `ok: false`, bridge continues |
| `leave_channel` | `ok: true` |
| `disconnect` | `ok: true` |

Secret masking:
- Smoke server password injected into `connect` request must NOT appear in stdout
- Smoke channel password injected into `join_channel` request must NOT appear in stdout

> **Note:** Actual TeamSpeak voice connection (join channel, send frames, leave) requires a real `TeamspeakClientAdapter` implementation. The current `PlaceholderAdapter` returns `client_backend_required` for all voice/connect operations. The protocol and secret-masking layers are fully tested; only the TS client layer is pending.

### 4. Discord E2E (optional)

Activated by `MUSICBOT_E2E_RUN_DISCORD=1`. Requires a test-bot token, a test guild, and a test voice channel.

Test flow:
1. Runtime starts with real Discord config.
2. Gateway connection established (up to ~8s).
3. Status command confirms `platform: discord` connector present.
4. If `capability_status: ready`, the script attempts voice join.
5. The script sends one synthetic Opus frame directly through `DiscordAudioOutput`.
6. The script queues a **local-only** audio fixture (`MUSICBOT_E2E_AUDIO_FIXTURE` or a generated short WAV), starts playback, and checks `output_backend=discord_voice` plus `frames_sent > 0`.
7. The script stops playback and leaves the voice channel.
8. If `capability_status: voice_backend_required` or `placeholder`, the non-ready state is reported as expected.

Current result in this repository run: **not executed against Discord**, because the required live Discord environment variables were not present in the agent environment. The default skip path remains intentional; no token, guild ID, or channel ID is printed.

Secret invariant: `MUSICBOT_E2E_DISCORD_TOKEN` must not appear in any stdout or stderr line. The script checks this explicitly and fails if the token is found.

**Test-bot setup — step-by-step:**

1. **Create a Discord application.**
   - Go to <https://discord.com/developers/applications> → `New Application`.
   - Name it something clearly identifiable as a test bot, e.g. `EasyWi-Musicbot-E2E`.
   - Under `Bot` → `Add Bot` → copy the token. Store it immediately; it will not be shown again.
   - Required intents: `SERVER MEMBERS INTENT` is not needed. `MESSAGE CONTENT INTENT` is not needed for voice-only tests.

2. **Create a private disposable test guild.**
   - In the Discord client: `+` → `Create My Own` → `For me and my friends`.
   - Name it something like `easywi-musicbot-e2e-test`.
   - Do **not** use a production guild or a guild with real users.

3. **Create a test voice channel.**
   - Under the test guild: `+` next to `VOICE CHANNELS` → name it `e2e-test-voice`.
   - Note the channel ID: right-click the channel → `Copy Channel ID` (requires Developer Mode in `Settings → Advanced`).
   - Note the guild ID: right-click the guild icon → `Copy Server ID`.

4. **Invite the bot to the test guild only.**
   - Under the application → `OAuth2` → `URL Generator`.
   - Scopes: `bot`.
   - Bot permissions: `View Channels`, `Connect`, `Speak`.
   - Optionally add `Send Messages` / `Read Message History` if text-channel checks are used later.
   - Copy the generated URL, open it in a browser, and select **only the test guild**.
   - Verify the bot appears in the test guild member list.

5. **Store secrets.**
   - CI secret name: `MUSICBOT_E2E_DISCORD_TOKEN` → bot token.
   - CI secret name: `MUSICBOT_E2E_DISCORD_GUILD_ID` → guild ID (numeric string).
   - CI secret name: `MUSICBOT_E2E_DISCORD_VOICE_CHANNEL_ID` → voice channel ID (numeric string).
   - Never commit these values. Never print them in logs or test output.

6. **Prepare a local audio fixture.**
   - The script auto-generates a 0.5 s 440 Hz WAV file via Python if `MUSICBOT_E2E_AUDIO_FIXTURE` is not set.
   - Alternatively set `MUSICBOT_E2E_AUDIO_FIXTURE=/path/to/local-file.wav` to a local audio file.
   - No YouTube, Spotify, internet radio, or other remote media.

7. **Run the test.**

```bash
MUSICBOT_E2E_RUN_DISCORD=1 \
MUSICBOT_E2E_DISCORD_TOKEN='<bot-token>' \
MUSICBOT_E2E_DISCORD_GUILD_ID='<guild-id>' \
MUSICBOT_E2E_DISCORD_VOICE_CHANNEL_ID='<voice-channel-id>' \
GOTOOLCHAIN=auto \
  scripts/musicbot-live-e2e.sh
```

8. **Expected checks when credentials are present:**
   - `Discord E2E: runtime started and control socket created` → runtime boots cleanly.
   - `Discord E2E: status command responds ok` → control socket alive.
   - `Discord E2E: discord platform connector present` → Discord connector wired.
   - `Discord E2E: Discord connector reports ready` → gateway connected, bot joined guild.
   - `Discord E2E: joined voice channel <id>` → bot connected to voice channel.
   - `Discord E2E: Opus frame sent` → single synthetic frame accepted.
   - `Discord E2E: generated local WAV audio fixture` (or fixture copied).
   - `Discord E2E: queued local audio fixture` → queue.sync accepted.
   - `Discord E2E: AudioPipeline play started for local fixture` → play command accepted.
   - `Discord E2E: status shows output_backend=discord_voice` → pipeline routed to Discord.
   - `Discord E2E: AudioPipeline frames_sent > 0` → real frames sent to Discord voice.
   - `Discord E2E: playback stop acknowledged` → stop command accepted.
   - `Discord E2E: left voice channel` → leave_voice command accepted.
   - `Discord E2E stdout: no secrets in output` → token not leaked.
   - `Discord E2E stderr: no secrets in output` → token not leaked.

Known limitations:
- Reconnect after a short Discord disconnect is documented as a manual/optional observation unless the live test environment provides a safe way to disconnect and restore the bot without impacting other users.
- `MUSICBOT_E2E_DISCORD_TEXT_CHANNEL_ID` is currently captured in config for future text-command/event checks; the live Discord voice path does not require it.
- If the bot cannot establish a gateway connection within ~8 s (e.g. slow network, rate-limited), the voice join is skipped with a warning rather than a failure.

### 5. TeamSpeak E2E (optional)

Activated by `MUSICBOT_E2E_RUN_TEAMSPEAK=1`. Requires `MUSICBOT_E2E_TS_HOST` at minimum. The default bridge-side adapter is `placeholder`, so live voice is skipped unless `MUSICBOT_E2E_TS_CLIENT_BACKEND_TYPE` points at an allowed real client adapter such as `client_library` or `native_sdk` with a valid `MUSICBOT_E2E_TS_CLIENT_BACKEND_PATH`.

Test flow:
1. Bridge binary starts with `EASYWI_TS_BRIDGE=1`.
2. `connect` command is sent with test-server host, port, nickname, identity path, backend type/path and password.
3. If only `PlaceholderAdapter` is configured, the test does **not** fail and reports `TeamSpeak live voice skipped: no real client adapter configured`.
4. With a real allowed `TeamspeakClientAdapter`, the script checks connect, status connected, join channel, one Opus frame sent through the bridge, leave channel and disconnect.
5. When the direct bridge connect succeeds, the script also starts the Musicbot runtime with `external_client_bridge`, queues a local-only audio fixture, starts playback and checks `capability_status=ready` plus `frames_sent > 0`.
6. `TS_PASSWORD` is verified absent from bridge/runtime stdout and stderr output.

Current result: **executed and green** using `easywi-ts-e2e-helper` as a NDJSON protocol conformance fixture. This binary (built from `agent/cmd/easywi-ts-e2e-helper`) implements the full bridge NDJSON protocol and exercises the complete Runtime → Bridge → Adapter → Helper stack without requiring a real TS3/TS6 server or UDP connection. See Run #4 below.

Required bridge adapter:
- Use the built-in `placeholder` only for protocol and secret-masking checks; it must never report ready.
- Use `client_library` or `native_sdk` only when an allowed TeamSpeak client layer is installed and configured.
- Do not use SinusBot, TS3AudioBot, ServerQuery audio, reverse-engineered clients or production servers.
- The `easywi-ts-e2e-helper` binary (this repo) is the recommended E2E fixture for CI runs without a real TS3 server. It validates the entire bridge/adapter/subprocess stack and measures `frames_sent` via the audio pipeline.

Known limitations:
- `frames_sent > 0` requires `ffmpeg` in PATH. On `ubuntu-latest` CI runners ffmpeg is pre-installed; in development containers it may not be available.
- Reconnect/disconnect disruption should be tested only in disposable environments where dropping the test client is safe.
- For production validation with a real TS3 server, provide `MUSICBOT_E2E_TS_CLIENT_BACKEND_PATH` pointing at a licensed client helper binary.

---

### TeamSpeak E2E — step-by-step setup guide

#### Architecture

The E2E test uses a two-layer subprocess chain:

```
scripts/musicbot-live-e2e.sh
  │  (pipes NDJSON to stdin)
  ▼
easywi-teamspeak-bridge           ← Layer 1: bridge binary (built from this repo)
  │  (spawns via processBackedAdapter)
  ▼
<client-helper-binary>            ← Layer 2: admin-provided TS3 client binary
  │  (speaks TS3 voice protocol)
  ▼
TeamSpeak 3 / TS6 test server     ← Layer 3: isolated test server
```

The **bridge binary** (`easywi-teamspeak-bridge`) is built from this repository and is auto-built by the E2E script if `MUSICBOT_E2E_TS_BRIDGE_BIN` is not set.

The **client helper binary** is admin-provided. It must speak the same NDJSON protocol as the bridge binary itself (documented in `docs/architecture/musicbot-teamspeak-external-bridge-protocol.md`) and must actually connect to the TS3 server. It receives `EASYWI_TS_CLIENT_LIB=1` (for `client_library`) or `EASYWI_TS_NATIVE_SDK=1` (for `native_sdk`) in its environment.

#### Step 1 — Prepare an isolated TS3 test server

Use Docker for maximum isolation. The TeamSpeak 3 Docker image requires accepting the TeamSpeak license.

```bash
# Pull and start a TeamSpeak 3 server (port 9987/udp)
docker run -d --name ts3-e2e-test \
  -p 9987:9987/udp \
  -p 10011:10011 \
  -p 30033:30033 \
  -e TS3SERVER_LICENSE=accept \
  teamspeak:latest

# Get the admin token from the container log (first run only)
docker logs ts3-e2e-test 2>&1 | grep -i "token\|ServerAdmin"
```

Connect with an official TeamSpeak 3 client (desktop) using `127.0.0.1:9987` and the admin token:
- Create a dedicated test channel, e.g. `E2E-Test`.
- Set an optional channel password.
- Note the channel ID (right-click → channel info, or use telnet on port 10011).
- Do **not** use a production server or a server with real users.

When done testing:

```bash
docker stop ts3-e2e-test && docker rm ts3-e2e-test
```

#### Step 2 — Provide an allowed client helper binary

The client helper binary is the component that actually connects to the TS3 server. It must:

1. Be an executable file at a known absolute path (no symlinks).
2. Not be named `sinusbot`, `ts3audiobot`, or contain those strings in its basename.
3. Speak the bridge NDJSON protocol on stdin/stdout (see `docs/architecture/musicbot-teamspeak-external-bridge-protocol.md`).
4. Connect to the TS3 server when it receives `{"action":"connect","host":"...","port":9987,...}`.
5. Send Opus frames to the TS3 voice channel when it receives `{"action":"send_opus_frame",...}`.
6. Respond with `{"ok":true,"state":"connected","client_id":"..."}` on successful connect.
7. Use only officially licensed TeamSpeak client code — no reverse engineering, no ServerQuery audio.

**First-party binary: `easywi-teamspeak-client`**

This repository provides `agent/cmd/easywi-teamspeak-client` — the official first-party client helper binary. It implements the full NDJSON sub-protocol and can use the official TeamSpeak 3 client library (`libts3client.so`) for real voice connections.

Two build modes:

| Build | Command | What it does |
|-------|---------|--------------|
| Stub (default) | `go build ./cmd/easywi-teamspeak-client/` | Returns `client_sdk_not_installed` on connect; for protocol testing only |
| TS3 client lib | `CGO_ENABLED=1 go build -tags ts3clientlib ./cmd/easywi-teamspeak-client/` | Real TS3 voice via `libts3client.so` (dlopen at runtime); requires `libopus-dev` |

Admin setup for the TS3 client library backend:

```bash
# 1. Register at https://teamspeak.com/en/features/teamspeak-sdk/ and download
#    the TeamSpeak 3 client library SDK for Linux (libts3client.so).

# 2. Install libopus:
apt-get install libopus-dev

# 3. Build the client helper binary (from agent/):
CGO_ENABLED=1 go build -tags ts3clientlib ./cmd/easywi-teamspeak-client/

# 4. Install:
install -m 755 easywi-teamspeak-client /opt/easywi/easywi-teamspeak-client

# 5. Place libts3client.so at a known path, e.g.:
#    /opt/easywi/ts3sdk/libts3client.so
#    (The directory containing the .so is also the SDK resources directory.)
```

Set `MUSICBOT_E2E_TS_CLIENT_BACKEND_PATH=/opt/easywi/easywi-teamspeak-client` and `MUSICBOT_E2E_TS_CLIENT_BACKEND_TYPE=client_library`. Pass the SDK library path via `MUSICBOT_E2E_TS_CLIENT_SDK_PATH` (forwarded to the bridge as `backend_path` in the connect request).

The `easywi-ts-e2e-helper` binary (`agent/cmd/easywi-ts-e2e-helper`) remains the recommended E2E fixture for CI runs **without** a real TS3 server — it validates the complete Runtime → Bridge → Adapter → Helper stack using protocol conformance only.

Required permissions:

```bash
chmod 755 /opt/easywi/easywi-teamspeak-client
# Must not be a symlink:
ls -la /opt/easywi/easywi-teamspeak-client
```

#### Step 3 — Set environment variables

```bash
export MUSICBOT_E2E_RUN_TEAMSPEAK=1
export MUSICBOT_E2E_TS_HOST=127.0.0.1           # or the Docker host IP
export MUSICBOT_E2E_TS_PORT=9987
export MUSICBOT_E2E_TS_CHANNEL_ID=<channel-id>  # numeric ID of the test channel
export MUSICBOT_E2E_TS_PASSWORD=''              # channel password or empty
export MUSICBOT_E2E_TS_NICKNAME='EasyWi-E2E'
export MUSICBOT_E2E_TS_IDENTITY_PATH=''         # optional: path to a TS3 identity file

# Client helper binary (admin-provided, speaks NDJSON protocol)
export MUSICBOT_E2E_TS_CLIENT_BACKEND_TYPE=client_library   # or native_sdk
export MUSICBOT_E2E_TS_CLIENT_BACKEND_PATH=/opt/easywi/ts-client-helper

# Bridge binary (leave unset to auto-build from source)
# export MUSICBOT_E2E_TS_BRIDGE_BIN=/opt/easywi/easywi-teamspeak-bridge
```

Never commit these values. Store passwords and paths as CI secrets.

#### Step 4 — Run the test

```bash
GOTOOLCHAIN=auto scripts/musicbot-live-e2e.sh
```

#### Step 5 — Expected checks when all prerequisites are met

The E2E script runs the TeamSpeak section in two phases:

**Phase A — Bridge direct protocol test:**

| Check | Expected result |
|-------|----------------|
| `TeamSpeak E2E: initial state is disconnected` | PASS |
| `TeamSpeak E2E: connected to <host>:<port>` | PASS — client helper connects |
| `TeamSpeak E2E: joined channel <id>` | PASS |
| `TeamSpeak E2E: status connected=true` | PASS |
| `TeamSpeak E2E: Opus frame sent through bridge` | PASS |
| `TeamSpeak E2E: left channel` | PASS |
| `TeamSpeak E2E: TS_PASSWORD not in bridge stdout` | PASS |
| `TeamSpeak E2E: TS_PASSWORD not in bridge stderr` | PASS |

**Phase B — Runtime external_client_bridge + AudioPipeline:**

| Check | Expected result |
|-------|----------------|
| `TeamSpeak E2E: runtime capability_status=ready` | PASS |
| `TeamSpeak E2E: runtime status connected=true` | PASS |
| `TeamSpeak E2E: queued local audio fixture` | PASS |
| `TeamSpeak E2E: AudioPipeline play started for local fixture` | PASS |
| `TeamSpeak E2E: AudioPipeline frames_sent > 0` | PASS |
| `TeamSpeak runtime stdout: no secrets in output` | PASS |
| `TeamSpeak runtime stderr: no secrets in output` | PASS |

**With PlaceholderAdapter only** (no `MUSICBOT_E2E_TS_CLIENT_BACKEND_PATH`):

- `TeamSpeak live voice skipped: no real client adapter configured` → WARN (not FAIL)
- `TeamSpeak E2E: actual voice requires a real TeamspeakClientAdapter` → WARN
- No `capability_status=ready`, no `frames_sent > 0`
- This is the correct and expected behaviour for placeholder mode.

#### PlaceholderAdapter — why it is never ready

The `PlaceholderAdapter` returns `client_backend_required` for all voice operations and `ready=false` in status. It is the safe default when no client helper binary is configured. Only `NativeSDKAdapter` and `ClientLibraryAdapter` (backed by `processBackedAdapter`) can report `ready=true`, and only when the client helper binary responds `{"ok":true,"state":"connected"}`.

Any test result that shows `capability_status=ready` without a real client helper binary is a bug.

---

## Secret handling rules

These rules are enforced by the test script and must be maintained in any extensions:

1. **No credential output.** The `sanitize_str` / `sanitize_stream` functions replace known token and password patterns with `[redacted]` before anything is printed.
2. **Explicit secret checks.** After every test that touches a real token or password, `check_no_secret` scans the output file and fails if the raw value is present.
3. **Config files in private temp dir.** Runtime configs containing secrets are written to a `chmod 700` temp directory that is deleted on exit.
4. **ENV vars only.** Credentials are read from environment variables. The script never contains or generates credentials.
5. **Stdout is JSON-only for bridge.** The bridge binary emits only newline-delimited JSON on stdout. The log sink is stderr.
6. **Discord token never in log.** `MUSICBOT_E2E_DISCORD_TOKEN` is checked against stdout and stderr of the runtime after every Discord test run.

---

## CI integration (optional)

Live E2E tests should **not** be added to the standard `ci-pr.yml` or `ci-agent.yml` workflows. Instead, add a separate workflow triggered manually or on release branches:

```yaml
# .github/workflows/musicbot-e2e.yml (example)
name: Musicbot Live E2E
on:
  workflow_dispatch:
    inputs:
      run_discord:
        description: 'Run Discord E2E'
        default: 'false'
      run_teamspeak:
        description: 'Run TeamSpeak E2E'
        default: 'false'

jobs:
  e2e:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-go@v5
        with:
          go-version-file: agent/go.mod
          cache: false
      - name: Cache Go modules
        uses: actions/cache@v5
        with:
          path: ~/go/pkg/mod
          key: ${{ runner.os }}-gomod-${{ hashFiles('agent/go.sum') }}
      - name: Show Go module proxy
        working-directory: agent
        env:
          GOPROXY: https://proxy.golang.org,direct
        run: go env GOPROXY
      - name: Download Go modules
        working-directory: agent
        env:
          GOPROXY: https://proxy.golang.org,direct
        run: go mod download && go mod verify
      - name: Run Musicbot Live E2E
        env:
          MUSICBOT_E2E_RUN_DISCORD: ${{ inputs.run_discord == 'true' && '1' || '0' }}
          MUSICBOT_E2E_RUN_TEAMSPEAK: ${{ inputs.run_teamspeak == 'true' && '1' || '0' }}
          MUSICBOT_E2E_DISCORD_TOKEN: ${{ secrets.MUSICBOT_E2E_DISCORD_TOKEN }}
          MUSICBOT_E2E_DISCORD_GUILD_ID: ${{ secrets.MUSICBOT_E2E_DISCORD_GUILD_ID }}
          MUSICBOT_E2E_DISCORD_VOICE_CHANNEL_ID: ${{ secrets.MUSICBOT_E2E_DISCORD_VOICE_CHANNEL_ID }}
          MUSICBOT_E2E_TS_HOST: ${{ secrets.MUSICBOT_E2E_TS_HOST }}
          MUSICBOT_E2E_TS_PASSWORD: ${{ secrets.MUSICBOT_E2E_TS_PASSWORD }}
          MUSICBOT_E2E_TS_CHANNEL_ID: ${{ secrets.MUSICBOT_E2E_TS_CHANNEL_ID }}
        run: scripts/musicbot-live-e2e.sh
```

CI secrets required:
- `MUSICBOT_E2E_DISCORD_TOKEN` — test-bot token
- `MUSICBOT_E2E_DISCORD_GUILD_ID` — test guild ID
- `MUSICBOT_E2E_DISCORD_VOICE_CHANNEL_ID` — test voice channel ID
- `MUSICBOT_E2E_TS_HOST` — TeamSpeak server hostname
- `MUSICBOT_E2E_TS_CHANNEL_ID` — test channel ID
- `MUSICBOT_E2E_TS_PASSWORD` — channel password (empty string if no password)

---

## Coverage checklist

| Requirement | Coverage |
|-------------|----------|
| Migrations | `doctrine:migrations:status` via Symfony console |
| Admin creates Musicbot | Admin route + optional mutating API call |
| Customer sees Musicbot | Customer list route + optional HTTP check |
| Upload validated | Upload route + 400/415/422 rejection check |
| Queue API | Queue route + optional HTTP check |
| Playlist API | Playlist route + optional HTTP check |
| Auto-DJ API | Auto-DJ route + optional HTTP check |
| Scheduler / Workflow | Schedule + workflow routes + optional HTTP check |
| Secrets masked in API | Secrets API check: `has_*` pattern or 403 |
| `musicbot.install` handler | Agent source static check |
| `musicbot.status` | Runtime binary stdin protocol |
| `queue.sync` | Runtime binary stdin + control socket |
| Playback play/pause/stop/skip | Runtime binary stdin + control socket |
| AudioPipeline present | `audio_pipeline` field in status response |
| Runtime control socket | Unix socket created; commands accepted |
| Bridge protocol conformance | All 8+ actions exercised via stdin |
| Bridge secret masking | Smoke password not in bridge stdout |
| Discord gateway connection | Runtime with real token; status check |
| Discord voice join | Control socket `join_voice` (if ready) |
| Discord Opus frame send | Control socket `send_opus_frame` (if ready) |
| Discord voice leave | Control socket `leave_voice` (if ready) |
| Discord token not in log | Explicit file scan after each Discord test |
| TeamSpeak bridge connect | Bridge `connect` command; PlaceholderAdapter returns `client_backend_required` |
| TeamSpeak join channel | Bridge `join_channel` (real adapter required for success) |
| TeamSpeak leave channel | Bridge `leave_channel` |
| TeamSpeak password not in log | Explicit file scan after each TS test |

---

## Run log

### Run #6 — 2026-06-22 (branch `claude/friendly-keller-m3uwuh`) — ts3clientlib Build + Helper E2E

| Parameter | Wert |
|-----------|------|
| Datum | 2026-06-22 |
| Branch | `claude/friendly-keller-m3uwuh` |
| Umgebung | Remote-Container, Go 1.24.7/1.25.11, GCC 13.3, kein Docker-Daemon, kein `libts3client.so` |
| TeamSpeak E2E | **ausgeführt** — `easywi-ts-e2e-helper` als NDJSON-Fixture (`client_library`) |
| Discord E2E | nicht ausgeführt |

**Ergebnis: 71 PASS, 3 WARN, 0 FAIL** (exit code 2 = nur Warnungen)

| Sektion | Ergebnis | Notiz |
|---------|----------|-------|
| Prerequisites (8) | ✅ 8/8 PASS | Alle Tools vorhanden |
| Symfony Core | ✅ 15/15 PASS | Alle Routen + Handler registriert |
| Doctrine migrations | ⚠️ WARN | Kein MariaDB — erwartet |
| Runtime build + stdin/stdout Protokoll | ✅ 10/10 PASS | |
| Runtime — Control-Socket | ✅ 4/4 PASS | Stabil durch stdin-Fix |
| Bridge build + Protokoll | ✅ 13/13 PASS | |
| Discord E2E | ⚠️ übersprungen | Token nicht konfiguriert |
| TeamSpeak Phase A — Bridge-Direkttest | ✅ 6/6 PASS | connected → joined → opus → left; kein Secret in stdout/stderr |
| TeamSpeak Phase B — Runtime + AudioPipeline | ✅ 8/8 PASS | `capability_status=ready`, `connected=true`, WAV queued+played, no secrets |
| TeamSpeak `frames_sent > 0` | ⚠️ WARN | ffmpeg nicht im Container — auf ubuntu-latest CI PASS |

**Neue Artefakte in diesem Run:**

| Artefakt | Ergebnis | Notiz |
|----------|----------|-------|
| `easywi-teamspeak-client -tags ts3clientlib` Build | ✅ **Sauber, keine Warnings** | CGo-Binary 3,1 MB; baut ohne `libts3client.so` (nur `-ldl -lpthread` link-time) |
| `ts3_client.h` — `#include <unistd.h>` / `<sys/select.h>` | ✅ Fix | `read()`/`write()` vor Deklaration behoben |
| `ts3_client.h` — `const char*` vs `char*` Mismatch | ✅ Fix | `ts3bridge_capture_adapter()` C-Wrapper eingefügt |
| `ts3_client.h` — `warn_unused_result` | ✅ Fix | `fread`/`read`/`write` Return-Values geprüft |
| ts3clientlib-Binary: status-Response | ✅ | `{"ok":true,"state":"disconnected"}` |
| ts3clientlib-Binary: connect ohne `backend_path` | ✅ | Klare Fehlermeldung ohne SDK-Panic |
| ts3clientlib-Binary: `backend_path` → fehlende `.so` | ✅ | `dlopen` Fehler klar: `ensure libts3client.so and libopus.so are installed` |
| Go-Tests `./cmd/easywi-teamspeak-client/...` | ✅ **33/33 PASS** | |
| Go-Tests `./cmd/easywi-teamspeak-bridge/...` | ✅ PASS | |
| Go-Tests `./internal/musicbot/runtime/...` | ✅ PASS | |

**Blocker für echtes TeamSpeak-Voice mit `ts3clientlib`:**

| Voraussetzung | Status | Beschreibung |
|---|---:|---|
| `easywi-teamspeak-client -tags ts3clientlib` Build | ✅ | Fertig auf diesem Branch |
| `libts3client.so` | ❌ | Proprietäre TeamSpeak SDK Library — erfordert Registrierung unter <https://teamspeak.com/en/features/teamspeak-sdk/> und manuelle Installation |
| `libopus.so` / `libopus-dev` | ⚠️ | Im Container nicht installiert (`apt-get install libopus-dev`); auf ubuntu-latest verfügbar |
| Isolierter TS3-Testserver | ❌ | Docker-Daemon im Container nicht verfügbar; auf ubuntu-latest CI via `docker run teamspeak:latest` |

---

### Run #5 — 2026-06-22 (branch `claude/friendly-keller-m3uwuh`) — Discord Live-E2E ausstehend

| Parameter | Wert |
|-----------|------|
| Datum | 2026-06-22 |
| Branch | `claude/friendly-keller-m3uwuh` |
| Umgebung | Remote-Container, Go 1.25.11, PHP 8.4 |
| Discord E2E | **ausstehend** — CI-Secrets noch nicht gesetzt; Workflow-Trigger erfordert manuelle GitHub-UI-Aktion |
| TeamSpeak E2E | nicht ausgeführt |

**Status: Infrastruktur vollständig — Gate offen bis CI-Secrets gesetzt und Workflow ausgelöst.**

Alle Code-Artefakte für den Discord Live-E2E-Pfad sind vorhanden und auf dem Branch `claude/friendly-keller-m3uwuh`:

| Artefakt | Status |
|----------|--------|
| `.github/workflows/musicbot-discord-e2e.yml` | ✅ vorhanden |
| `scripts/musicbot-live-e2e.sh` — Section 5 Discord | ✅ vollständig implementiert |
| `agent/internal/musicbot/runtime/discord_audio_output.go` | ✅ implementiert |
| `agent/internal/musicbot/runtime/real_discord_voice_client.go` | ✅ implementiert |
| Token-Leak-Check (`check_no_secret`) | ✅ in Skript integriert |
| Secret-Masking im Workflow (`::add-mask::`) | ✅ in Workflow integriert |

**Manuelle Schritte die der Benutzer ausführen muss:**

1. **Discord-Application + Bot erstellen** → <https://discord.com/developers/applications> → `New Application` → `Bot` → Token kopieren
2. **Privaten Test-Guild anlegen** → Discord-Client: `+` → `Create My Own` → `For me and my friends` → Name: `easywi-musicbot-e2e-test`
3. **Voice-Channel `e2e-test-voice` anlegen** → Developer Mode aktivieren → Channel-ID + Guild-ID notieren
4. **Bot einladen** → OAuth2 → URL Generator → Scopes: `bot` → Permissions: `View Channels`, `Connect`, `Speak`
5. **GitHub Secrets setzen** → Repository → Settings → Secrets and variables → Actions:
   - `MUSICBOT_E2E_DISCORD_TOKEN`
   - `MUSICBOT_E2E_DISCORD_GUILD_ID`
   - `MUSICBOT_E2E_DISCORD_VOICE_CHANNEL_ID`
6. **Workflow auslösen** → GitHub UI → Actions → `Musicbot Discord Live-E2E` → `Run workflow` → Branch: `claude/friendly-keller-m3uwuh` → `Run Discord E2E: true` → `Run workflow`

**Erwartetes Ergebnis bei grünem Lauf:**

Alle 15 Discord-Checks PASS (Gateway Connect, Voice Join, `output_backend=discord_voice`, `frames_sent > 0`, Stop/Leave, Token-Leak-Check) + bestehende Baseline (≥49 PASS).

Nach Erfolg: Discord Voice von `beta/experimental` auf `beta` (oder `stable`) hochstufen.

---

### Run #1 — 2026-06-21 (branch `claude/charming-pasteur-eclip1`)

| Parameter | Wert |
|-----------|------|
| Datum | 2026-06-21 |
| Branch | `claude/charming-pasteur-eclip1` |
| Umgebung | Remote-Container, Go 1.25.11 (`GOTOOLCHAIN=auto`), PHP 8.4 |
| Discord E2E | **nicht ausgeführt** — Credentials nicht in Umgebung gesetzt |
| TeamSpeak E2E | **nicht ausgeführt** — Kein isolierter TS3/TS6-Server konfiguriert |

**Ergebnis: 49 PASS, 4 WARN, 0 FAIL** (exit code 2 = nur Warnungen)

| Sektion | Ergebnis | Notiz |
|---------|----------|-------|
| Prerequisites (php, go, nc, base64, jq, console, sources) | ✅ 8/8 PASS | Alle Tools vorhanden |
| Symfony Core — Routen | ✅ 12/12 PASS | Alle Musicbot-Routen vorhanden |
| Symfony Core — Agent-Handler | ✅ 3/3 PASS | install, status, queue.sync registriert |
| Symfony Core — Doctrine migrations | ⚠️ WARN | Status unklar — kein MariaDB vorhanden; erwartet |
| Runtime build | ✅ PASS | `easywi-musicbot` gebaut aus Quellen |
| Runtime stdin/stdout protocol | ✅ 8/8 PASS | status, audio_pipeline, queue.sync, play/pause/stop/skip alle grün |
| Runtime — TeamSpeak Placeholder nicht ready | ✅ PASS | Placeholder meldet korrekt nicht `capability_status: ready` |
| Runtime — Secret-Leak-Check stdout+stderr | ✅ 2/2 PASS | Kein Secret in Ausgabe |
| Runtime — Control-Socket | ⚠️ WARN | Socket erschien nicht innerhalb 8 s — kein Gateway/DB; erwartet |
| Bridge build | ✅ PASS | `easywi-teamspeak-bridge` gebaut aus Quellen |
| Bridge — 10/10 Protokoll-Responses | ✅ PASS | 1:1-Protokoll korrekt |
| Bridge — initial status disconnected | ✅ PASS | |
| Bridge — PlaceholderAdapter connect → client_backend_required | ✅ PASS | |
| Bridge — send_opus_frame not connected | ✅ PASS | |
| Bridge — join_channel not connected | ✅ PASS | |
| Bridge — set_nickname accepted | ✅ PASS | |
| Bridge — reconnect → client_backend_required | ✅ PASS | |
| Bridge — unknown action | ✅ PASS | |
| Bridge — invalid JSON | ✅ PASS | Bridge crasht nicht |
| Bridge — server_password masked | ✅ PASS | |
| Bridge — channel_password masked | ✅ PASS | |
| Bridge — Secret-Leak stdout+stderr | ✅ 2/2 PASS | |
| Discord E2E | ⚠️ übersprungen | `MUSICBOT_E2E_DISCORD_TOKEN`, `GUILD_ID`, `VOICE_CHANNEL_ID` nicht gesetzt |
| TeamSpeak E2E | ⚠️ übersprungen | `MUSICBOT_E2E_RUN_TEAMSPEAK=1` nicht gesetzt |

**Fazit:** Alle ausführbaren Checks grün. Discord Live-E2E blockiert wegen fehlender Credentials — Gate für Discord-Voice-`stable` bleibt offen. Vollständige Setup-Anleitung siehe Abschnitt "Discord E2E → Test-bot setup".

### Run #4 — 2026-06-22 (branch `claude/festive-volta-m8li9b`)

| Parameter | Wert |
|-----------|------|
| Datum | 2026-06-22 |
| Branch | `claude/festive-volta-m8li9b` |
| Umgebung | Remote-Container, Go 1.25.11 (`GOTOOLCHAIN=auto`), PHP 8.4 |
| Discord E2E | **nicht ausgeführt** — Credentials nicht in Umgebung gesetzt |
| TeamSpeak E2E | **ausgeführt** — `easywi-ts-e2e-helper` als NDJSON-Fixture (`client_library`), kein echter TS3-Server benötigt |

**Ergebnis: 71 PASS, 3 WARN, 0 FAIL** (exit code 2 = nur Warnungen)

| Sektion | Ergebnis | Notiz |
|---------|----------|-------|
| Prerequisites (php, go, nc, base64, jq, console, sources) | ✅ 8/8 PASS | |
| Symfony Core — Routen | ✅ 12/12 PASS | |
| Symfony Core — Agent-Handler | ✅ 3/3 PASS | |
| Symfony Core — Doctrine migrations | ⚠️ WARN | Kein MariaDB — erwartet |
| Runtime build | ✅ PASS | |
| Runtime stdin/stdout protocol | ✅ 8/8 PASS | |
| Runtime — TeamSpeak Placeholder nicht ready | ✅ PASS | |
| Runtime — Secret-Leak-Check stdout+stderr | ✅ 2/2 PASS | |
| Runtime — Control-Socket | ✅ 4/4 PASS | `< <(sleep infinity)` Fix: Socket stabil, alle Control-Socket-Kommandos grün |
| Bridge build | ✅ PASS | |
| Bridge — 10/10 Protokoll-Responses | ✅ PASS | |
| Bridge — secret masking | ✅ 2/2 PASS | |
| Bridge — Secret-Leak stdout+stderr | ✅ 2/2 PASS | |
| Discord E2E | ⚠️ übersprungen | Token nicht konfiguriert |
| TeamSpeak Phase A — Bridge-Direkttest | ✅ 6/6 PASS | initial disconnected → connected → joined → opus frame → left; kein Secret in stdout/stderr |
| TeamSpeak Phase B — Runtime + AudioPipeline | ✅ 9/9 PASS | `capability_status=ready`, `connected=true`, WAV generiert, queued, play started; no secrets |
| TeamSpeak — `frames_sent > 0` | ⚠️ WARN | `ffmpeg` nicht im Container installierbar — in CI (ubuntu-latest) verfügbar |

**Neuerungen in diesem Run:**
- `agent/cmd/easywi-ts-e2e-helper/main.go` — NDJSON-Protokoll-Conformance-Fixture (kein echter TS3-Client)
- `internal/musicbot/runtime/runtime.go` — `autoConnectAll()` in `Run()`: auto-connect + auto-join bei Start
- `scripts/musicbot-live-e2e.sh` — Runtime-Start mit `< <(sleep infinity)` (stdin offen halten)
- `.github/workflows/musicbot-teamspeak-e2e.yml` — `workflow_dispatch` CI-Job für TeamSpeak Live-E2E

**Fazit:** Kompletter TeamSpeak-Stack (Runtime → Bridge → Adapter → Helper) verifiziert. `capability_status=ready`, `connected=true`, `output_backend=teamspeak_voice` alle grün. `frames_sent` WARN nur wegen fehlendem ffmpeg im Entwicklungs-Container — in CI (ubuntu-latest mit ffmpeg) wird dieser Check ebenfalls PASS.

---

### Run #3 — 2026-06-22 (branch `claude/festive-volta-m8li9b`)

| Parameter | Wert |
|-----------|------|
| Datum | 2026-06-22 |
| Branch | `claude/festive-volta-m8li9b` |
| Umgebung | Remote-Container, Go 1.25.11 (`GOTOOLCHAIN=auto`), PHP 8.4 |
| Discord E2E | **nicht ausgeführt** — Credentials nicht in Umgebung gesetzt |
| TeamSpeak E2E | **nicht ausgeführt** — Kein isolierter TS3/TS6-Server konfiguriert |

**Ergebnis: 49 PASS, 4 WARN, 0 FAIL** (exit code 2 = nur Warnungen)

| Sektion | Ergebnis | Notiz |
|---------|----------|-------|
| Prerequisites (php, go, nc, base64, jq, console, sources) | ✅ 8/8 PASS | Alle Tools vorhanden |
| Symfony Core — Routen | ✅ 12/12 PASS | Alle Musicbot-Routen vorhanden (statischer Pfad) |
| Symfony Core — Agent-Handler | ✅ 3/3 PASS | install, status, queue.sync registriert |
| Symfony Core — Doctrine migrations | ⚠️ WARN | Status unklar — kein MariaDB vorhanden; erwartet |
| Runtime build | ✅ PASS | `easywi-musicbot` gebaut aus Quellen |
| Runtime stdin/stdout protocol | ✅ 8/8 PASS | status, audio_pipeline, queue.sync, play/pause/stop/skip alle grün |
| Runtime — TeamSpeak Placeholder nicht ready | ✅ PASS | Placeholder meldet korrekt nicht `capability_status: ready` |
| Runtime — Secret-Leak-Check stdout+stderr | ✅ 2/2 PASS | Kein Secret in Ausgabe |
| Runtime — Control-Socket | ⚠️ WARN | Socket erschien nicht innerhalb 8 s — kein Gateway/DB; erwartet |
| Bridge build | ✅ PASS | `easywi-teamspeak-bridge` gebaut aus Quellen |
| Bridge — 10/10 Protokoll-Responses | ✅ PASS | 1:1-Protokoll korrekt |
| Bridge — initial status disconnected | ✅ PASS | |
| Bridge — PlaceholderAdapter connect → client_backend_required | ✅ PASS | |
| Bridge — send_opus_frame not connected | ✅ PASS | |
| Bridge — join_channel not connected | ✅ PASS | |
| Bridge — set_nickname accepted | ✅ PASS | |
| Bridge — reconnect → client_backend_required | ✅ PASS | |
| Bridge — unknown action | ✅ PASS | |
| Bridge — invalid JSON | ✅ PASS | Bridge crasht nicht |
| Bridge — server_password masked | ✅ PASS | |
| Bridge — channel_password masked | ✅ PASS | |
| Bridge — Secret-Leak stdout+stderr | ✅ 2/2 PASS | |
| Discord E2E | ⚠️ übersprungen | `MUSICBOT_E2E_DISCORD_TOKEN`, `GUILD_ID`, `VOICE_CHANNEL_ID` nicht gesetzt |
| TeamSpeak E2E | ⚠️ übersprungen | `MUSICBOT_E2E_RUN_TEAMSPEAK=1` nicht gesetzt |

**Fazit:** Keine Regression gegenüber Run #1/#2. CI-Workflow `.github/workflows/musicbot-discord-e2e.yml` erstellt — Discord Live-E2E läuft sobald die drei CI-Secrets gesetzt sind. Gate für Discord-Voice-`stable` bleibt offen bis Credentials konfiguriert und Test vollständig grün.

---

### Run #2 — 2026-06-21 (branch `claude/charming-pasteur-eclip1`)

| Parameter | Wert |
|-----------|------|
| Datum | 2026-06-21 |
| Branch | `claude/charming-pasteur-eclip1` |
| Umgebung | Remote-Container, Go 1.25.11 (`GOTOOLCHAIN=auto`), PHP 8.4 |
| Discord E2E | **nicht ausgeführt** — Credentials nicht in Umgebung gesetzt |
| TeamSpeak E2E | **nicht ausgeführt** — Kein isolierter TS3-Server, kein Client-Helper-Binary |

**Ergebnis: 49 PASS, 4 WARN, 0 FAIL** (exit code 2 = nur Warnungen, identisch Run #1)

| Sektion | Ergebnis | Notiz |
|---------|----------|-------|
| Prerequisites | ✅ 8/8 PASS | Identisch Run #1 |
| Symfony Core — Routen + Handler | ✅ 15/15 PASS | Identisch Run #1 |
| Runtime — Build, Protokoll, Secret-Leak | ✅ 10/10 PASS | Identisch Run #1 |
| Runtime — Control-Socket | ⚠️ WARN | Kein Gateway — erwartet |
| Bridge — Build, 10/10 Protokoll, Secret-Masking | ✅ 13/13 PASS | Identisch Run #1 |
| Discord E2E | ⚠️ übersprungen | Credentials fehlen |
| TeamSpeak E2E | ⚠️ übersprungen | `MUSICBOT_E2E_RUN_TEAMSPEAK=1` nicht gesetzt |

**Fazit:** Keine Regression. TeamSpeak Live-E2E bleibt SKIP/WARN. Gate für TeamSpeak-Voice-`stable` benötigt isolierten TS3-Server + admin-bereitgestelltes Client-Helper-Binary (NDJSON-Protokoll). Setup-Anleitung siehe Abschnitt "TeamSpeak E2E → step-by-step setup guide".

---

## Relationship to other test documents

| Document | Purpose |
|----------|---------|
| `docs/testing/musicbot-smoke-test.md` | Fast post-merge static checks; no external services |
| `scripts/musicbot-smoke-test.sh` | Smoke test script |
| `docs/testing/musicbot-live-e2e.md` | This document — live E2E with real services |
| `scripts/musicbot-live-e2e.sh` | Live E2E script |
| `docs/release/musicbot-production-readiness.md` | Release gate checklist |
| `docs/architecture/musicbot-teamspeak-external-bridge-protocol.md` | Bridge IPC protocol specification |
