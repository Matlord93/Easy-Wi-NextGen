# Webspace Lifecycle Runbook (TASK-015)

## Apply failure handling
1. Inspect job output `error_code` / `error_message`.
2. For `configtest_failed`: run `nginx -t` on node and inspect generated managed vhost.
3. For `reload_failed`: check `systemctl status nginx` and recent journal.
4. Confirm rollback restored previous vhost content.

## File API incident triage
- `path_invalid`: verify requested path stays inside webspace root and does not traverse symlink outside.
- `acl_denied`: check caller payload policy (`acl=ro` denies write/delete/mkdir).
- `size_limit_exceeded`: tune request (`max_bytes`/`max_entries`) or split operation.
- `operation_timeout`: reduce scope; retry with bounded timeout.
- `webspace_action_in_progress`: wait for current file lock holder and retry.

## SSL flow operations
- Issue: `domain.ssl.issue` (new cert).
- Renew: `domain.ssl.renew` (single cert or all certs if no domain).
- Revoke: `domain.ssl.revoke` (`cert_path` or `domain`).
- Post-action validation: `nginx -t && systemctl reload nginx`.
