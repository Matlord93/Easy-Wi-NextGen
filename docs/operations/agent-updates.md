# Agent Updates

## Source of truth
- **IST**: `lastHeartbeatVersion` from agent heartbeat.
- **SOLL**: panel resolves latest version from update feed (`agent.latest[channel]`) or semver max in `agent.releases`.
- Default channel: `stable` (`APP_AGENT_RELEASE_CHANNEL`).

## Feed behavior
- Feed URL via `APP_AGENT_UPDATE_FEED_URL`.
- Core cache TTL: `APP_AGENT_RELEASE_CACHE_TTL` (default 300s).
- Core uses ETag/If-None-Match; on `304` it reuses cached payload.
- Semver comparison uses `version_compare`, never string sort.

## Update flow
1. Panel resolves artifact by node OS/arch.
2. Panel creates async `agent.update` / `agent.self_update` job.
3. Agent downloads binary, verifies checksum/signature, swaps binary, restarts.
4. Core verifies IST version via next heartbeat.
