# Webspace Lifecycle Contract (TASK-015)

## Lifecycle operations
- `webspace.create`: creates filesystem layout + php-fpm/nginx include.
- `webspace.update`: updates metadata/runtime settings.
- `webspace.domain.apply`: renders and writes managed vhost, validates config, reloads webserver.
- `webspace.apply`: applies pending runtime config (`nginx -t` + reload).
- `webspace.delete`: removes webspace files + managed runtime artifacts.

## File API contract
Jobs:
- `webspace.files.list`
- `webspace.files.read`
- `webspace.files.write`
- `webspace.files.delete`
- `webspace.files.mkdir`

Envelope (errors):
```json
{
  "status": "failed",
  "output": {
    "error_code": "path_invalid|acl_denied|size_limit_exceeded|operation_timeout|fs_*|webspace_action_in_progress",
    "error_message": "human readable"
  }
}
```

## Security guardrails
- Path traversal blocked (`..`, absolute escape).
- Symlink escape blocked by evaluated-root + parent symlink validation.
- ACL gate via payload policy (`acl=ro|rw`).
- Write/read size limits: `max_bytes` (default 1MiB, hard cap 5MiB).
- Listing size limits: `max_entries` (default 1000).
- Operation timeout policy: `timeout_ms` (default 2000ms, cap 10000ms).
- Per-webspace locking (`webspace_id`) to prevent concurrent conflicting writes.

## VHost apply contract
- Managed template rendering with strict domain/directive validation.
- Atomic vhost writes via staging+rename.
- Rollback on config test / reload failure restores previous vhost snapshot.
- Apply errors are normalized:
  - `configtest_failed`
  - `reload_failed`
  - `write_failed`
  - `invalid_domain_name`
  - `forbidden_directive`
  - `path_outside_webspace_root`

## SSL orchestration flow
Supported jobs:
- `domain.ssl.issue`
- `domain.ssl.renew`
- `domain.ssl.revoke`

Flow:
1. Build domain set / cert path context.
2. Execute certbot action.
3. Reload nginx.
4. Emit result output with `action` + certificate metadata.
