# Go-Agent Deterministic Mail Config Rendering Engine

## 1) Design / Spec

### Ziel
Deterministische, idempotente und rollback-fähige Apply-Pipeline für Mail-Config aus **DB Snapshot**.

### Architektur
```text
Snapshot Loader (Panel payload / cached DB snapshot)
  -> Renderer (text/template, strict funcs, sorted input)
  -> Writer (staging + backups + atomic replace)
  -> Validator (postfix/dovecot/opendkim config tests)
  -> Activator (reload + healthcheck)
  -> Rollback (bei Reload/Health-Fehler)
```

Implementierung:
- `agent/internal/mail/configrender/renderer.go`
- `agent/internal/mail/configrender/writer.go`
- `agent/internal/mail/configrender/validator.go`
- `agent/internal/mail/configrender/activator.go`
- `agent/internal/mail/configrender/pipeline.go`

## 2) Datenmodell / Inputs

`Snapshot` enthält deterministisch sortierbare Inputs:
- Domains, Users, Aliases, Forwardings
- Policies, RateLimits
- DKIM public metadata (`domain`, `selector`, `private_key_path`)
- TrustedHosts

Typen: `agent/internal/mail/configrender/types.go`

## 3) API / DTO

### Input DTO (`Snapshot`)
```go
Snapshot{
  Revision string
  Domains []Domain
  Users []User
  Aliases []Alias
  Forwardings []Forwarding
  Policies []Policy
  RateLimits []RateLimit
  DKIMKeys []DKIMKeyMetadata
  TrustedHosts []string
}
```

### Output DTO (`RenderBundle` / `ApplyResult`)
```go
RenderBundle{Revision, Files[]}
ApplyResult{Revision, ActivatedAt, FilesActivated[], Health{}}
```

## 4) Agent Contract

### Dovecot Entscheidung
- **SQL userdb/passdb** via `dovecot-sql.conf.ext`, um 100k Mailboxen ohne lokale passwd-Dateien zu skalieren.

### Dateibaum & Naming Convention

Aktive Pfade:
- `/etc/postfix/main.cf.d/50-panel-mail.cf`
- `/etc/postfix/virtual_mailbox_map`
- `/etc/postfix/virtual_aliases_map`
- `/etc/postfix/virtual_forwardings_map`
- `/etc/dovecot/dovecot-sql.conf.ext`
- `/etc/opendkim/KeyTable`
- `/etc/opendkim/SigningTable`
- `/etc/opendkim/TrustedHosts`

Staging:
- `/etc/staging/postfix/...`
- `/etc/staging/dovecot/...`
- `/etc/staging/opendkim/...`

Backups:
- `<file>.bak.<timestampUTC>`

### Template Ansatz (strict)
- Go `text/template`
- `Option("missingkey=error")`
- erlaubte funcs: `lower`, `join`
- Input wird vor Rendering sortiert (stable order) -> byte-identische Ausgaben.

### Pseudocode Apply Pipeline
```text
bundle = renderer.Render(snapshot)
writer.Stage(bundle)
validator.Validate(snapshot)
artifact = writer.BackupAndActivate(bundle)
health = activator.ReloadAndHealthcheck(snapshot)
if health fails:
    writer.Rollback(artifact)
    return rollback or health error
return success (revision, files, health)
```

### Validation ohne Shell Injection
Nur `exec.CommandContext(binary, fixedArgs...)`:
- `postfix check`
- `doveconf -n`
- `opendkim-testkey -x /etc/opendkim.conf -d <domain> -s <selector> -k <path>`

### Reload/Health
- `systemctl reload postfix|dovecot|opendkim`
- `systemctl is-active <service>`

## 5) Fehlerklassen & Mapping ins Panel (`job_results`)

`ApplyError.class`:
- `render_error`
- `write_error`
- `validate_error`
- `activate_error`
- `healthcheck_error`
- `rollback_error`

Empfohlenes Mapping:
- `job_results.status = failed`
- `job_results.error_code = <class>:<code>`
- `job_results.error_message = <message>`
- `job_results.payload` enthält `revision`, `service`, betroffene Datei(n)

## Edgecases
1. Snapshot unsortiert -> Renderer sortiert stabil.
2. Fehlender Template-Key -> hard fail (`missingkey=error`).
3. Leerer DKIM-Satz -> opendkim Test übersprungen, kein Reload.
4. Aktivierungsfehler nach Teilmenge Dateien -> Rollback aus `.bak`.
5. Healthcheck fail nach erfolgreichem Replace -> Rollback + failed result.
6. Doppelte Aliase im Snapshot -> deterministic output; upstream dedupe empfohlen.
7. Invalid path chars in DKIM path -> validate_error vor activation.
8. Postfix check fail -> keine Aktivierung.
9. Dovecot SQL config invalid -> keine Aktivierung.
10. Staging write permission denied -> write_error.
