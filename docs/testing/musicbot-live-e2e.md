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
| `MUSICBOT_E2E_BRIDGE_BIN` | auto-built | Pre-built `easywi-teamspeak-bridge` binary |

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

### TeamSpeak

| Variable | Default | Purpose |
|----------|---------|---------|
| `MUSICBOT_E2E_RUN_TEAMSPEAK` | `0` | Set to `1` to enable TeamSpeak E2E tests |
| `MUSICBOT_E2E_TS_HOST` | unset | TS3/TS6 server hostname or IP |
| `MUSICBOT_E2E_TS_PORT` | `9987` | TS3/TS6 server UDP port |
| `MUSICBOT_E2E_TS_CHANNEL_ID` | `1` | Target channel ID |
| `MUSICBOT_E2E_TS_PASSWORD` | unset | Channel password (never logged) |

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
1. Runtime starts with real Discord config
2. Gateway connection established (up to ~8s)
3. Status command confirms `platform: discord` connector present
4. If `capability_status: ready` — attempts voice join, Opus frame send, voice leave
5. If `capability_status: voice_backend_required` or `placeholder` — state reported as expected

Secret invariant: `MUSICBOT_E2E_DISCORD_TOKEN` must not appear in any stdout or stderr line. The script checks this explicitly and fails if the token is found.

**Test-bot setup:**
- Create a dedicated Discord application and bot with `bot` scope and `CONNECT`, `SPEAK` permissions.
- Add the bot to a private test guild. Do not use a production guild.
- Store the token as a CI secret (`MUSICBOT_E2E_DISCORD_TOKEN`). Never commit it.

### 5. TeamSpeak E2E (optional)

Activated by `MUSICBOT_E2E_RUN_TEAMSPEAK=1`. Requires `MUSICBOT_E2E_TS_HOST` at minimum.

Test flow:
1. Bridge binary starts with `EASYWI_TS_BRIDGE=1`
2. `connect` command sent with test-server host and password
3. With current PlaceholderAdapter: `client_backend_required` is returned — test passes as expected, warning is emitted
4. With a real `TeamspeakClientAdapter`: connection succeeds, `join_channel` and `leave_channel` are exercised
5. `TS_PASSWORD` verified absent from all bridge stdout and stderr output

**Test-server setup:**
- Run a TS3/TS6 server instance in an isolated environment (Docker, dedicated VM).
- Create a dedicated channel with a known password.
- Store the password as a CI secret (`MUSICBOT_E2E_TS_PASSWORD`). Never commit it.

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
      - name: Cache Go modules
        uses: actions/cache@v5
        with:
          path: ~/go/pkg/mod
          key: ${{ runner.os }}-gomod-${{ hashFiles('agent/go.sum') }}
      - name: Download Go modules
        working-directory: agent
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

## Relationship to other test documents

| Document | Purpose |
|----------|---------|
| `docs/testing/musicbot-smoke-test.md` | Fast post-merge static checks; no external services |
| `scripts/musicbot-smoke-test.sh` | Smoke test script |
| `docs/testing/musicbot-live-e2e.md` | This document — live E2E with real services |
| `scripts/musicbot-live-e2e.sh` | Live E2E script |
| `docs/release/musicbot-production-readiness.md` | Release gate checklist |
| `docs/architecture/musicbot-teamspeak-external-bridge-protocol.md` | Bridge IPC protocol specification |
