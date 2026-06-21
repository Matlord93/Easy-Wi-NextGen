# Panel-managed Internal Scheduler

Easy-Wi NextGen uses the **panel-managed internal scheduler** for productive
automation. The panel triggers the scheduler after normal web requests through
`RequestDrivenScheduleTriggerSubscriber`; it starts one guarded
`app:run-schedules --once` process at most once per minute. This keeps
Gameserver backups, restarts, cleanup and update checks inside the panel system
instead of requiring customers to configure a separate cron job per feature.

The central command still exists as the internal execution entry point:

```bash
php /path/to/core/bin/console app:run-schedules --once --no-interaction --env=prod
```

Feature-specific commands such as `app:gameserver:run-backup-schedules` and
`app:gameserver:run-instance-schedules` are debug/development helpers only. They
must not be required as separate production cronjobs. The central
`app:run-schedules` command invokes the internal scheduler registry and then
continues to cover legacy schedule paths.

## Productive operation

For normal panel installations no manual backup cron entry is required. The
panel request lifecycle starts the internal scheduler opportunistically and uses
the shared process lock `easywi-run-schedules.lock` so concurrent HTTP requests
cannot start duplicate scheduler passes.

A systemd timer or crontab entry may be used only as an optional watchdog for
very low-traffic installations where no panel HTTP request may happen for long
periods. If such a watchdog is used, run only the central command. Do not add
one timer per module.

Example optional systemd unit:

```ini
# /etc/systemd/system/easywi-scheduler.service
[Unit]
Description=Easy-Wi central scheduler watchdog

[Service]
Type=oneshot
WorkingDirectory=/opt/easywi/core
ExecStart=/usr/bin/php bin/console app:run-schedules --once --no-interaction --env=prod
User=www-data
Group=www-data
```

Example optional systemd timer:

```ini
# /etc/systemd/system/easywi-scheduler.timer
[Unit]
Description=Run Easy-Wi central scheduler watchdog every minute

[Timer]
OnBootSec=1min
OnUnitActiveSec=1min
AccuracySec=10s
Unit=easywi-scheduler.service

[Install]
WantedBy=timers.target
```

A single optional cron watchdog is also acceptable:

```cron
* * * * * www-data cd /opt/easywi/core && php bin/console app:run-schedules --once --no-interaction --env=prod
```

## Admin UI

Admins can inspect and control schedules in the panel at:

```text
/admin/schedules
/admin/cronjobs
```

The page aggregates existing schedule sources such as `BackupSchedule` and
`InstanceSchedule` and shows name, module, type, enabled flag, cron expression,
next/last run, last status/error, job information and recent history. The
"Jetzt ausführen" action runs a schedule immediately through the same central
handler registry.

## Schedule history

Central scheduler executions are recorded in `scheduled_task_runs` via
`ScheduledTaskRun`. Individual gameserver backup/restart runners also record
per-schedule history so skipped, blocked, queued and failed decisions are visible
from the panel.

## Adding a scheduler handler

Implement:

```php
App\Module\Core\Application\Scheduler\ScheduleHandlerInterface
```

`ScheduleHandlerRegistry` receives all handlers through a tagged iterator.
Service autoconfiguration tags implementations with `app.schedule_handler`.

Initial handler types:

- `webinterface.auto_update`
- `privacy.gdpr_background`
- `gameserver.auto_backup`
- `gameserver.auto_restart`
- `gameserver.watchdog`
- `cleanup.jobs`
- `cleanup.backups`

Gameserver auto-backup still uses `BackupSchedule` and queues
`instance.backup.create`. Gameserver auto-restart still uses `InstanceSchedule`
and queues `instance.restart` with `reason=scheduled_restart` and `schedule_id`.

## Gameserver backups

Gameserver auto-backups are queued by the panel-managed central scheduler through
the `gameserver.auto_backup` handler. Production installations should enable and
monitor schedules through `/admin/schedules`; no feature-specific cron is needed.

For troubleshooting a single backup-schedule pass can be started with the helper
command:

```bash
php bin/console app:gameserver:run-backup-schedules --env=prod
```

Backup targets are resolved immediately before an agent receives the job. Local
backups stay on the gameserver node under the configured local target directory
(or `EASYWI_INSTANCE_BACKUP_DIR`). WebDAV and Nextcloud backups are uploaded by
the gameserver agent itself because the created archive exists on the agent host,
not necessarily on the core host. Nextcloud targets use WebDAV at
`/remote.php/dav/files/{username}/{remotePath}/{filename}` and support app
passwords via HTTP Basic Auth.
