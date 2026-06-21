# Agent Updates

## Source of truth
- **IST**: `lastHeartbeatVersion` from agent heartbeat.
- **SOLL**: panel resolves the target version from the selected release channel (`stable`, `beta`, `dev`).
- Default channel: `stable` (`APP_AGENT_RELEASE_CHANNEL`).

## GitHub channel metadata
The panel keeps Stable, Beta and Dev strictly separated. Prefer an explicit marker in the GitHub Release notes:

```text
easywi-channel: stable
```

Allowed values are `stable`, `beta` and `dev` (`alpha` is accepted as legacy alias for `dev`). If the marker is missing, the panel falls back to GitHub metadata:

- `stable`: GitHub release is **not** marked as pre-release.
- `beta`: GitHub pre-release tag contains `beta`, `preview` or `rc`; unclassified pre-releases also fall back to beta.
- `dev`: GitHub pre-release tag contains `dev`, `alpha`, `snapshot` or `nightly`.

Recommended agent tags:

- Stable: `agent-vX.Y.Z` or `vX.Y.Z`, GitHub pre-release disabled, `easywi-channel: stable`.
- Beta: `agent-vX.Y.Z-beta.N`, GitHub pre-release enabled, `easywi-channel: beta`.
- Dev: `agent-vX.Y.Z-dev.N`, GitHub pre-release enabled, `easywi-channel: dev`.

## Required assets
Each agent release must include the platform asset and checksum/signature files expected by the panel:

- `easywi-agent-linux-amd64.tar.gz`
- `easywi-agent-linux-amd64.zip`
- `easywi-agent-linux-arm64.tar.gz`
- `easywi-agent-linux-arm64.zip`
- `easywi-agent-windows-amd64.zip`
- `checksums-agent.txt`
- `checksums-agent.txt.asc` (recommended; the agent update path expects a signature URL when present)

## Feed behavior
- GitHub Releases are resolved via `APP_AGENT_RELEASE_REPOSITORY`.
- Core cache TTL: `APP_AGENT_RELEASE_CACHE_TTL` (default 300s).
- Semver comparison uses `version_compare`, never string sort.

## Update flow
1. Admin selects Stable, Beta or Dev in the panel.
2. Panel resolves the newest release in that exact channel and picks the node OS/arch artifact.
3. Panel creates async `agent.update` / `agent.self_update` job with `version`, `channel`, asset URL and checksums URL.
4. Agent downloads the archive asset, verifies checksum/signature, extracts the contained `easywi-agent` / `easywi-agent.exe`, swaps the running binary, and restarts.
5. Core verifies IST version via next heartbeat.
