# Hosting Panel Capability Matrix (TASK-017)

Quelle: `docs/integrations/panels/capabilities.yml`.

## Matrix

| Panel | Strategie | Versionen | Least Privilege | Zertifizierung (pro Version) |
|---|---|---|---|---|
| Plesk | API-first, CLI fallback (limited) | Obsidian >=18.0.55 | Dedizierter API-User, keine Shell-Logins im Regelbetrieb | Token-Rotation, Domain CRUD, Mailbox CRUD, DNS-Reconcile, Rollback |
| aaPanel | API-first, CLI fallback (required) | 7.0.x, 8.0.x | API-Key + sudo Allowlist (`bt`) | API-Signatur, Site CRUD, DB-Rotation, SSL-Renew, Permission Boundary |
| cPanel | API-first, CLI fallback (limited) | 110, 118 | Reseller-Token, kein root SSH in Standard-Flows | Token-Scopes, Account CRUD, DNS Apply, Mail Forwarder, AutoSSL |
| DirectAdmin | API-first, CLI fallback (limited) | 1.66, 1.67 | Reseller Login + forced-command SSH optional | API Auth, User CRUD, DNS Lifecycle, Mail CRUD, Backup Trigger |
| ISPConfig | API-first, CLI fallback (required) | 3.2.x, 3.3.x | Remote API User + sudo Allowlist | Session Rotation, Client/Website CRUD, Mail Domain CRUD, DNS Reconcile, Cert Renew |
| HestiaCP | CLI fallback (required) | 1.8.x | UNIX Operator + Command Allowlist + Audit | Sudo Allowlist, Web CRUD, DNS CRUD, Mail CRUD, Backup Schedule |
| tech-preview | API-first, kein CLI fallback | 0.x | Ephemeral Token, synthetische Scopes | Capability-Contract, Error-Contract, Smoke `ping` |

## Adapterstrategie

### Standardentscheidung API-first vs CLI-fallback

1. **API-first default**: Adapter nutzen die native Panel-API, wenn idempotente Endpunkte und Authz-Scopes vorhanden sind.
2. **CLI-fallback nur kontrolliert**:
   - `limited`: nur Rollback/Reconciliation, kein regulärer Provisioning-Flow.
   - `required`: CLI ist offizieller Teil des Betriebsmodells (z. B. HestiaCP).
3. **Least Privilege Pflicht**: Jeder Adapter muss Scope-Mapping und Credential-Rotation dokumentieren.

### Standardisierter Ausführungs- und Fehlervertrag

Core/Agent Adapter müssen folgende Kernoperationen abdecken:

- `discoverCapabilities(panel, version, context)`
- `executeAction(action, payload, context)`

Fehlercodes sind panel-unabhängig und werden als stabiler Contract behandelt:

- `ADAPTER_UNAVAILABLE`
- `ACTION_UNSUPPORTED`
- `AUTHENTICATION_FAILED`
- `AUTHORIZATION_FAILED`
- `RATE_LIMITED`
- `TEMPORARY_FAILURE`
- `VALIDATION_FAILED`
- `INTERNAL_ERROR`

## Referenzintegration: `tech-preview`

`tech-preview` dient als End-to-End Referenz mit Smoke-Test:

- Capability Discovery liefert einen stabilen Satz (`ping`, `account.describe`).
- `executeAction("ping")` liefert deterministisches `ok`.
- Nicht unterstützte Actions erzeugen `ACTION_UNSUPPORTED`.
