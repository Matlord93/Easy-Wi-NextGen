# Production Readiness – Taskboard (Core + Agent)

Status: initialisiert  
Quelle: `docs/audits/production-readiness-audit-2026-03-02.md`

## Ziel
Dieses Board übersetzt die Audit-Ergebnisse in **konkret umsetzbare Tasks** inklusive Reihenfolge, Ownership und DoD.

---

## Phase 0 – Blocker (P0)

### TASK-001 – Deployment-Artefakte & Environment-Definition (Missing Info)
- **Priorität:** P0
- **Bereich:** Infra / Docs
- **Owner (Vorschlag):** Platform/DevOps
- **Beschreibung:** Fehlende deploybare Artefakte (Compose/K8s/Helm/Terraform) inkl. klarer dev/stage/prod Trennung.
- **Deliverables / DoD:**
  - [ ] Verbindlicher Deployment-Standard dokumentiert (z. B. K8s + Helm ODER Compose).
  - [ ] Versionierte Manifeste im Repo (`deploy/`).
  - [ ] Environment-Overlays (dev/stage/prod) inkl. Secrets-Referenzen.
  - [ ] Ressourcenlimits, Probes, Restart-Policy gesetzt.
  - [ ] Runbook für Rollout/Rollback vorhanden.
- **Abhängigkeiten:** keine

### TASK-002 – Core PHPUnit Stabilisierung
- **Priorität:** P0
- **Bereich:** Core / CI
- **Owner (Vorschlag):** Core-Team
- **Beschreibung:** `composer run test` bricht lokal mit Fatal in `AccountSecurityFlowTest` ab.
- **Deliverables / DoD:**
  - [ ] Fehler reproduzierbar dokumentiert (inkl. Ursache).
  - [ ] Fix implementiert und verifiziert.
  - [ ] CI-Job `core-ci` ist stabil grün.
  - [ ] Merge-Policy: Core-Merges nur bei grünem Test-Gate.
- **Abhängigkeiten:** keine

### TASK-003 – Rollback-/Migrations-Playbook (Missing Info)
- **Priorität:** P0
- **Bereich:** Shared / Docs / CI
- **Owner (Vorschlag):** Tech Lead + DevOps + DBA
- **Beschreibung:** Fehlende verbindliche Rollout-/Rollback-Strategie inkl. DB-Migrationsstrategie.
- **Deliverables / DoD:**
  - [ ] Pre-/Deploy-/Post-Deploy-Prozess dokumentiert.
  - [ ] DB-Strategie (expand/contract) verbindlich beschrieben.
  - [ ] Rollback-Kriterien inkl. Datenkompatibilität definiert.
  - [ ] Smoke-Gates in Pipeline integriert.
- **Abhängigkeiten:** TASK-001

---

## Phase 1 – Hohes Risiko (P1)

### TASK-004 – Doctrine-Migrationen restrukturieren
- **Priorität:** P1
- **Bereich:** Core / DB
- **Owner (Vorschlag):** Core + DBA
- **Beschreibung:** Sammel-Migrationsdatei in einzelne Migrationen überführen + Konvention festlegen.
- **Deliverables / DoD:**
  - [ ] Migrationskonvention „eine Migration pro Datei“ dokumentiert.
  - [ ] CI-Prüfung für Migration dry-run + schema validate.
  - [ ] Zukünftige destruktive Änderungen nur expand/contract.
- **Abhängigkeiten:** TASK-003

### TASK-005 – API Contract als Single Source of Truth
- **Priorität:** P1
- **Bereich:** Shared / API / CI
- **Owner (Vorschlag):** Core + Agent
- **Beschreibung:** OpenAPI/Proto verbindlich versionieren und gegen Implementierung testen.
- **Deliverables / DoD:**
  - [ ] Verbindliche Contract-Quelle definiert (`docs/api/*`).
  - [ ] Contract-Tests in Core- und Agent-CI.
  - [ ] Breaking-Change-Check als CI-Blocker.
- **Abhängigkeiten:** TASK-002

