# Shared deployment assets

- `env/`: environment variable files per environment.
- `nginx/`: minimal endpoint configs for core/agent/proxy health checks.
- `prometheus/`: metrics stack stub configuration.

## Secrets handling

No runtime secret is hardcoded in Compose services. Values are referenced via environment variables loaded from env files (or CI/CD secret injection).

For stage/prod, replace placeholder values in `env/stage.env` through your secret manager and do not store real values in Git.

## WebDAV TLS policy

WebDAV/Nextcloud backup targets support `verify_tls=false` for legacy/self-signed environments, but this disables certificate validation and should only be used for controlled internal endpoints.

- Prefer `verify_tls=true` in all stage/prod environments.
- If `verify_tls=false` is required temporarily, document owner, reason, and expiry date in your deployment runbook.

## Production startup guardrails

In `stage`/`prod`, application startup now rejects obvious placeholder secrets (for example `change_this_secret` and `replace-with-*` patterns) for critical keys such as `APP_SECRET` and `AGENT_REGISTRATION_TOKEN`.
