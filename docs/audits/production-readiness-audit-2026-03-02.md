# Production Readiness Audit (Core Symfony + Agent Go)

Datum: 2026-03-02
Scope: `core/`, `agent/`, `.github/workflows/`, `docs/`, `wiki/`, `scripts/`

> Umsetzungs-Taskboard: `docs/audits/production-readiness-taskboard-2026-03-02.md`
> Erweiterte Modul-/Plattform-Tasks (Gameserver, Voiceserver, Webspace, Mail, Linux/Windows, Plesk/aaPanel/cPanel/DirectAdmin/ISPConfig/HestiaCP, Admin-UI): `docs/audits/production-readiness-taskboard-2026-03-02.md` (TASK-013 bis TASK-020)

## ✅ Executive Summary

### Aktueller Stand (kurz)
- Das System ist funktional und kompilierbar; der Go-Agent baut/testet lokal stabil.
- Core und Agent haben bereits CI-Workflows, Security-Scans und erste Smoke-Skripte.
- Zwischen Core und Agent existieren sowohl Legacy- als auch v1-Routen; die Signatur/JWT-Authentisierung ist implementiert.
- Für „production-ready“ fehlen vor allem: belastbare Contract-Governance, einheitliches Error-/Tracing-Modell, harte Betriebsartefakte (Runbooks/DB-Strategie/Deployment-Playbooks), saubere Migrations-Organisation und klare Environment-Standards.

### Größte Risiken / Blocker
1. **P0 – Missing Info:** Keine produktionsrelevanten IaC-/Deployment-Manifeste (Docker Compose/K8s/Terraform/Helm) im Repo auffindbar; Go-Live-Prozess nicht belastbar verifizierbar.
2. **P0 – Missing Info:** Keine dokumentierte Rollback-/Migration-Strategie pro Environment (Blue/Green/Canary, Pre/Post-Checks, Datenmigrationen).
3. **P0:** PHPUnit läuft lokal nicht stabil (fatal process abort), dadurch Core-Releasequalität unsicher.
4. **P1:** Große Sammel-Migrationsdatei (`core/migrations/Migrations.php`) mit vielen Versionen in einer Datei erhöht Drift-/Merge-/Rollback-Risiko.
5. **P1:** Agent/Core Contract-Dokumentation ist fragmentiert (OpenAPI vorhanden, aber nicht vollständig als „source of truth“ verankert und nicht per CI gegen Implementierung geprüft).
6. **P1:** Kein durchgängiger Correlation-/Request-ID-Standard über Core↔Agent↔Job-Pipeline.
7. **P1:** Error-Model inkonsistent (teils `error`, teils `error_code`, teils freie Texte) erschwert Retries, Monitoring, Client-Stabilität.
8. **P1:** Messenger-Retry vorhanden, aber dedizierte DLQ-Operations-Runbooks/SLOs/Alerting fehlen.
9. **P1:** Konfigurationsquellen sind teilweise uneinheitlich dokumentiert (z. B. erforderliche Secrets/Keys nicht vollständig in `.env.example`).
10. **P2:** Logging ist teils unstrukturiert (Go `log.Printf`) und ohne garantierte Korrelation.

### Nächste 3 Schritte (sofort machbar)
1. Contract-Governance fixieren: ein einziges versioniertes API-Artefakt + Contract-Tests in CI (Core & Agent).
2. Core-Teststabilität herstellen (Fatal in PHPUnit analysieren/fixen), dann CI als Release-Gate „grün verpflichtend“ machen.
3. Betriebsbasis schließen: Deployment-/Rollback-Runbook + Migrationsstrategie + Monitoring/Alert-Matrix pro Service.

## Systemüberblick & Datenfluss

