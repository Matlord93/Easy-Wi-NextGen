# Secrets Provisioning (Operations)

This project expects runtime secrets to be injected externally and **not** committed.

## Supported provisioning patterns

1. **Vault/KMS integration (preferred)**
   - Sync secrets into runtime env (`APP_SECRET`, SMTP creds, Redis creds, tokens).
   - Use short TTL where possible and automated rotation.

2. **Docker/Compose secrets**
   - Store secret values in secret files or orchestrator-managed secret stores.
   - Inject into container env at startup (entrypoint/export step).

3. **Local `.env.local` (development only)**
   - Allowed for developer machines and ephemeral test containers.
   - Never commit `.env.local` or real credentials.

## Mandatory startup check

Core startup now validates required env keys and aborts with a clear error if any are missing.
Use this as a deployment gate in smoke checks.

## Rollback notes

If startup fails after a release due to missing secrets:
- Restore previous secret set/version in Vault/KMS (or previous Compose secret bundle).
- Re-run deployment with known-good secret revision.
- As temporary mitigation, roll back application image and secret bundle together.
