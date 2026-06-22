# Compatibility Matrix

Quelle: `docs/compatibility/matrix.yml`.

| Modul | OS/Target | Tier | Hinweise |
|---|---|---|---|
| gameserver | linux | GA | Produktiv unterstützt für Agent + Konsole + API Endpunkte. |
| gameserver | windows | Beta | Agent/Endpoints verfügbar; operative Härtung und breitere Feldtests laufen. |
| gameserver | windows-core-only | Tech Preview | Nur Kernpfade (Endpoint/Relay) validiert, keine vollständige Betriebsfreigabe. |


## Agent OS Feature Parity (TASK-018)

| Capability | Linux | Windows | Notes |
|---|---|---|---|
| Build (`go build ./cmd/agent`, `./cmd/easywi-sftp`) | supported | supported | Built natively in CI matrix on ubuntu-latest + windows-latest. |
| Unit/Integration tests (`go test ./...`) | supported | supported | Full Go test suite runs on both OS runners. |
| Smoke: agent starts | supported | supported | `TestAgentCoreConnectivitySmoke` starts runtime loop with service listener disabled. |
| Smoke: connects to core (`/agent/heartbeat`) | supported | supported | Smoke server validates heartbeat from both OS runners. |
| Smoke: runs one dummy job (`agent.diagnostics`) | supported | supported | Smoke validates start + result submission lifecycle. |
| Webspace lifecycle handlers | supported | partial | Windows support remains tech preview. |
| Host-specific service management (`systemd`/Windows service install) | partial | partial | OS-native service manager paths differ; validate with platform-specific rollout checks. |

### OS-specific known issues

- Windows: Webspace lifecycle remains tech preview; do not treat as GA for production rollout.
- Linux: no additional known issues for TASK-018 smoke scope.

### Mail backend lifecycle (TASK-016)

| Capability | none | local | panel | external |
|---|---:|---:|---:|---:|
| Mail create/update/delete endpoints | blocked (`MAIL_BACKEND_DISABLED`) | ✅ | ✅ | ✅ |
| DNS ownership + SPF/DMARC/DKIM orchestration | n/a | ✅ | ✅ | ✅ |
| Agent mail handlers execute mailbox/alias/domain jobs | blocked | ✅ | ✅ | ✅ |
| Auth-failure alert telemetry (`mail.security.auth_failures_alert`) | n/a | ✅ | ✅ | ✅ |

Design guardrail: exactly one active backend per instance is represented via single `mail_backend` enum value; `none` models "no mailserver" and future multi-provider support can extend this to provider sets while keeping `none` semantics.

### Webspace lifecycle (TASK-015)

| Capability | linux | windows |
|---|---:|---:|
| Webspace file operations contract (ACL/path/symlink/size/timeout/lock) | ✅ | ⚠️ (not supported by handler parity) |
| VHost managed apply with rollback on failed configtest/reload | ✅ | ⚠️ |
| SSL orchestration (`issue`/`renew`/`revoke`) | ✅ | ⚠️ |
