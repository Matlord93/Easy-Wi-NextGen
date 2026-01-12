# Agent Bootstrap Flow (Installer → Core → Agent)

## Overview
- Admin creates a one-time bootstrap token in the Core UI.
- Installer calls `POST /api/v1/agent/bootstrap` with node metadata.
- Core returns a short-lived `register_token` for `POST /api/v1/agent/register`.
- Installer registers the agent, writes `/etc/easywi/agent.conf`, then starts the agent.

## Manual Test Steps
1. In the Core UI, navigate to **Infrastructure → Bootstrap tokens** and create a token.
2. Copy the token immediately (it is shown once).
3. On a target node, run the installer with the token:
   ```bash
   EASYWI_CORE_URL=https://<core-host> EASYWI_BOOTSTRAP_TOKEN=<token> ./easywi-installer-linux.sh
   ```
4. Verify Core audit logs contain `agent.bootstrap_token_created`, `agent.bootstrap_used`, and `agent.bootstrap_registration_token_used`.
5. Confirm the agent appears under **Nodes** and starts heartbeating.

## Dry-run Preview
Use `--dry-run` to print planned API calls without making changes:
```bash
./easywi-installer-linux.sh --core-url https://<core-host> --bootstrap-token <token> --dry-run
```
