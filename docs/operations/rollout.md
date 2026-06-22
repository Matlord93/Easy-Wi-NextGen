# Rollout Runbook (dev/stage)

## Ziel
Standardisierter Rollout mit Docker Compose, reproduzierbar für dev/stage.

## Preflight
1. Compose-Datei validieren:
   ```bash
   docker compose -f deploy/compose/<env>/docker-compose.yml config
   ```
2. Env/Secrets prüfen (`DB_PASSWORD`, `QUEUE_PASSWORD`).
3. Optionalen Smoke ausführen:
   ```bash
   SMOKE_DEPLOY=1 DEPLOY_ENV=<env> ./scripts/smoke.sh
   ```

## Rollout-Schritte
1. Pull der letzten Änderungen.
2. Start/Update:
   ```bash
   docker compose -f deploy/compose/<env>/docker-compose.yml up -d
   ```
3. Status prüfen:
   ```bash
   docker compose -f deploy/compose/<env>/docker-compose.yml ps
   ```
4. Health-Endpunkte prüfen:
   - core `/healthz`
   - agent `/healthz`

## Post-Rollout Checks
- Logs prüfen:
  ```bash
  docker compose -f deploy/compose/<env>/docker-compose.yml logs --tail=100 core agent db queue
  ```
- Smoke erfolgreich oder nur bekannte Warnings.
## Verwandte Runbooks
- Queue/DLQ Betrieb: [Queue Operations Runbook](./queues.md)

