# Rollback Runbook

## Ziel
Schnelles, kontrolliertes Zurückrollen bei fehlgeschlagenem Rollout mit Fokus auf Service-Verfügbarkeit und Datenkonsistenz.

## Rollback-Kriterien (Trigger)
Rollback einleiten, wenn mindestens eines zutrifft:
- Smoke Gate nach Deploy schlägt fehl.
- Kritische User-Flows sind regressiv (Login, API, Provisioning, Billing-kritische Pfade).
- Error-Rate/Latenz verletzt SLO über das Verify-Fenster.
- Dateninkonsistenzen oder Migrationsfehler treten auf.

## Vorbedingungen
- Last-known-good (LKG) Commit/Artifact ist bekannt.
- Verantwortliche On-Call-Rolle ist benannt.
- Laufende Migrations-/Backfill-Jobs sind identifiziert.

## Standard-Rollback (App/Infra)
1. Incident markieren und Deploy-Freeze setzen.
2. Auf LKG-Version zurückgehen (Code/Artifact/Image-Tag).
3. Stack neu ausrollen:
   ```bash
   docker compose -f deploy/compose/<env>/docker-compose.yml up -d
   ```
4. Healthchecks prüfen (`core`, `agent`, `db`, `queue`).
5. Smoke erneut ausführen:
   ```bash
   SMOKE_DEPLOY=1 DEPLOY_ENV=<env> ./scripts/smoke.sh
   ```
6. Verify-Fenster 15-30 Minuten überwachen.

## Datenkompatibilität und DB-Risiken
- Expand-Migrationen müssen rollback-fähig sein (App kann auf altes Verhalten zurück).
- Bei Contract/destruktiven Änderungen ist App-only-Rollback ggf. **nicht** ausreichend.
- DB-Restore nur als letzter Schritt mit klarem Datenverlust-Risiko und Freigabe.

## Entscheidungsbaum
- **Nur App-Regression, Schema kompatibel** -> App-Rollback.
- **Migration fehlerhaft, aber expand-only** -> App-Rollback + Migration fix forward.
- **Destruktiver Contract bereits live** -> ggf. Restore-Runbook + Incident-Command.

## Nachbereitung (Pflicht)
- Root Cause Analysis dokumentieren.
- Ticket mit Präventivmaßnahmen anlegen (Tests/Gates/Monitoring).
- Release-Checkliste aktualisieren, um Wiederholung zu verhindern.

## Verwandte Runbooks
- Queue/DLQ Betrieb: [Queue Operations Runbook](./queues.md)
