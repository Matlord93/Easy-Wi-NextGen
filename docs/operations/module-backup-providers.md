# Complete managed module backups

The generic backup dispatcher now has completed-operation providers for `game`,
`db`, `voice` and `mail`. Existing module-specific Agent job and TeamSpeak
ServerQuery snapshot operations remain available. Full voice backups use
`ts3:<instance-id>` or `ts6:<instance-id>`; a virtual-server query snapshot is a
separate operation and does not include uploaded files or the complete instance
SQL database.

Apply migration `Version20261118010000` through the normal deployment migration
process. Resource IDs are existing persisted module entity IDs. Credentials are
resolved from encrypted database/node records. MySQL/MariaDB database backups
use the managed database node Agent and actual dump/client processes. An
unmanaged database or missing credentials causes a failed operation.

## Administrator setup

An administrator with local console access configures resource bindings. This
command has no customer web endpoint. The JSON must be a regular private file
(`chmod 600`). It is encrypted with `APP_SECRET` in `backup_module_bindings`.
The command validates the resource, ownership, Agent identity, path and service
settings transactionally. It performs no remote operations.

For a game instance:

```json
{
  "agent_id": "management-agent-id",
  "path": "/home/easywi/instances/42",
  "service": "easywi-game-42.service"
}
```

```sh
php bin/console app:backup-modules:bind game 42 /etc/easywi/backup-game-42.json
```

For voice use the matching TeamSpeak install directory and its actual service:

```sh
php bin/console app:backup-modules:bind voice ts3:12 /etc/easywi/backup-ts3-12.json
```

An explicit service (or nonempty `services` list) is required for file modules to
quiesce writes. The Agent stops the configured services around backup/restore
and restarts services it stopped even when an operation fails. TS3 with external
MySQL/MariaDB includes both the instance files and the SQL dump in this window;
TS3 SQLite and TS6 include their embedded databases in their install files.

Mail requires an explicit binding because an SMTP or IMAP hostname does not
identify a management Agent. For one managed mail domain:

```json
{
  "agent_id": "mail-management-agent",
  "path": "/var/vmail/example.org",
  "services": ["postfix.service", "dovecot.service", "opendkim.service"],
  "additional_paths": {
    "config": "/etc/easywi/mail/example.org",
    "dkim": "/etc/opendkim/keys/example.org"
  }
}
```

```sh
php bin/console app:backup-modules:bind mail 17 /etc/easywi/backup-mail-17.json
```

`path` contains this domain's messages. `config` must contain all of this
domain's actual routing and mailbox configuration; `dkim` contains its actual
private signing keys. Bind dedicated domain directories, not shared global
configuration that would overwrite other tenants. Additional named roots may
be supplied for other domain-specific backend files. Restoring the actual
files and restarting the configured services applies the saved configuration.
The bundle also contains authenticated and encrypted SQL metadata for the
mail domain, mailboxes/password hashes, mail users, aliases, forwarding rules,
DKIM records, policy and mailbox rate limits. Metadata is restored inside a
transaction; an unsuccessful Agent result rolls it back. External filesystem
and database operations cannot form a distributed transaction: infrastructure
failure after the Agent finishes but before the control-plane commit requires
operator recovery. Do not treat a failed restore report as completed.

The Agent independently restricts every path to its server-configured module
roots (`EASYWI_MODULE_BACKUP_ROOTS`, JSON mapping module names to allowed root
arrays). Add `/etc/easywi/mail` and `/etc/opendkim/keys` for the mail example;
allow only the directories managed on that node. Configured Agent addresses
may be internal or external and may use any configured port.

## Archive verification and recovery

The outer generic archive contains only `resource.json` and `payload.tar.gz`.
The manifest is authenticated and encrypted with `APP_SECRET`, including a
SHA-256 digest of the streamed Agent payload. It binds module, resource,
customer, management Agent and destination configuration. Restore rejects a
cross-customer archive, changed destination binding, modified payload, links,
extra members or malformed metadata before performing remote writes. Preserve
`APP_SECRET`/fallback keys and the data encryption keyring with the installation
recovery material. Password rotation does not change the resource identity;
Agent/path changes require deliberate migration rather than replaying an old
archive onto an unrelated destination.

Dry-run validates the complete outer archive, metadata and Agent destination
without applying it. Successful reports represent actual completed operations;
missing bindings, services, binaries, credentials or module roots fail clearly.
Use isolated dry-runs and a recovery rehearsal with representative data before
scheduling on a managed installation.
