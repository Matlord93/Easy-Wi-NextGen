# Update-System (Core)

## Ablauf
1. Preflight (Disk, Write-Access, PHP, Lock, Backup-Hinweis)
2. Download des Artefakts (Retry/Resume via curl)
3. Verify (SHA256, optional Signatur)
4. Unpack in Staging (`srv/update/work/<job>/staging`)
5. Apply (Release-Verzeichnis + atomarer Symlink `current`)
6. Migration (`doctrine:migrations:migrate --no-interaction`)
7. Postchecks (cache clear/warmup + Healthcheck)
8. Cleanup (alte Releases, `KEEP_RELEASES`)
9. Rollback (Symlink auf vorheriges Release)

## Verzeichnisse
- Jobs: `srv/update/jobs`
- Logs: `srv/update/logs`
- Work/Staging: `srv/update/work`
- Releases: `srv/update/releases/<version>`
- Active symlink: `current`
- Backups: `srv/update/backups`

## Rollback-Plan
- Bei Fehler im Update wird automatisch auf vorheriges Release zurückgeschwenkt.
- DB-Rollback wird **nicht** automatisch erzwungen (nur wenn Migration safe rückwärtsfähig ist).
- Runbook: Release-Symlink prüfen, Logs prüfen, ggf. manueller Migration-Rollback.