### Komponenten & Verantwortlichkeiten
- **Core (Symfony 8, PHP):** API, Backoffice, Orchestrierung, Persistenz, Job-Dispatching, Agent-Authentisierung.
- **Agent (Go):** Heartbeat, Job-Polling/-Ausführung, Result-Submit, lokale Service-HTTP APIs (Datei-/Webspace-/Mail-/Health-Endpunkte).
- **DB (Doctrine):** Persistenz für Domänenobjekte, Jobs, Agent-Metadaten, Metriken.
- **Queue/Messenger:** Async Verarbeitung über Symfony Messenger (`async`, `failed`).
- **CI/Security:** GitHub Actions für Core/Agent, CodeQL, Dependabot, Gitleaks, Dependency Review.

### Datenfluss (Textdiagramm)
1. Agent startet, lädt Config, baut signierten API-Client.
2. Agent sendet Heartbeat/Metrik-Batches an Core (`/agent/...` und teils `/api/v1/agent/...`).
3. Core authentisiert Agent (JWT + Signatur + Secret-Decrypt), persistiert Status/Metriken.
4. Agent pollt Jobs (Dispatch + Orchestrator), startet lokal Handler, sendet Ergebnisse zurück.
5. Core aktualisiert Jobstatus, triggert Folgeschritte/Audit-Logs.
6. Admin/Panel-Aktionen erzeugen Jobs über Core; Zustellung erfolgt über Poll-Modell.

## 📌 ToDo-Liste (priorisiert)

### ID: TODO-001
- **Priorität:** P0 (Blocker)
- **Bereich:** Infra / Docs
- **Beschreibung:** Missing Info – Es fehlen bereitstellbare Deployment-Artefakte (Compose/K8s/Helm/Terraform) im auditierbaren Scope.
- **Warum wichtig:** Ohne reproduzierbares Deployment kein verifizierbarer Production-Betrieb.
- **Akzeptanzkriterien:** Repo enthält produktive Deploy-Manifeste + Dokumentation für Dev/Staging/Prod inkl. Secrets/Ingress/Storage.
- **Konkrete Schritte:**
  - Infrastruktur-Standard festlegen (Compose vs K8s).
  - Artefakte versionieren (`deploy/`), inkl. Service-Dependencies.
  - Environment-Overlays definieren (dev/stage/prod).
  - Healthchecks, Ressourcenlimits, Restart-Policy hinterlegen.
  - Runbook für Rollout/Rollback dokumentieren.
- **Betroffene Dateien/Ordner:** `deploy/` (neu/ausbauen), `docs/setup/`, `wiki/`
- **Aufwand:** L
- **Abhängigkeiten:** keine

### ID: TODO-002
- **Priorität:** P0 (Blocker)
- **Bereich:** CI / Core
- **Beschreibung:** Core-Testpipeline ist nicht stabil (lokaler PHPUnit-Fatal-Abbruch).
- **Warum wichtig:** Release-Risiko hoch; Regressionen unentdeckt.
- **Akzeptanzkriterien:** `composer run test` läuft deterministisch grün in CI und lokal.
- **Konkrete Schritte:**
  - Failing/Fatal Test isolieren (`AccountSecurityFlowTest`).
  - Ursachenanalyse (Memory, Xdebug, Kernel-Bootstrap, DB-State).
  - Test fixturen stabilisieren (deterministische Daten/Transactions).
  - Crash reproduzierbar dokumentieren + Fix committen.
  - CI-Gate: Merge nur bei grünem Core-Testjob.
- **Betroffene Dateien/Ordner:** `core/tests/**`, `core/phpunit.xml.dist`, `.github/workflows/core-ci.yml`
- **Aufwand:** M
- **Abhängigkeiten:** keine

### ID: TODO-003
- **Priorität:** P0 (Blocker)
- **Bereich:** Shared / Docs / CI
- **Beschreibung:** Missing Info – Keine zentrale, verifizierte Go-Live-/Rollback-Strategie (inkl. DB-Migrationen) je Environment.
- **Warum wichtig:** Hohe Ausfallgefahr bei Deployments und Schema-Änderungen.
- **Akzeptanzkriterien:** Dokumentierte Rollout-/Rollback-Playbooks + CI/CD-Checks + Migrations-Gates vorhanden.
- **Konkrete Schritte:**
  - Release-Phasen definieren (pre-deploy/deploy/post-deploy).
  - DB-Migrations-Policy (expand/contract, backward compatibility) festschreiben.
  - Rollback-Kriterien inkl. Datenkompatibilität definieren.
  - Smoke-Test-Gates in Pipeline integrieren.
  - On-call Runbook + Eskalationspfad dokumentieren.
