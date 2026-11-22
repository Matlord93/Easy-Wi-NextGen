# Remote backup storage and retention

Backup plans can enable `options.retention_enabled: true` for local, WebDAV,
Nextcloud, S3, SFTP/SSH, SMB and mounted NFS storage. The existing encrypted archive
inventory is required (`Version20261117210000`). Preview uses
`app:backup-plans:prune <plan>`; applying retention adds `--apply`. The scheduler
processes explicitly enabled plans. Retention works on recorded completed archives;
it does not enumerate a remote directory and infer ownership from filenames.

Before deleting an eligible archive, the runner verifies its complete SHA-256 and
size against the completed backup, its configured storage/account identity, sole
inventory reference, unchanged plan/policy, and ownership of a live plan lease.
Verification streams data without buffering the complete archive in PHP memory.
Upload progress renews the active worker leases. A failed verification or deletion
keeps the inventory record. An actual deletion is reported even if later database
bookkeeping fails.

## Target configuration

| Type | Config | Secrets | Required backend behavior |
| --- | --- | --- | --- |
| `webdav` | HTTPS `url`; optional `remote_path` / `root_path`, `username` | `username` + `password`, or `token` | Strong ETag, GET, conditional DELETE with `If-Match` |
| `nextcloud` | Same; `username` identifies DAV home when `url` is an installation root | App password or token | Nextcloud DAV; same conditional deletion requirements |
| `s3` | `bucket`; optional HTTPS `endpoint` including an API base path/port, `region` (default `us-east-1`), `prefix` | `access_key`, `secret_key`; optional `session_token` | AWS Signature V4, strong ETag and conditional DELETE with `If-Match` |
| `sftp` / `ssh` | `host`, optional `port` (22), absolute dedicated `path` / `base_path`, trusted OpenSSH SHA256 `host_key_fingerprint`, optional `username` | `username`, `password` or `private_key_path`; optional `private_key_passphrase` | SFTP, regular files and rename without replacement; no remote shell |
| `smb` | `host`, `share`; optional `path`, `port` (445), `username` | `username`, `password` | Installed `smbclient`, SMB signing, read/rename/delete permissions |
| `nfs` | Absolute existing `path` / `base_path` on an NFS/NFS4 mount | None | Mounted export, same-directory rename and regular-file access |

WebDAV and Nextcloud endpoints use the existing public-address URL policy. S3
endpoints are selected through administrator-managed backup plans and can use
internal MinIO hostnames, private IPv4/IPv6 addresses and arbitrary configured
ports. The S3 validator and writer share strict HTTPS URL validation: userinfo,
query strings, fragments, control characters, dot path segments and encoded path
separators are rejected. The configured host, port and API base path are retained.
TLS verification stays configurable for existing WebDAV targets; S3 verifies TLS.
Redirects are disabled,
so credentials and conditional deletion cannot move to a different endpoint.
S3 signs the complete canonical request path, including custom endpoint base path,
bucket, encoded object key and explicit Host port. Uploads stream from the archive.

SFTP verifies the pinned host key before sending credentials. Private key files
must resolve under `/etc/easywi`; escaping symlinks and other roots are rejected.
SSH targets use SFTP, not shell commands. SFTP and SMB publish a complete private
staging archive by rename and refuse to overwrite an existing archive.
NFS validates the most specific mount entry and binds inventory to its server/export
and filesystem identity. An unavailable mount table, unmounted directory, ordinary
local filesystem, or changed export fails closed. NFS publication uses a private
same-directory staging file and atomic rename.

## Deletion and changes to a target

WebDAV, Nextcloud and S3 delete with the exact strong ETag obtained while streaming
the archive. Unsupported preconditions, changed ETags, permission errors and missing
objects fail the operation and preserve history.

SFTP, SMB and NFS isolate the original archive under a private random
`.easywi-retention-*` name, verify its contents again, then delete that isolated
file. These protocols do not offer the HTTP equivalent of an atomic `If-Match`
delete. Use a dedicated backup account/directory without concurrent external
writers. A changed or unreadable isolated archive is restored without replacing a
new file at the original name. If restoration cannot complete, the error identifies
the retained quarantine for operator recovery; it is not silently deleted.

Passwords and SFTP private keys can rotate without changing archive ownership.
Changing endpoint, root, username, SSH host fingerprint or NFS export invalidates
the old binding. S3 access keys and bearer-only tokens identify otherwise opaque
accounts, so changing those also blocks historical retention. After such an account
change, old archives require deliberate operator reconciliation; do not rewrite
inventory identities to bypass ownership checks.

Inventory version 1 remains readable. Existing local records remain usable.
Historical remote records without the new target binding are retained safely and
are not adopted automatically. Old shared references, untracked files and changed
archives are retained.

## Validation performed

Automated tests cover changed content/size, weak or missing ETags, HTTP 412,
retargeting/account changes, missing historical identity, shared references, expired
leases, plan changes during verification, completed deletion followed by inventory
failure, SFTP callback transfers, quarantine restoration, host-key checks, SMB
argument separation/signing/existing directories, S3 canonical signatures, and NFS
refusal to write to an unmounted directory.

The implementation was also exercised against an isolated OpenSSH server, Samba,
and an HTTPS WebDAV/Nextcloud/S3 fixture that independently validates Signature V4.
Real transfers and changed-object preconditions passed under PHP 8.4 and 8.5.
A real mounted NFS export was not available in this execution environment; NFS
mount rejection is verified, but an actual export must be checked on the deployment
host before enabling scheduled NFS pruning.
