# Mail backend runbook (single active backend)

## Operating model
- Exactly one mail backend is active at a time: `none`, `local`, `panel`, or `external`.
- `none` means mail is intentionally disabled; mailbox and alias APIs must return `MAIL_BACKEND_DISABLED`.
- Domain capabilities are the source of truth:
  - `capabilities.webspace=true` enables webspace/vhost attachment.
  - `capabilities.mail=true` enables mail attachment and DNS planning.

## Backend switch procedure
1. Set maintenance window for mailbox mutations.
2. Export current mail settings and domain/mail inventory.
3. Switch `mail_backend` to the target backend.
4. For each mail-capable domain, verify DNS plan contains DKIM/SPF/DMARC.
5. Run smoke checks: mailbox create/update/delete, alias create/delete.
6. End maintenance window.

## Migration notes
- `none -> local|panel|external`: enable `mail_enabled`, set backend, then re-run DNS plan/apply.
- `local|panel|external -> none`: disable `mail_enabled`; all mailbox operations should fail with standard error payload.
- `local <-> panel <-> external`: keep `mail_enabled=true`, switch backend, then run synchronization and DNS verification.

## Agent mail install and healthcheck
- Pre-flight test from repository root:
  - `make agent-test-role`
- Dry run (no changes, no restart):
  - `agent mail install --dry-run`
- Full install (packages/config/maps/services) with automatic post-install health snapshot:
  - `agent mail install`
- Read-only healthcheck (no writes/restarts):
  - `agent mail healthcheck`

Health states:
- `ok`: critical stack checks passed.
- `warning`: non-critical findings (for example outbound TCP/25 provider block or missing DKIM).
- `error`: critical failures (for example Dovecot/Postfix service/config/auth/TLS checks).
- `skipped`: optional probe was not runnable in current environment.

## Core/Panel test setup (PHPUnit) and manual UI verification

### 1) Core dependencies installieren
- Voraussetzungen: PHP 8.2+ und Composer.
- Im Repo:
  - `cd core`
  - `composer install`

### 2) Exakte Befehle für Core-Tests
- Alle Core-Tests:
  - `cd core && ./vendor/bin/phpunit`
- Nur Mail-Health relevante Tests (falls vorhanden):
  - `cd core && ./vendor/bin/phpunit tests/Controller/AdminMailHealthPageControllerTest.php tests/Service/MailNodeHealthAggregatorTest.php`

### 3) API-Prüfung für `/api/v1/admin/mail/nodes/{id}/health-report`
- Mit gültiger Admin-Session/Cookie im Browser oder API-Client:
  - Cache lesen: `GET /api/v1/admin/mail/nodes/{id}/health-report`
  - Neu berechnen: `GET /api/v1/admin/mail/nodes/{id}/health-report?refresh=1`
- Erwartung:
  - `200` mit `{ "ok": true, "report": { "overall": "...", "generated_at": "...", "checks": [...] } }`
  - Bei Agentproblemen: `502` mit Fehlermeldung „Mail-Healthcheck konnte nicht geladen werden.“

### 4) Panel-Prüfung für `admin/mail-system/health`
- Seite öffnen: `.../admin/mail/health` (Healthcheck-Seite im Mail-System).
- Pro Node prüfen:
  - Bereich **Mailserver** sichtbar.
  - `overall` und `generated_at` sichtbar.
  - Kategorien (Dovecot/Postfix/TLS/Auth/Delivery/DNS/Ports/Security/Relay) mit Checks sichtbar.
  - Check-Zeilen enthalten Status-Badge, Titel, Message sowie optional Recommendation/Probe.

### 5) Erwartete UI-Zustände
- **overall ok**:
  - Badge grün, keine kritischen Fehler, Messages überwiegend positiv/leer.
- **overall warning**:
  - Badge gelb/orange, z. B. outbound TCP/25 blockiert oder DKIM noch offen.
- **overall error**:
  - Badge rot, kritischer Stack-Check fehlt/fehlerhaft (z. B. Dovecot/Postfix/TLS/Auth).
- **Agent nicht erreichbar**:
  - UI zeigt Lade-/Fehlerhinweis, API-Call liefert Fehler (typisch 502/fehlender Report).
- **Snapshot älter als 15 Minuten**:
  - Hinweistext „Dieser Healthcheck ist älter als 15 Minuten.“ sichtbar.
- **Refresh-Button funktioniert**:
  - Klick auf „Mailserver erneut prüfen“ triggert `.../health-report?refresh=1`; danach aktualisiert sich der Node-Block.