- **Betroffene Dateien/Ordner:** `docs/operations/`, `.github/workflows/*`, `core/deploy/`
- **Aufwand:** M
- **Abhängigkeiten:** TODO-001

### ID: TODO-004
- **Priorität:** P1
- **Bereich:** Core / DB
- **Beschreibung:** Doctrine-Migrationen sind als große Sammeldatei organisiert statt „eine Klasse pro Datei“.
- **Warum wichtig:** Merge-Konflikte, unklare Historie, riskante Rollbacks in Prod.
- **Akzeptanzkriterien:** Jede Migration in eigener Datei, linear nachvollziehbar, prod-safe geprüft.
- **Konkrete Schritte:**
  - Migrationssplit planen (ohne Historie zu brechen).
  - Neue Migrationskonvention im Team standardisieren.
  - „No destructive migration without expand/contract“ Regel einführen.
  - CI-Check ergänzen (Schema validate + migration dry-run).
- **Betroffene Dateien/Ordner:** `core/migrations/`, `core/config/packages/doctrine_migrations.yaml`
- **Aufwand:** L
- **Abhängigkeiten:** TODO-003

### ID: TODO-005
- **Priorität:** P1
- **Bereich:** Shared / API
- **Beschreibung:** Core↔Agent-Contract ist nicht als strikter, CI-geprüfter Single Source of Truth verankert.
- **Warum wichtig:** Breaking Changes und Drift zwischen Implementierung und Doku.
- **Akzeptanzkriterien:** OpenAPI/Proto versioniert, Contract-Tests in beiden CI-Pipelines, Breaking-Change-Check automatisiert.
- **Konkrete Schritte:**
  - Autoritative Spec definieren (`docs/api/*`).
  - Contract-Test-Suite erstellen (request/response/status/error).
  - CI-Job für diff/breaking-change erzwingen.
  - Versioning-/Deprecation-Policy verbindlich dokumentieren.
- **Betroffene Dateien/Ordner:** `docs/api/`, `wiki/API-Versionierung*.md`, `.github/workflows/*`
- **Aufwand:** M
- **Abhängigkeiten:** TODO-002

### ID: TODO-006
- **Priorität:** P1
- **Bereich:** Shared / Observability
- **Beschreibung:** Kein Ende-zu-Ende Correlation-ID-Standard über Core↔Agent↔Jobs.
- **Warum wichtig:** Incident-Analyse und Debugging in Prod stark erschwert.
- **Akzeptanzkriterien:** Jede Anfrage/Job hat `request_id`/`correlation_id`, propagiert in Logs, Audit und API-Responses.
- **Konkrete Schritte:**
  - Header-Standard definieren (`X-Request-ID`, `X-Correlation-ID`).
  - Middleware in Core + Agent implementieren.
  - Job-Metadaten um Korrelation erweitern.
  - Dashboards/Logs auf ID-Felder ausrichten.
- **Betroffene Dateien/Ordner:** `core/src/**`, `agent/internal/**`, `agent/cmd/agent/**`
- **Aufwand:** M
- **Abhängigkeiten:** TODO-005

### ID: TODO-007
- **Priorität:** P1
- **Bereich:** Shared / API
- **Beschreibung:** Uneinheitliches Error-Model (Format/Felder/HTTP-Codes) zwischen Endpunkten.
- **Warum wichtig:** Clients können Fehler nicht robust klassifizieren; Retry/Alerting unzuverlässig.
- **Akzeptanzkriterien:** Einheitliches Error-Schema (code/message/request_id/details), dokumentiert und getestet.
- **Konkrete Schritte:**
  - Error-Envelope definieren.
  - Core-Controller auf konsistente Responses umstellen.
  - Agent-HTTP Responses angleichen.
  - Contract-Tests für Fehlerpfade ergänzen.
