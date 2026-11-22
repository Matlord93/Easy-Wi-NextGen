# Persistent generic backup plans

The generic backup dispatcher uses encrypted database plans and shared expiring worker leases. This is separate from the existing module-specific `BackupDefinition` administration and does not replace those workflows. An installed adapter must support the requested operation; generic Mail, Voice, Game and Database operations require an actual configured provider. An unavailable provider fails explicitly. The `musicbot` adapter keeps configuration-manifest recovery; administrator plans can use `musicbot_full` for authenticated metadata, credentials and media recovery. See [module-backup-providers.md](module-backup-providers.md) and [musicbot-full-recovery.md](musicbot-full-recovery.md) for the registered providers and setup.

Apply the normal reviewed Doctrine migrations, including `Version20261117200000`, before using this workflow. The migration creates `backup_plans`, `backup_plan_runs` and `backup_plan_locks`; it does not import plans or contact agents. No production migration is run automatically by these commands.

Also apply `Version20261117210000` before creating new generic backups. It adds the encrypted `backup_archive_inventory`, independently of expiring idempotency markers. Missing inventory schema fails before an archive is created or uploaded. Existing archives are not scanned or automatically imported into this inventory.

Use the same strong, stable `APP_SECRET` on every web/worker/scheduler node. Complete plan configuration, target credentials, archive encryption keys and run details are encrypted with the existing authenticated `SecretsCrypto` format. For key rotation, set the new `APP_SECRET` and retain previous secrets in `APP_SECRET_FALLBACKS` until old records have been rewritten or expired. Losing all applicable keys makes those records unreadable. Archive encryption is a separate key in the plan; retain that key for the lifetime of the archives. The legacy archive AES-CBC format does not authenticate archive contents.

Create a local JSON file readable only by the operating account (`chmod 600`), for example:

```json
{
  "version": 1,
  "id": "website-nightly",
  "module": "web",
  "resource_id": "/srv/example-site",
  "target": {
    "type": "local",
    "label": "Local backup storage",
    "config": {"base_path": "/srv/backups"},
    "secrets": {}
  },
  "retention": {"keep_count": 7, "keep_days": 30},
  "cron": "0 3 * * *",
  "timezone": "Europe/Berlin",
  "options": {
    "compression": "gzip",
    "encrypt": false,
    "lock_target": true,
    "retention_enabled": false,
    "lock_ttl_seconds": 7200,
    "idempotency_ttl_seconds": 172800
  }
}
```

`resource_id` follows the selected adapter's contract; it is a source path for the Web adapter and an instance ID for Musicbot. Use `encrypt: true` and a nonempty `encryption_key` to encrypt archives. Keep secret-bearing import files protected or remove them securely after import. They are never echoed by the commands.

```console
php bin/console app:backup-plans:import /secure/website-nightly.json
php bin/console app:backup-plans:list
php bin/console backup:dispatch-schedules
php bin/console app:backup-plans:restore website-nightly /secure/website-nightly-run.tar.gz
php bin/console app:backup-plans:restore website-nightly /secure/website-nightly-run.tar.gz --apply
php bin/console app:backup-plans:delete website-nightly
```

Import validates the version, supported adapter/target, cron, timezone, retention and known options before atomically writing one plan. Reimporting its ID updates the plan. Listing exposes only IDs, modules and schedules. Deleting a plan stops future scheduled lookup but does not cancel a running backup or remove existing archives or their provenance. Configure the existing schedule dispatcher and Messenger transport/worker according to your deployment.

Restore takes an existing plan ID and a local archive. By default it only validates the operation; `--apply` writes to the resource configured in that plan. The plan supplies the encryption key and module adapter. Download remote archives to a protected local path first. Restore shares the plan's worker lease with scheduled backups and fails while that plan is busy; built-in transfers and archive processing renew the owning lease. The Agent also serializes managed operations sharing resources, paths, databases or service units. Independent Web plans do not share a resource lock: check their targets and quiesce writers before applying a restore. Invalid archives or unavailable module providers return a failing command exit status.

Completed generic backups now record their path, checksum, size, completion time and original local target root in encrypted inventory records. New archive names use the module's snapshot name, plan ID and a hash of the plan/run identity; this avoids ambiguous names such as plan `a-b` / run `c` versus plan `a` / run `b-c`. Existing archives remain restorable using their stored or local path. Local publication uses a private staging file and an atomic rename, so a failed copy cannot replace the previous complete archive and destination symlinks are replaced rather than followed. Published local files are readable only by their owning service account (`0600`).

Automatic archive retention is **off by default**. For a dedicated local target, explicitly set `retention_enabled: true` in a plan and import it. This activates that plan in the existing central `cleanup.backups` schedule, which runs at minute 30 of each hour. No additional retention daemon or cron entry is required. WebDAV/Nextcloud, S3, SFTP/SSH, SMB and mounted NFS targets also support explicit opt-in; see [remote-backup-retention.md](remote-backup-retention.md) for account binding, backend requirements and safe deletion. A count or day limit of zero disables that individual limit; an archive is selected when it exceeds either enabled limit. Age uses the plan's timezone.

```console
php bin/console app:backup-plans:prune website-nightly
php bin/console app:backup-plans:prune website-nightly --apply
```

The first command validates and lists candidates without deleting files. The second applies the enabled plan's policy immediately. Cleanup shares the plan lease with backup/restore and renews its lease before and after archive verification. For local storage it deletes only recorded, unchanged, regular files directly inside the current and originally recorded target directory. Remote providers additionally bind the original account/root and use conditional deletion or a verified quarantine. Untracked files, symlinks, changed contents, previous targets and paths referenced by another run are preserved. Missing or blocked files retain their inventory records and require operator attention; they are not silently counted as deleted. Encryption-key errors or malformed inventory stop that plan. Check blocked reports and logs rather than manually deleting database rows. Existing archives absent from the inventory are never automatically removed.

Built-in archive/checksum processing and transfer progress renew the plan and optional target leases; lost ownership stops completion bookkeeping. Choose `lock_ttl_seconds` to cover the longest blocking section. Custom adapters/writers must pulse the shared `BackupOperationHeartbeat` while running; a custom blocking call with no progress callback can still outlive its lease. A stale worker cannot release its successor's lease. Idempotency markers become visible only after a successful completed run and expire according to their configured TTL. State cleanup removes expired markers and leases while retaining plans. Concurrent writes of the same plan ID use last-writer-wins behavior.

The existing dispatcher uses the previous cron window as its idempotency key, so a newly imported plan can catch up that window on the next dispatch. Size the idempotency TTL to cover the intended retry/repeated-dispatch period. Verify a sample backup and restore against non-production data, including remote target permissions, before enabling production scheduling.

The streaming outer bundle/envelope paths support large managed-module payloads without buffering whole archives. The existing Web restore reader still limits individual source files to below 4 GiB; Web backup rejects larger members before publishing an unrestorable archive. This does not limit the total size of a managed-module payload.
