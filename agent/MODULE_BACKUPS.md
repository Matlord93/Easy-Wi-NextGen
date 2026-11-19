# Synchronous module backups

The `/v1/module-backups/snapshot` and `/v1/module-backups/restore` endpoints
complete the requested operation before returning success. They use the file API
HMAC headers, authenticate metadata before reading an upload, and verify the
entire uploaded SHA-256 before opening resource files or stopping services.
Large uploads stream through a private staging directory instead of PHP/Go
memory. Configure enough free space for the compressed upload, expanded staged
roots, the live resource and its recovery copy.

## Agent setup

`EASYWI_MODULE_BACKUP_ROOTS` is a JSON map of module names to absolute permitted
filesystem roots. When unset, game/voice/musicbot roots are limited to `/home`,
`/opt/easywi`, and `/var/lib/easywi`; mail roots to `/var/vmail`,
`/opt/easywi`, and `/var/lib/easywi`. A configured JSON map replaces the defaults.
Database descriptors do not use filesystem allowroots.

For example, an administrator can explicitly allow mail configuration and DKIM:

```sh
EASYWI_MODULE_BACKUP_ROOTS='{"game":["/home"],"voice":["/home"],"musicbot":["/opt/easywi/musicbots"],"mail":["/var/vmail","/etc/postfix/main.cf","/etc/dovecot","/etc/opendkim/keys"]}'
```

Only add paths that belong to the resources being backed up. Do not allow `/`,
the whole `/etc` directory, or agent credentials. Sources/destinations and their
ancestors must not be symbolic links. Multiple roots must not overlap. Source
root parents must already exist. Additional named roots support either a whole
directory or a regular file. The archive never chooses its destination paths:
the current authenticated descriptor does.

- `EASYWI_MODULE_BACKUP_MAX_BYTES`: positive byte count, default 100 GiB. Both
  compressed input/output and total expanded regular-file bytes are bounded.
- `EASYWI_MODULE_BACKUP_TIMEOUT`: Go duration, default `2h`, maximum `24h`.
  Authenticated archive operations extend their HTTP connection deadlines to
  match this timeout. Service recovery has its own bounded context even if the
  original caller disconnects.
- `EASYWI_MODULE_BACKUP_RECOVERY_DIR`: private `0700` directory for pre-restore
  SQL dumps; default `/var/lib/easywi/backups/module-recovery`.

Game, voice and mail descriptors require explicit resource `service` or
`services` entries. Only originally active services are stopped and restarted;
a shared database daemon must not be configured as a resource service. Service
names are passed as separate systemctl arguments, never through a shell. The
`.service` suffix is optional and aliases are normalized/deduplicated. Operations
sharing a systemd service are serialized even when their module/customer/resource
and filesystem roots differ, so one operation cannot restart a shared mail
service while another operation is still copying its data.
Musicbot descriptors can omit a service when their application coordinates its
own stopped instance. Configure all services that can modify a resource while
it is being backed up or restored.

Database support requires `mariadb-dump` and `mariadb`, or `mysqldump` and `mysql`,
on the agent's PATH. Credentials are written only to temporary `0600` defaults
files and are not command arguments, response bodies or archive entries.
`tls_mode` is explicitly `disabled`, `required`, `verify_ca`, or
`verify_identity`. Verified modes require the node's PEM CA certificate in `ca_cert` (maximum 32 KiB). The agent validates
the PEM and stages it in a private temporary `0600` file for each client process;
certificate contents are never command arguments and the file is always removed. MariaDB uses certificate *and hostname* verification for both verified
modes; MySQL supports the two modes separately. The database account must have
permission to read all tables/routines/events and to drop/recreate its own
resource database for a complete restore. Use an account restricted to that
resource database. Native dumps contain DROP/CREATE DATABASE statements so
restores and rollback also remove tables introduced after the snapshot. Native
imports disable client shell commands, LOCAL INFILE, and automatic reconnect.

## Protocol

Snapshot accepts JSON up to 64 KiB:

```json
{"customer_id":"123","descriptor":{"module":"voice","resource_id":"ts3:17","path":"/home/ts3-17","service":"ts3-17.service","additional_paths":{},"exclude":["logs","run"]}}
```