- **Betroffene Dateien/Ordner:** `core/src/Module/**/Controller/**`, `agent/cmd/agent/*.go`, `docs/api/*`
- **Aufwand:** M
- **Abhängigkeiten:** TODO-005, TODO-006

### ID: TODO-008
- **Priorität:** P1
- **Bereich:** Core / Queue
- **Beschreibung:** Messenger-Retry vorhanden, aber DLQ-Betriebskonzept/Alerting/Reprocessing-Runbook fehlt.
- **Warum wichtig:** Verdeckte Backlogs/Poison-Messages führen zu stillen Funktionsverlusten.
- **Akzeptanzkriterien:** DLQ-Monitoring, Alarme, Reprocess-Prozedur und SLOs dokumentiert.
- **Konkrete Schritte:**
  - Queue-Metriken definieren (lag, fail rate, retries).
  - Alert-Schwellen festlegen.
  - `failed` Queue Reprocess-Skript/Anleitung bereitstellen.
  - On-call-Runbook ergänzen.
- **Betroffene Dateien/Ordner:** `core/config/packages/messenger.yaml`, `docs/operations/`, `scripts/`
- **Aufwand:** M
- **Abhängigkeiten:** TODO-003

### ID: TODO-009
- **Priorität:** P1
- **Bereich:** Core / Config / Security
- **Beschreibung:** ENV-Dokumentation unvollständig (wichtige Schlüssel fehlen in `.env.example` oder sind nur verstreut dokumentiert).
- **Warum wichtig:** Fehlkonfigurationen und Security-Risiken beim Provisioning.
- **Akzeptanzkriterien:** Vollständige, validierte ENV-Matrix pro Environment inkl. Pflicht/Default/Secret-Quelle.
- **Konkrete Schritte:**
  - Alle `env(...)` Keys inventarisieren.
  - `.env.example` und Setup-Doku synchronisieren.
  - Startup-Validation für Pflichtwerte ergänzen.
  - Secret-Quellen (Vault/KMS) dokumentieren.
- **Betroffene Dateien/Ordner:** `core/.env.example`, `core/config/**`, `docs/setup/**`, `wiki/Installation*.md`
- **Aufwand:** S
- **Abhängigkeiten:** TODO-001

### ID: TODO-010
- **Priorität:** P1
- **Bereich:** Agent / Reliability
- **Beschreibung:** HTTP-Retry/Backoff/Idempotenz für Core-Kommunikation nicht konsequent zentralisiert.
- **Warum wichtig:** Transiente Fehler führen zu Datenlücken oder doppelten Nebenwirkungen.
- **Akzeptanzkriterien:** Standardisierte Retry-Policy pro Endpoint-Typ + Idempotenzregeln + Tests.
- **Konkrete Schritte:**
  - Endpoint-Klassen definieren (safe retry / no retry).
  - Exponential Backoff + jitter zentral implementieren.
  - Idempotenz-Key-Strategie für mutierende Calls erweitern.
  - Integrationstests für 429/5xx/timeout Szenarien.
- **Betroffene Dateien/Ordner:** `agent/internal/api/client.go`, `agent/internal/panelagent/api/client.go`, `agent/cmd/agent/main.go`
- **Aufwand:** M
- **Abhängigkeiten:** TODO-005, TODO-007

### ID: TODO-011
- **Priorität:** P2
- **Bereich:** Observability
- **Beschreibung:** Logging teilweise unstrukturiert und nicht standardisiert.
- **Warum wichtig:** Eingeschränkte Suchbarkeit/Alerting.
- **Akzeptanzkriterien:** Strukturierte JSON-Logs in beiden Services mit minimalem Feldschema.
- **Konkrete Schritte:**
  - Log-Schema definieren (`level`, `service`, `request_id`, `agent_id`, `event`, `error_code`).
  - Go `log.Printf` Pfade auf strukturierten Logger migrieren.
  - Core Monolog Handler um Kontextfelder erweitern.
