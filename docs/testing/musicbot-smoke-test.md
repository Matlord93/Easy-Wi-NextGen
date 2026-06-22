# Musicbot End-to-End Smoke Test

This smoke test verifies that the Musicbot module is basically usable after a merge or fresh deployment without requiring real Discord or TeamSpeak services.

The smoke test has two layers:

1. **Local/static + console checks**: always safe and fast. These checks verify routes, controllers, migrations, plugin manifests, runtime placeholders, and agent job registrations.
2. **Optional HTTP/API checks**: enabled by environment variables when a local or staging panel is running. Mutating API checks are disabled by default and should only run against disposable test data.

The test accepts placeholder connector status. It explicitly checks that placeholders do **not** claim real audio readiness:

- TeamSpeak `capability_status` must not be `ready` when no native SDK or external bridge backend is configured.
- Discord may report `placeholder` or `voice_backend_required` when no real token/backend is active.
- `audio_pipeline` status must exist.
- `output_backend` may be `null`.

No real Discord or TeamSpeak connection is required.

## Script

Run from the repository root:

```bash
scripts/musicbot-smoke-test.sh
```

The script prints one `[PASS]`, `[WARN]`, or `[FAIL]` line per check and exits with:

- `0` when all checks pass.
- `1` when at least one required check fails.
- `2` when checks pass but optional dependencies or optional environments are missing.

Secrets are never printed intentionally. The script sanitizes common `token`, `password`, `secret`, and `Authorization` markers before showing command output.

## Environment variables

| Variable | Default | Purpose |
| --- | --- | --- |
| `REPO_ROOT` | parent of `scripts/` | Repository root. |
| `PANEL_ROOT` | `$REPO_ROOT/core` | Symfony panel root. |
| `AGENT_ROOT` | `$REPO_ROOT/agent` | Go agent root. |
| `CONSOLE` | `$PANEL_ROOT/bin/console` | Symfony console path. |
| `MUSICBOT_RUNTIME_BIN` | unset | Optional built `easywi-musicbot` runtime binary for runtime status checks. |
| `MUSICBOT_SMOKE_BASE_URL` | unset | Enables HTTP/API route checks, for example `http://127.0.0.1:8000`. |
| `MUSICBOT_SMOKE_AUTH_HEADER` | unset | Shared auth header for API checks, for example `Authorization: Bearer ...`. |
| `MUSICBOT_SMOKE_ADMIN_AUTH_HEADER` | shared header | Admin-specific auth header. |
| `MUSICBOT_SMOKE_CUSTOMER_AUTH_HEADER` | shared header | Customer-specific auth header. |
| `MUSICBOT_SMOKE_INSTANCE_ID` | `1` | Existing disposable Musicbot instance ID for optional API checks. |
| `MUSICBOT_SMOKE_CONNECTION_ID` | `1` | Existing disposable connection ID for secret API checks. |
| `MUSICBOT_SMOKE_PLUGIN_ID` | `1` | Existing disposable plugin ID for plugin checks. |
| `MUSICBOT_SMOKE_RUN_MUTATING_API` | `0` | Set to `1` only on disposable data to exercise create/upload/queue/schedule validation calls. |
| `MUSICBOT_SMOKE_STRICT` | `0` | Treat unavailable console/DB checks as failures instead of warnings. |

## Recommended fresh-setup run

```bash
# 1. Ensure PHP dependencies and env are installed for the panel.
cd /path/to/webinterface

# 2. Optional: build or point to the runtime binary for runtime status coverage.
# (Skip this if you only want static/console coverage.)
# cd agent && go build -o /tmp/easywi-musicbot ./cmd/easywi-musicbot && cd ..

MUSICBOT_RUNTIME_BIN=/tmp/easywi-musicbot \
  scripts/musicbot-smoke-test.sh
```

If the database is not configured yet, migration checks will warn by default. Use `MUSICBOT_SMOKE_STRICT=1` in CI after the database is available.

## Optional panel/API run

```bash
MUSICBOT_SMOKE_BASE_URL=http://127.0.0.1:8000 \
MUSICBOT_SMOKE_ADMIN_AUTH_HEADER='Authorization: Bearer <admin-token>' \
MUSICBOT_SMOKE_CUSTOMER_AUTH_HEADER='Authorization: Bearer <customer-token>' \
MUSICBOT_SMOKE_INSTANCE_ID=1 \
MUSICBOT_SMOKE_CONNECTION_ID=1 \
scripts/musicbot-smoke-test.sh
```

To include mutating validation checks on disposable data:

