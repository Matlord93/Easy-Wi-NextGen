# Go-Agent Project Skeleton (MUST HAVE #13)

## 1) Design / Spec

Ziel: klare, erweiterbare Paketstruktur für Mail-Control-Plane im Agent, mit deterministischem Rendering, sicherer Kommandoausführung und HTTP-Lifecycle-Checks.

### Struktur
- `cmd/agent` (main + server wiring skeleton)
- `internal/mail/configgen` (deterministic config rendering)
- `internal/mail/dkim` (DKIM metadata/path handling)
- `internal/mail/queue` (queue parsing)
- `internal/mail/logs` (structured log parsing)
- `internal/mail/validator` (bereits vorhanden)
- `internal/platform/http` (health/readiness server, config loader)
- `internal/platform/storage` (atomic file writer + backups)
- `internal/platform/exec` (safe exec wrapper, fixed args)

### Config-Entscheidung
- **Stdlib (json + env overrides)** statt Viper:
  - geringere Abhängigkeit
  - besser auditierbar
  - deterministischer startup path

## 2) Datenmodell/Migration

- Für dieses Skeleton keine DB-Migration notwendig.
- Persistenz bleibt in Panel/Control-Plane (`jobs`, `job_results`, `mail_*`).

## 3) API/DTO

- DTO-seitig wird im Agent Snapshot/Input-Output über typed structs abgebildet.
- Rendering liefert `RenderedFile{Path, Content}` für nachgelagerte storage/activation steps.

## 4) Agent-Contract

- Readiness checkt explizit Services: `postfix`, `dovecot`, `opendkim`.
- Keine Shell-Ausführung über `sh -c`.
- `safe exec` nutzt `exec.CommandContext(name, args...)` mit Timeout.

## 5) Tests / Edgecases

### Implementiert
- Golden test für deterministisches Postfix-Rendering in `internal/mail/configgen`.

### Edgecases
1. Sortierung von Domains/Mailboxes ist stabil (byte-identisch).
2. Config-Datei fehlt/ungültig → sauberer Fehler beim Laden.
3. Env-Overrides überschreiben Dateiwerte deterministisch.
4. Readiness liefert 503 falls ein Dienst nicht aktiv ist.
5. Atomic write sichert alte Datei als `.bak.<ts>`.
6. Exec-Timeout verhindert hängende Kommandos.

## CI / Build

- `agent/Makefile`: `fmt`, `lint`, `test`, `build`.
- `.github/workflows/agent-mail-skeleton-ci.yml`:
  - format check
  - lint (`go vet`)
  - tests
  - build
