# TASK-018 – Linux/Windows Agent CI Matrix + Smoke

## Highlights
- CI validates agent build + full Go tests on both `ubuntu-latest` and `windows-latest`.
- CI runs cross-platform smoke coverage for:
  - agent starts,
  - agent connects to core heartbeat endpoint,
  - agent executes one dummy job (`agent.diagnostics`) and submits result.

## Known issues by OS
Source of truth: [`docs/compatibility/matrix.md`](../compatibility/matrix.md#os-specific-known-issues).

- Windows: Webspace lifecycle support is still tech preview.
- Linux: no additional known issues in TASK-018 scope.
