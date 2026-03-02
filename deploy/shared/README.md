# Shared deployment assets

- `env/`: environment variable files per environment.
- `nginx/`: minimal endpoint configs for core/agent/proxy health checks.
- `prometheus/`: metrics stack stub configuration.

## Secrets handling

No runtime secret is hardcoded in Compose services. Values are referenced via environment variables loaded from env files (or CI/CD secret injection).

For stage/prod, replace placeholder values in `env/stage.env` through your secret manager and do not store real values in Git.
