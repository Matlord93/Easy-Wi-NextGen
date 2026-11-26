# Agent Updates

## Source of truth
- **IST**: `lastHeartbeatVersion` from agent heartbeat.
- **SOLL**: panel resolves the target version from the selected release channel (`stable`, `beta`, `dev`).
- The agent channel set under **Updates** applies to the update overview, individual nodes and rollouts. `APP_AGENT_RELEASE_CHANNEL` supplies the default (`stable`) until a channel is saved in the panel.

## GitHub channel metadata
The panel keeps Stable, Beta and Dev strictly separated. Prefer an explicit marker in the GitHub Release notes:

```text
easywi-channel: stable
```

Allowed values are `stable`, `beta` and `dev` (`alpha` is accepted as legacy alias for `dev`). A recognized version suffix takes precedence over the marker and the GitHub pre-release flag:

- `stable`: A stable tag on a release with the GitHub pre-release flag disabled.
- `beta`: A tag with `beta`, `preview` or `rc`. Unclassified pre-releases also fall back to beta.
- `dev`: A tag with `predev`, `dev`, `alpha`, `snapshot` or `nightly`.

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
- `checksums-agent-linux.txt` / `checksums-agent-windows.txt` or the combined `checksums-agent.txt`. Older agent-only releases can use `checksums.sha256`.
- A matching OpenPGP signature using the same filename with `.asc` appended, when release signing is configured.

## Feed behavior
- GitHub Releases are resolved via `APP_AGENT_RELEASE_REPOSITORY`.
- Core cache TTL: `APP_AGENT_RELEASE_CACHE_TTL` (default 300s).
- The version comparison recognizes legacy `predev` builds as Dev and orders dated builds by their build date and sequence.

## Update flow
1. Admin selects Stable, Beta or Dev in the panel.
2. Panel resolves the newest release in that exact channel and picks the node OS/arch artifact.
3. Panel creates async `agent.update` / `agent.self_update` job with `version`, `channel`, asset URL and checksums URL.
4. Agent downloads the archive asset, verifies checksum/signature, extracts the contained `easywi-agent` / `easywi-agent.exe`, swaps the running binary, and restarts.
5. The node details show the last update job and any failure message. After the agent reports that the update was prepared, the next heartbeat must report the target version to confirm installation.

## Linux executable permissions

Gameserver units execute the agent binary in wrapper mode under their own users. The standard installation uses mode `0755` on `/usr/local/bin/easywi-agent`. Updates set this mode before replacing the file and retain its existing owner and group. The agent service also checks the mode at startup for standard installations. Its `UMask=0027` still applies to other files.

If an older update left the binary at `0750`, gameserver starts can fail with `203/EXEC` and `Permission denied`. Restore the executable mode on the affected agent host and retry the gameserver start.

```sh
sudo chmod 0755 /usr/local/bin/easywi-agent
```
