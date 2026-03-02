# Queue Operations Runbook (Symfony Messenger)

## Ziel
Betriebsfähigkeit für `async` und `failed` Queue sicherstellen: frühzeitig Probleme erkennen, sauber triagieren und kontrolliert reprocessen.

## Queue-Topologie
- `async` Transport: Primäre Verarbeitung.
- `failed` Transport: Dead-Letter-Queue (DLQ) über `failure_transport`.
- Retry-Strategie (`async`): `max_retries=3`, `delay=1000ms`, `multiplier=2`, `max_delay=60000ms`.

## Metriken und Alert-Schwellen
Die folgenden Signale müssen als Metriken in Monitoring/Alerting vorhanden sein (z. B. aus RabbitMQ/Redis + App-Logs).

| Metrik | Bedeutung | Warnung | Kritisch |
|---|---|---:|---:|
| Queue Lag (`async_messages_ready`) | Anzahl wartender Nachrichten in `async` | > 200 für 10 min | > 1000 für 5 min |
| Fail Rate (`failed_per_5m / processed_per_5m`) | Anteil fehlgeschlagener Jobs | > 2% für 10 min | > 5% für 5 min |
| Retry-Rate (`retried_per_5m`) | Anzahl Retrys (vor DLQ) | > 50 / 5 min | > 200 / 5 min |
| Dead-letter Size (`failed_messages_ready`) | Größe der DLQ `failed` | > 20 für 10 min | > 100 für 5 min |

> Hinweis: Schwellen sind konservative Startwerte und müssen nach Lastprofil kalibriert werden.

## Triage -> Root Cause -> Reprocess -> Prevent Recurrence

### 1) Triage
1. Queue-Lage prüfen:
   ```bash
   ./scripts/messenger-inspect-failed.sh --max 50
   ```
2. Prüfen, ob Backlog wächst oder stabil bleibt (2-3 Stichproben im Abstand 2-5 min).
3. Betroffene Message-Typen aus der Failed-List erfassen.

### 2) Root Cause
1. App-Logs und Worker-Logs auf Exceptions korrelieren.
2. Häufige Kategorien markieren:
   - temporär (Netzwerk, Timeout, externes API-Limit)
   - permanent/fachlich (Validation, nicht existente Entität)
   - deploy-/schemabedingt (inkompatible Nachricht/Code-Version)
3. Fix-Entscheidung treffen:
   - zuerst Hotfix/Config-Fix deployen,
   - danach Reprocessing starten.

### 3) Reprocess (kontrolliert)
1. Einzelne Nachricht testen:
   ```bash
   ./scripts/messenger-reprocess-failed.sh --id <failed-id>
   ```
2. Wenn erfolgreich, Bulk-Retry:
   ```bash
   ./scripts/messenger-reprocess-failed.sh --all
   ```
3. Falls Nachrichten fachlich ungültig sind, statt Retry entfernen (nur nach RCA/Freigabe):
   ```bash
   ./scripts/messenger-reprocess-failed.sh --remove --id <failed-id>
   ./scripts/messenger-reprocess-failed.sh --remove --all
   ```
4. Nachlaufkontrolle:
   ```bash
   ./scripts/messenger-inspect-failed.sh --max 20
   ```

### 4) Prevent Recurrence
- Defekte Message-Handler mit zusätzlicher Validierung/Idempotenz absichern.
- Alarmgrenzen bei Bedarf nachstellen (vermeide Alert-Fatigue).
- RCA mit Action-Items dokumentieren (Tests, Circuit-Breaker, Retry-Tuning).
- Regressionsschutz: betroffenen Handler-Pfad über Unit/Integration-Test absichern.

## Operative Hinweise
- Reprocessing nur, wenn Ursache behoben oder als transient eingestuft ist.
- Große DLQ-Batches in Wartungsfenster verarbeiten, um Lastspitzen zu begrenzen.
- Bei wiederkehrendem Fehler nach Retry sofort stoppen, RCA vertiefen und kein blindes Retry-Loop.
