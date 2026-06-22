# Release Process (Pre / Deploy / Post)

## Ziel und Geltungsbereich
Dieser Prozess definiert verbindliche Gates für Rollout und Rollback in `dev`, `stage` und `prod`.
Er ist auf zero-downtime-fähige Deployments mit **Expand/Contract**-Migrationsstrategie ausgelegt.

## Rollen
- **Release Driver**: führt die Schritte aus, dokumentiert Gates.
- **Reviewer/On-Call**: Go/No-Go-Freigabe, Monitoring während Rollout.
- **DB Owner (bei Schema-Änderungen)**: prüft Migrationsverträglichkeit.

## Phase 1: Pre-Deploy (verbindliche Go/No-Go Gates)

### 1.1 Change Readiness
- Ticket/PR ist gemergt, Changelog/Release Notes vorhanden.
- Risiko-Klassifizierung ist dokumentiert (low/medium/high).
- Rollback-Plan ist vorhanden und auf die Änderung angepasst.

### 1.2 Build- und Test-Gates
- Unit-/Integrationstests grün.
- Pipeline-Gates grün (inkl. Smoke Gate).
- Keine offenen Blocker-Incidents.

### 1.3 Config-Validation (Gate vor Deploy)
Für jede Zielumgebung muss die Compose-Konfiguration valide sein:

```bash
docker compose -f deploy/compose/<env>/docker-compose.yml config
```

Fehlschlag => **No-Go**.

### 1.4 Datenbank-Gate
- Geplante Migrationen folgen `docs/operations/db-migrations.md`.
- Keine destruktiven Schema-Operationen im Expand-Schritt.
- Rückwärtskompatibilität zwischen altem und neuem App-Stand ist sichergestellt.

## Phase 2: Deploy
1. Deploy in Reihenfolge: `dev` -> `stage` -> `prod`.
2. Rollout mit Standardverfahren (Compose/Orchestrator).
3. Nach jedem Environment ein kurzes Verify-Fenster einhalten.

Empfohlener Compose-Befehl:

```bash
docker compose -f deploy/compose/<env>/docker-compose.yml up -d
```

## Phase 3: Post-Deploy (Smoke Gate)

### 3.1 Automatischer Smoke-Test
Nach Deploy muss `scripts/smoke.sh` erfolgreich laufen:

```bash
SMOKE_DEPLOY=1 DEPLOY_ENV=<env> ./scripts/smoke.sh
```

Exit-Code-Bedeutung:
- `0`: Go
- `2`: Warnungen vorhanden (manuelle Bewertung erforderlich)
- `!=0/2`: No-Go / Rollback prüfen

### 3.2 Monitoring-Fenster
- 15-30 Minuten aktive Beobachtung (Logs, Error-Rate, Healthchecks, Queue-Lag).
- Keine signifikante Regression in Kernmetriken.

## Go/No-Go Checkliste

### Go nur wenn alle Punkte erfüllt sind
- [ ] Config-Validation erfolgreich (inkl. Startup-ENV-Validation).
- [ ] Tests/Pipeline grün.
- [ ] DB-Migrationsstrategie Expand/Contract-konform.
- [ ] Smoke Gate nach Deploy bestanden.
- [ ] Monitoring im Verify-Fenster unauffällig.

### No-Go wenn einer der Punkte zutrifft
- [ ] Config ungültig oder fehlende Secrets.
- [ ] Migrationsplan destruktiv oder nicht rückwärtskompatibel.
- [ ] Smoke Gate fehlgeschlagen.
- [ ] Kritische Fehler/Incident nach Deploy.

## Verweise
- Migrationsstrategie: `docs/operations/db-migrations.md`
- Rollback-Runbook: `docs/operations/rollback.md`
- Operativer Rollout: `docs/operations/rollout.md`

- Secrets-Betrieb: `docs/operations/secrets.md`
- ENV-Matrix: `docs/setup/env.md`