### TASK-006 – Correlation-ID Ende-zu-Ende
- **Priorität:** P1
- **Bereich:** Shared / Observability
- **Owner (Vorschlag):** Core + Agent
- **Beschreibung:** Durchgängige `X-Request-ID`/`X-Correlation-ID` Propagation über API, Jobs, Logs, Audit.
- **Deliverables / DoD:**
  - [ ] Header-Standard dokumentiert.
  - [ ] Middleware/Filter in Core und Agent implementiert.
  - [ ] IDs in Logs/Audits/Fehlerantworten enthalten.
- **Abhängigkeiten:** TASK-005

### TASK-007 – Einheitliches Error-Model
- **Priorität:** P1
- **Bereich:** Shared / API
- **Owner (Vorschlag):** Core + Agent
- **Beschreibung:** Standardisiertes Fehlerformat für alle relevanten Endpunkte.
- **Deliverables / DoD:**
  - [ ] Error-Envelope definiert (`code`, `message`, `request_id`, `details`).
  - [ ] Endpunkte auf Standard umgestellt.
  - [ ] Fehlerpfade durch Contract-Tests abgesichert.
- **Abhängigkeiten:** TASK-005, TASK-006

### TASK-008 – Messenger/DLQ Operations Readiness
- **Priorität:** P1
- **Bereich:** Core / Queue / Ops
- **Owner (Vorschlag):** Core + SRE
- **Beschreibung:** DLQ-Sichtbarkeit, Alerting und Reprocessing formalisieren.
- **Deliverables / DoD:**
  - [ ] Queue-Metriken + Alert-Schwellen definiert.
  - [ ] Reprocessing-Prozedur dokumentiert.
  - [ ] On-call Runbook erweitert.
- **Abhängigkeiten:** TASK-003

### TASK-009 – ENV-/Secret-Matrix konsolidieren
- **Priorität:** P1
- **Bereich:** Core / Security / Docs
- **Owner (Vorschlag):** Core + Security
- **Beschreibung:** Vollständige Konfigurationsmatrix inkl. Pflichtwerten, Defaults und Secret-Quelle.
- **Deliverables / DoD:**
  - [ ] Inventar aller `env(...)`-Keys erstellt.
  - [ ] `.env.example` und Doku synchronisiert.
  - [ ] Startup-Validation für Pflichtwerte vorhanden.
- **Abhängigkeiten:** TASK-001

### TASK-010 – Retry/Backoff/Idempotenz Core↔Agent härten
- **Priorität:** P1
- **Bereich:** Agent / Shared Reliability
- **Owner (Vorschlag):** Agent-Team
- **Beschreibung:** Zentralisierte Retry-Policy + Idempotenz-Regeln für mutierende Calls.
- **Deliverables / DoD:**
  - [ ] Endpoint-Klassen (retrybar/nicht retrybar) dokumentiert.
  - [ ] Exponential Backoff + Jitter zentral implementiert.
  - [ ] Tests für 429/5xx/Timeout vorhanden.
- **Abhängigkeiten:** TASK-005, TASK-007

---

## Phase 2 – Absicherung/Optimierung (P2)

### TASK-011 – Strukturierte Logs vereinheitlichen
- **Priorität:** P2
- **Bereich:** Observability
- **Owner (Vorschlag):** Core + Agent
- **Beschreibung:** JSON-Logging mit Mindestfeldschema in beiden Systemen.
- **Deliverables / DoD:**
  - [ ] Gemeinsames Log-Schema dokumentiert.
  - [ ] Kritische Go-Pfade von `log.Printf` auf strukturiertes Logging migriert.
  - [ ] Monolog-Kontext um Correlation-Felder erweitert.
- **Abhängigkeiten:** TASK-006

### TASK-012 – Vulnerability Remediation SLA
- **Priorität:** P2
- **Bereich:** Security / Compliance
- **Owner (Vorschlag):** Security + Engineering Management
- **Beschreibung:** Verbindliche SLAs und Blocker-Regeln für Sicherheitsbefunde.
- **Deliverables / DoD:**
  - [ ] SLA (kritisch/hoch/mittel) schriftlich festgelegt.
  - [ ] Verantwortlichkeiten und Ticketprozess definiert.
  - [ ] Release-Gate „keine offenen kritischen CVEs“ aktiv.
- **Abhängigkeiten:** keine

---

