# Agent Releasing

## Trigger
- Push tag `agent-vX.Y.Z` or run workflow dispatch.

## Workflow outputs
- Targets: `linux-amd64`, `linux-arm64`, `windows-amd64`.
- Per target archives: `.zip` and/or `.tar.gz`.
- `checksums.sha256` (+ optional `checksums.sha256.asc`).
- `manifest.json` with version/commit/build date/artifact list.

## Build metadata
Agent binary is built with ldflags:
- `main.version`
- `main.commit`
- `main.date`

`easywi-agent --version` prints all three values.