- **Betroffene Dateien/Ordner:** `agent/cmd/agent/*.go`, `agent/internal/panelagent/logging/logger.go`, `core/config/packages/monolog.yaml`
- **Aufwand:** M
- **Abhängigkeiten:** TODO-006

### ID: TODO-012
- **Priorität:** P2
- **Bereich:** Security / Compliance
- **Beschreibung:** Abhängigkeitssicherheit ist in CI vorhanden, aber keine dokumentierte Remediation-SLA/Policy.
- **Warum wichtig:** Schwachstellen bleiben ggf. zu lange offen.
- **Akzeptanzkriterien:** Definierte SLA (P1/P2 Vulnerabilities), regelmäßige Upgrade-Zyklen, Verantwortlichkeiten.
- **Konkrete Schritte:**
  - Security-Patch-Policy dokumentieren.
  - Dependabot/Govulncheck Findings in Tickets überführen.
  - Release-Kriterien um „keine offenen kritischen CVEs“ ergänzen.
- **Betroffene Dateien/Ordner:** `.github/workflows/security-*.yml`, `.github/dependabot.yml`, `docs/operations/unified-security.md`
- **Aufwand:** S
- **Abhängigkeiten:** keine

## 🧪 Test-Matrix

### Ist-Zustand
- Core: lint/phpstan/phpunit in CI vorgesehen; lokal phpunit derzeit instabil.
- Agent: `go test ./...` und `go vet ./...` laufen lokal.
- Security: Gitleaks, CodeQL, Dependency Review Workflows vorhanden.

### Soll-Zustand (kritisch priorisiert)
1. **Contract Tests (kritisch, fehlt):** Core↔Agent API success + error + backward compatibility.
2. **E2E Smoke (kritisch, teilweise):** deployte Umgebung mit Heartbeat→Job→Result Roundtrip.
3. **Migration Safety Tests (kritisch, fehlt):** migrate up/down dry-run + schema validation auf prod-nahem Dump.
4. **Resilience Tests (hoch, fehlt):** timeout/retry/network-partition für Agent-API Calls.
5. **Security Tests (hoch, teilweise):** Auth replay/nonce expiry/signature skew scenarios.
6. **Load/Soak (mittel, fehlt):** Job queue throughput + agent poll scalability.

## 🔒 Security Checklist (P0/P1)
- [ ] AuthN/AuthZ Contract für alle Agent-Endpunkte vollständig dokumentiert und getestet.
- [ ] Einheitliche Input-Validierung + konsistentes Error-Envelope.
- [ ] Secrets nur via Secret-Store, keine statischen Secrets in Deploy-Artefakten.
- [ ] Rotation-Runbook für Agent-Secrets inkl. Audit-Nachweis.
- [ ] Dependency-Vulnerability SLA und Blocking Rules in CI.
- [ ] Correlation IDs in Security-Events und Audit-Logs Ende-zu-Ende.

## 🚀 Release-Checkliste
- [ ] Build/Lint/Test/Contract-Tests in CI vollständig grün.
- [ ] DB-Migrationen prod-safe geprüft (expand/contract, rollback bewertet).
- [ ] Deployment-Runbook (inkl. Rollback) freigegeben.
- [ ] Monitoring aktiv: Error-Rate, Queue-Lag, Heartbeat-Staleness, Job-Failure-Rate.
- [ ] Alerts + On-call-Eskalation getestet.
- [ ] Backups + Restore-Drill durchgeführt (DB + Konfiguration).
- [ ] Smoke-Test nach Deployment erfolgreich (`scripts/smoke.sh` angepasst/automatisiert).
- [ ] Security-Scans ohne kritische Befunde.
