# Datenbank-Migrationen (Expand / Contract)

## Ziel
Schemaänderungen müssen ohne harte Downtime und ohne Breaking Changes ausgerollt werden.
Verbindlich ist das **Expand/Contract**-Vorgehen.

## Grundregeln
1. **Backwards compatible first**: Neue App-Version muss mit altem und neuem Schema arbeiten.
2. **No-destructive rule (Expand-Phase)**: Kein `DROP COLUMN`, `DROP TABLE`, `RENAME` oder inkompatibler Typwechsel in Expand.
3. **Zwei-Phasen-Rollout**: Erst expandieren, dann Anwendung umstellen, dann kontrahieren.
4. **Rollback-fähig bleiben**: Während Expand muss ein App-Rollback ohne DB-Restore möglich sein.

## Phase A: Expand
Zulässige Änderungen:
- Neue Tabellen/Spalten (nullable oder mit sicherem Default).
- Neue Indizes/Constraints, sofern kompatibel.
- Shadow-Spalten/-Tabellen für spätere Migration.

Typischer Ablauf:
1. Migration erstellen und in Staging ausführen.
2. Backfill idempotent per Batch-Job durchführen.
3. Alte und neue Schreibpfade parallel betreiben (dual write, falls nötig).
4. Lesepfad schrittweise auf neues Schema umstellen.

## Phase B: Contract
Nur wenn folgende Kriterien erfüllt sind:
- Neue Version stabil in Produktion.
- Monitoring über mindestens einen Release-Zyklus unauffällig.
- Kein aktiver Consumer nutzt alte Spalten/Tabellen.

Zulässige Änderungen in Contract:
- Entfernen obsoleter Spalten/Tabellen/Indizes.
- Entfernen temporärer Dual-Write-Logik.

## Verbotene Anti-Patterns
- Direkt destruktive Migration zusammen mit App-Deploy.
- Nicht idempotente Backfill-Skripte ohne Wiederanlaufstrategie.
- Harte NOT NULL-Änderungen ohne vorherigen Backfill.

## Pflicht-Checkliste pro Migrations-PR
- [ ] Expand/Contract-Phase explizit benannt.
- [ ] Rückwärtskompatibilität dokumentiert.
- [ ] Rollback-Auswirkung beschrieben (App-only vs DB-Restore erforderlich).
- [ ] Backfill-Plan inkl. Laufzeitabschätzung vorhanden.
- [ ] Monitoring/Smoke-Kriterien für Erfolg definiert.

## Beispiel-Muster
1. **Release N (Expand)**: `add column new_status nullable`.
2. **Release N+1 (Switch)**: App liest/schreibt `new_status` (mit Fallback auf alt).
3. **Release N+2 (Contract)**: `drop column old_status`.

## Konvention: Eine Migrationsklasse pro Datei (TASK-004)

Ab sofort gilt für **neue** Migrationen:
- Dateiname: `core/migrations/VersionYYYYMMDDHHMMSS.php`
- Inhalt: exakt **eine** Klasse `final class VersionYYYYMMDDHHMMSS extends AbstractMigration`
- Jede Datei enthält nur eine Version und beschreibt genau einen reviewbaren Schritt.

### Legacy-Strategie für `core/migrations/Migrations.php`
- `core/migrations/Migrations.php` bleibt als **Legacy-Freeze** im Repository, damit bestehende Historie und bereits produktiv ausgeführte Versionen unverändert ladbar bleiben.
- Neue Migrationen dürfen **nicht** mehr in `Migrations.php` ergänzt werden.
- Wenn Altbestand schrittweise aufgelöst werden soll, erfolgt das in einem separaten Migrations-Housekeeping-Task mit explizitem Risiko-/Rollback-Plan.

### Generate-Workflow
1. Neue Migration erzeugen (Klassenname im Timestamp-Format):
   ```bash
   cd core
   php bin/console make:migration
   ```
2. Datei nach `core/migrations/VersionYYYYMMDDHHMMSS.php` prüfen/umbenennen, falls Generator abweicht.
3. SQL-Statements auf Expand/Contract-Regeln prüfen.
4. Lokal Safety-Checks ausführen:
   ```bash
   cd core
   APP_ENV=test DATABASE_URL="sqlite:///%kernel.project_dir%/var/task004-check.db" php bin/console doctrine:schema:validate --skip-sync -n
   APP_ENV=test DATABASE_URL="sqlite:///%kernel.project_dir%/var/task004-check.db" php bin/console doctrine:migrations:migrate --dry-run --no-interaction --allow-no-migration -n
   ```

### Review-Regeln (zusätzlich zur PR-Checkliste)
- Kein PR darf `core/migrations/Migrations.php` funktional erweitern.
- Jede neue Migrationsdatei muss Namenskonvention und „eine Klasse pro Datei“ einhalten.
- Jede Migrations-PR muss CI-Safety-Checks erfolgreich durchlaufen:
  - `doctrine:schema:validate`
  - `doctrine:migrations:migrate --dry-run`