## Empfohlene Reihenfolge (Roadmap)
1. **P0 komplett schließen**: TASK-001, TASK-002, TASK-003
2. **Contract/Observability Basis**: TASK-005, TASK-006, TASK-007
3. **Betriebssicherheit vertiefen**: TASK-004, TASK-008, TASK-009, TASK-010
4. **Nachziehen P2**: TASK-011, TASK-012

## Vorschlag für Tracking in eurem Tool (Jira/Linear/GitHub)
- Epics:
  - EPIC-A: Deployment & Operations Foundation
  - EPIC-B: Contract & API Reliability
  - EPIC-C: Database & Migration Safety
  - EPIC-D: Security & Compliance
- Labels:
  - `production-readiness`, `core`, `agent`, `infra`, `security`, `p0`, `p1`, `p2`

---

## Phase 3 – Produktmodule & Plattformabdeckung (neu)

### TASK-013 – Gameserver Readiness Matrix (Linux + Windows)
- **Priorität:** P0
- **Bereich:** Gameserver / Agent / Core
- **Owner (Vorschlag):** Gameserver-Team + Agent-Team
- **Beschreibung:** Vollständige Betriebs-/Feature-Matrix für Gameserver über Linux und Windows aufbauen und validieren.
- **Deliverables / DoD:**
  - [ ] Matrix je Spielprofil: Install, Start/Stop/Restart, Config-Render, Backup/Restore, Logs, Monitoring.
  - [ ] Linux-Systemd und Windows-Service-Pfade reproduzierbar dokumentiert.
  - [ ] E2E-Smoke für Provisionierung + Lifecycle je OS automatisiert.
  - [ ] Bekannte Limitierungen je Spiel und OS transparent dokumentiert.
- **Abhängigkeiten:** TASK-001, TASK-005

### TASK-014 – Voiceserver Readiness Matrix (TS3/TS6/SinusBot)
- **Priorität:** P1
- **Bereich:** Voiceserver / Core / Agent
- **Owner (Vorschlag):** Voice-Team
- **Beschreibung:** TS3/TS6/SinusBot Betriebsfähigkeit inkl. Query/Port-/Fallback-Verhalten und Recovery-Härtung absichern.
- **Deliverables / DoD:**
  - [ ] Matrix für Create/Update/Start/Stop/Backup/Token/Viewer pro Voice-Modul.
  - [ ] Retry-/Fallback-Szenarien (Query Timeout, Port failover) getestet.
  - [ ] Monitoring/Alerting für Voice-spezifische KPIs definiert.
- **Abhängigkeiten:** TASK-006, TASK-007, TASK-010

### TASK-015 – Webspace Readiness Matrix (Apache/Nginx + Windows IIS falls unterstützt)
- **Priorität:** P0
- **Bereich:** Webspace / Agent / Core
- **Owner (Vorschlag):** Webspace-Team
- **Beschreibung:** Dateiverwaltung, VHost-Apply, SSL, Owner/ACL, Deploy-Pipeline je OS und Webstack absichern.
- **Deliverables / DoD:**
  - [ ] Matrix für Webspace-Lifecycle inkl. File API Security (Traversal, Locks, Timeouts).
  - [ ] VHost-Templates + Zertifikats-Flow (Issue/Renew/Revoke) Ende-zu-Ende getestet.
  - [ ] Einheitliche Fehlercodes/Runbook für häufige Apply-Fehler.
- **Abhängigkeiten:** TASK-005, TASK-007, TASK-011

### TASK-016 – Mail-Server Readiness Matrix
- **Priorität:** P0
- **Bereich:** Mail / Agent / Core
- **Owner (Vorschlag):** Mail-Team + Security
- **Beschreibung:** Domain/Mailbox/Alias/DKIM/SPF/DMARC/Telemetry/Log-Ingest pro Zielplattform verbindlich absichern.
- **Deliverables / DoD:**
  - [ ] Matrix für Mail-Lifecycle (Domain, Mailbox, Alias, DNS-Validation, DKIM Rotation).
  - [ ] Contract für Mail Metrics + Logs Batch produktiv validiert.
  - [ ] Abuse/Security-Flows (RateLimit, Audit, Alert) mit Testfällen abgedeckt.
- **Abhängigkeiten:** TASK-005, TASK-006, TASK-007

