# Central Internal Scheduler

Easy-Wi NextGen uses **one productive scheduler entry point**:

```bash
php /path/to/core/bin/console app:run-schedules
```

Feature-specific commands such as `app:gameserver:run-backup-schedules` and
`app:gameserver:run-instance-schedules` are debug/development helpers only. They
must not be required as separate production cronjobs. The central
`app:run-schedules` command invokes the internal scheduler registry and then
continues to cover legacy schedule paths.

## Recommended production operation

Use one systemd timer (or one crontab entry) for the central command. Do not add
one timer per module.

Example systemd unit:

```ini
# /etc/systemd/system/easywi-scheduler.service
[Unit]
Description=Easy-Wi central scheduler

[Service]
Type=oneshot
WorkingDirectory=/opt/easywi/core
ExecStart=/usr/bin/php bin/console app:run-schedules --env=prod
User=www-data
Group=www-data
```

Example systemd timer:

```ini
# /etc/systemd/system/easywi-scheduler.timer
[Unit]
Description=Run Easy-Wi central scheduler every minute

[Timer]
OnBootSec=1min
OnUnitActiveSec=1min
AccuracySec=10s
Unit=easywi-scheduler.service

[Install]
WantedBy=timers.target
```

Enable it with:

```bash
systemctl daemon-reload
systemctl enable --now easywi-scheduler.timer
```

A single cron entry is also acceptable:

```cron
* * * * * www-data cd /opt/easywi/core && php bin/console app:run-schedules --env=prod
```

## Admin UI

Admins can inspect schedules at:

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
`ScheduledTaskRun` with start/end timestamps, status, message/error, created job
IDs and duration. History is visible on `/admin/schedules` and per schedule via
its "Verlauf" action.

## Registering module handlers

Modules register schedules by implementing:

```php
App\Module\Core\Application\Scheduler\ScheduleHandlerInterface
```

Autoconfiguration tags implementations with `app.schedule_handler`; the
`ScheduleHandlerRegistry` receives all handlers through a tagged iterator.
Handlers should expose schedules via `schedules()`, process due work via
`runDue()`, and implement immediate execution via `runNow()`.

Initial handler types:

- `gameserver.auto_backup`
- `gameserver.auto_restart`
- `gameserver.watchdog`
- `cleanup.jobs`
- `cleanup.backups`

Gameserver auto-backup still uses `BackupSchedule` and queues
`instance.backup.create`. Gameserver auto-restart still uses `InstanceSchedule`
and queues `instance.restart` with `reason=scheduled_restart` and `schedule_id`.