An optional database descriptor contains `host`, `port`, `name`, `username`,
`password`, `tls_mode`, and optionally the PEM CA certificate in `ca_cert`. It can accompany filesystem
roots, or be the only payload for module `db`.

Snapshot returns a completed gzip tar with `Content-Length`,
`X-Archive-SHA256`, `X-Response-SHA256` and `X-Operation-Signature`.
Restore receives an uncompressed tar envelope with exactly two regular files:
`descriptor.json` (the snapshot request plus `dry_run`) and `archive.tar.gz`.
Restore returns JSON containing `success`, `dry_run`, and `message`.

All successful responses are authenticated. `X-Response-SHA256` is the lowercase
SHA-256 of the response body. `X-Operation-Signature` is lowercase HMAC-SHA256
using the agent secret over the following newline-separated fields, without a
trailing newline:

```text
agent ID
signed customer ID
uppercase request method
request URI, including query
original X-Timestamp
lowercase original X-Content-SHA256
HTTP status code
response SHA-256
```

The caller must verify both response digest and signature before accepting an
archive or restore success, including when agents use HTTP on a trusted network.

## Integrity and recovery

The internal `.module-backup.json` binds version, module, resource, customer,
logical root names and database name. File payloads live under fixed `files/`
prefixes and the SQL dump at `database.sql`. Neither credentials nor original
absolute paths are stored in the manifest. Symlinks/hardlinks and special archive
entries are rejected; live sockets, FIFOs/devices, PID files and explicitly
excluded relative prefixes are omitted from snapshots. A source containing a
symlink fails explicitly rather than silently creating an incomplete backup.
Ownership and permission bits are preserved, excluding setuid/setgid/sticky
bits. Directory/file ACLs and extended attributes are not represented by this
archive format.

Restore validates the entire archive (including gzip checksum and binding) and
extracts into private stages alongside each destination before changing live
data. Dry-run performs that validation plus service/client prerequisite checks,
then removes its staging directories. It does not stop services, import SQL or
swap live roots. A real restore preserves the previous roots, swaps in staged
roots on the same filesystem, imports SQL and verifies restarted services.
Errors trigger rollback; rollback failure is explicitly reported as incomplete
recovery and retained artifacts are not deleted.

Successful restores retain previous filesystem roots in private
`.easywi-module-restore-*/previous` directories alongside the destinations and
SQL under the configured recovery directory. Operators should retain these
until application verification is complete and remove them deliberately later.
These local emergency copies are separate from scheduled remote retention.
A process/host crash during a multi-root or SQL restore can require manual
recovery from these copies: this is not a transaction across filesystems and a
SQL server.

## Validation

```sh
go test ./cmd/agent ./internal/fileapi -run 'ModuleBackup|VerifyRequestSignature' -count=1
go test -race ./cmd/agent ./internal/fileapi -run 'ModuleBackup|VerifyRequestSignature' -count=1
go vet ./cmd/agent ./internal/fileapi
```

The default tests use real HTTP, HMAC, tar/gzip, staging and filesystem swaps;
only the process boundary is replaced for service/SQL failure injection.
External-service helpers require the explicit build tag:

```sh
go test -tags modulebackup_integration ./cmd/agent -run '^TestModuleBackupNativeDatabaseRoundTrip$' -count=1 -v
go test -tags modulebackup_integration ./cmd/agent -run '^TestModuleBackupIntegrationServer$' -count=1 -v
```

When explicitly selected, missing required integration setup fails the test.
`TestModuleBackupNativeDatabaseRoundTrip` can additionally use disposable
MariaDB/MySQL clients and a server configured through
`EASYWI_MODULE_BACKUP_NATIVE_CONTAINER`, `EASYWI_MODULE_BACKUP_NATIVE_ROOT`, PATH,
and the recovery-directory variable. `TestModuleBackupIntegrationServer` starts
a temporary HTTP endpoint only when `EASYWI_MODULE_BACKUP_INTEGRATION_ADDR`,
`EASYWI_MODULE_BACKUP_INTEGRATION_READY_FILE`, and
`EASYWI_MODULE_BACKUP_INTEGRATION_ROOT` are explicitly set. Normal runs exclude
these helpers entirely; there are no no-op passes or skipped tests.