### TASK-017 – Control-Panel Integrationsprogramm (Plesk, aaPanel, cPanel, DirectAdmin, ISPConfig, HestiaCP)
- **Priorität:** P0 (Missing Info)
- **Bereich:** Shared / Integrations / Docs
- **Owner (Vorschlag):** Architecture + Integrations-Team
- **Beschreibung:** Ziel ist Full-Compatibility; dafür fehlen verbindliche Adapter-Strategie, Capability-Matrix und Abnahmekriterien je Panel.
- **Deliverables / DoD:**
  - [ ] Capability-Matrix je Panel (Accounts, Domains, DNS, Mail, SSL, Webspace, Limits).
  - [ ] Integrationsmodus je Panel festgelegt (API-first, CLI fallback, unsupported scope).
  - [ ] Security/Permission-Modell je Panel dokumentiert (least privilege).
  - [ ] Zertifizierungs-Checkliste je Panel + Version erstellt.
  - [ ] Automatisierte Kompatibilitätstests für mindestens eine Referenzversion je Panel.
- **Abhängigkeiten:** TASK-001, TASK-003, TASK-005

### TASK-018 – Linux/Windows Parity-Programm
- **Priorität:** P0
- **Bereich:** Agent / QA / Release
- **Owner (Vorschlag):** Agent-Team + QA
- **Beschreibung:** Funktionsparität und Supportgrenzen zwischen Linux und Windows explizit festlegen und testen.
- **Deliverables / DoD:**
  - [ ] Feature-Parity-Tabelle mit Status: supported/partial/not supported.
  - [ ] CI-Matrix mit Linux + Windows Build/Test/Smoke.
  - [ ] Release Notes enthalten OS-spezifische Known Issues.
- **Abhängigkeiten:** TASK-013, TASK-015, TASK-016

### TASK-019 – Admin-Panel IA/UX Konsolidierung (Informationsarchitektur)
- **Priorität:** P1
- **Bereich:** UI/UX / Core (Admin)
- **Owner (Vorschlag):** Product + UX + Core
- **Beschreibung:** Admin-UI ist derzeit zu fragmentiert; Navigation, Seitenstruktur und Aktionen müssen zusammengeführt und vereinfacht werden.
- **Deliverables / DoD:**
  - [ ] Navigationsaudit (alle Admin-Module, Redundanzen, tote Pfade).
  - [ ] Neue IA mit klaren Domänenbereichen (Nodes, Instances, Webspace, Mail, Voice, Security, Billing, Ops).
  - [ ] Zusammenlegung redundanter Seiten/Formulare inkl. Redirect-Strategie.
  - [ ] Einheitliche Pattern Library (Filter, Tabellen, Form-Layouts, Action Bars, Status-Badges).
  - [ ] UX-Abnahme mit 5–10 Kern-Workflows (Zeit bis Ziel, Klickpfade reduziert).
- **Abhängigkeiten:** TASK-005, TASK-006, TASK-007

### TASK-020 – Admin-Panel Technical Refactor Backlog
- **Priorität:** P1
- **Bereich:** Core / Frontend / Symfony
- **Owner (Vorschlag):** Core-Frontend-Team
- **Beschreibung:** Technische Umsetzung der IA/UX-Konsolidierung mit wartbarer Modulstruktur und wiederverwendbaren Komponenten.
- **Deliverables / DoD:**
  - [ ] Modulweise Refactor-Pakete (pro Domäne) mit Feature-Flags.
  - [ ] Gemeinsame Twig-Komponenten und konsistente Controller/Route-Patterns.
  - [ ] UI-Regression-Tests + Smoke für zentrale Admin-Flows.
  - [ ] Migrationspfad für bestehende Links/Bookmarks/ACLs dokumentiert.
- **Abhängigkeiten:** TASK-019

---

## Ergänzende Governance für „läuft überall“-Ziel
- Für die Zielaussage „läuft überall“ gilt: **nur freigegebene Kombinationen** aus OS + Panel + Modul gelten als offiziell unterstützt.
- Jede Kombination erhält einen Support-Status (`ga`, `beta`, `tech-preview`, `unsupported`) inkl. Testnachweis.
- Release darf nur erfolgen, wenn P0-Tasks und alle `ga`-Kombinationen in der Kompatibilitätsmatrix grün sind.