```bash
MUSICBOT_SMOKE_RUN_MUTATING_API=1 \
MUSICBOT_SMOKE_BASE_URL=http://127.0.0.1:8000 \
MUSICBOT_SMOKE_ADMIN_AUTH_HEADER='Authorization: Bearer <admin-token>' \
MUSICBOT_SMOKE_CUSTOMER_AUTH_HEADER='Authorization: Bearer <customer-token>' \
scripts/musicbot-smoke-test.sh
```

## Coverage checklist

The smoke test covers the requested post-merge checks as follows:

| Requirement | Smoke coverage |
| --- | --- |
| Doctrine migrations executable | `doctrine:migrations:status` via Symfony console. |
| Musicbot routes present | `debug:router` with static route fallback. |
| Admin can create Musicbot | Admin create route plus install-job dispatch check; optional mutating API validation. |
| Customer sees own Musicbots | Customer list route/API plus controller scoping check. |
| Limits API works | Customer limits route and optional HTTP check. |
| Upload API validates file types | Track upload route plus `MusicbotTrackService` MIME allow-list check; optional invalid upload validation. |
| Queue API works | Queue routes plus optional HTTP validation. |
| Queue-Sync Job is created | Core dispatch check for `musicbot.queue.sync`. |
| Runtime `queue.sync` works | Optional runtime binary check sends an empty local `queue.sync` payload and expects `synced=true`. |
| Playlist API works | Playlist routes plus optional HTTP validation. |
| Plugin manifest status works | Manifest JSON and plugin API route checks. |
| Scheduler API works | Schedule routes plus optional HTTP validation. |
| Workflow API works | Workflow route/controller check. |
| Runtime install job can be created | Admin/API create paths dispatch `musicbot.install`; agent handler is registered. |
| Agent can execute `musicbot.status` | Agent job switch and handler registration are checked. |
| Secret API gives no tokens back | Secret route/controller redaction checks plus optional HTTP route check without body logging. |
| Runtime status contains no secrets | Optional runtime binary check uses synthetic smoke secrets and verifies they are not emitted. |
| AudioPipeline status present | Optional runtime binary status check verifies `audio_pipeline`. |
| Discord placeholder/real backend status distinguishable | Runtime source/status model checks and optional runtime status check. |
| TeamSpeak placeholder/backend status distinguishable | Runtime source/status model checks and optional runtime status check. |

## Go dependency requirements

The musicbot runtime tests (`go test ./internal/musicbot/runtime`) require all Go module dependencies to be present locally before they run. They do **not** need real Discord or TeamSpeak connections, but the module download must succeed at least once.

### Why tests may fail with a 403 or network error

If `go test ./...` fails with an error like `reading https://proxy.golang.org/...`: `403 Forbidden` or a TLS/CONNECT block, the root cause is a network-level restriction on the CI runner or developer machine, not a code defect.

**Symptoms:**
- `GOPROXY=off` fails because the module is not in the local cache.
- `go test ./internal/musicbot/runtime` fails immediately before any test runs.
- The error is from `go mod download` or the implicit download during `go test`, not from the test logic.

### Fix: pre-download dependencies

```bash
# Ensure dependencies are cached locally
cd agent
GOPROXY=https://proxy.golang.org,direct go mod download
go mod verify

# Then run tests (no network needed once modules are cached)
go test ./...
go test ./internal/musicbot/runtime
```

### gorilla/websocket pinning

`github.com/gorilla/websocket v1.5.3` is pinned as a direct dependency in `go.mod` and its hash is recorded in `go.sum`. The real Discord voice client uses it for Gateway and Voice WebSocket connections. Tests use a mock `wsConn` interface and do **not** open real WebSocket connections.

If `go mod verify` reports a hash mismatch for `gorilla/websocket`, the module cache is corrupt. Run `go clean -modcache` and re-download.

### CI module caching

CI workflows set `GOPROXY=https://proxy.golang.org,direct`, run `go mod download` before tests, run `go mod verify`, and cache `~/go/pkg/mod` keyed by `agent/go.sum`. The repository uses `actions/setup-go` plus an explicit module cache so selected runners behave consistently. After the first successful run the module download step is usually a cache restore, not a network fetch. If your organization blocks both `proxy.golang.org` and direct GitHub module downloads, configure an internal Go module mirror and set `GOPROXY=https://<internal-mirror>,https://proxy.golang.org,direct` in the workflow or runner environment. If CI fails on module download despite a warm cache, inspect the cache key — a `go.sum` change invalidates the key and triggers a fresh download.

## Notes and limitations

- The script is intentionally fast and does not require external Discord/TeamSpeak services.
- Placeholder status is accepted only when it is explicit and not `ready`.
- Mutating API checks should only run in disposable test environments.
- The script avoids printing response bodies for HTTP checks to reduce the risk of leaking secrets.
- Go tests fail before reaching any test function if module dependencies are missing. A 403 on `proxy.golang.org` is a network/cache problem, not a test failure.
