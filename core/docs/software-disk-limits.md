# Software Disk Limits

Easy-Wi NextGen enforces disk limits entirely in software (no filesystem quotas). Each instance is scanned regularly and enforcement rules apply automatically.

## Overview

- **Instance fields**: `disk_limit_bytes`, `disk_used_bytes`, `disk_state`, `disk_last_scanned_at`, `disk_scan_error`.
- **Node settings**: per-node scan interval, warning threshold, hard-block threshold, and disk protection threshold.
- **Agent handlers**: `instance.disk.scan`, `instance.disk.top`, and `node.disk.stat`.
- **Scheduler commands**:
  - `disk:scan:reconcile` queues instance scans and node disk stat jobs.
  - `disk:enforce:reconcile` updates disk states and enforces hard blocks.

## Disk state rules

- **warning** when usage ≥ warning percent (default 85%)
- **over_limit** when usage ≥ 100%
- **hard_block** when usage ≥ hard-block percent (default 120%)

Hard block triggers an `instance.stop` job and suspends the instance.

## Node disk protection

Nodes enter **Protect Mode** when free space falls below the configured threshold (default 5%). In Protect Mode:

- Provisioning jobs are blocked.
- Uploads and installs/updates are blocked.

Admins can apply a temporary override in the Nodes UI. Overrides expire after the timer or when disk space is back to normal.

## Upload gate

All uploads/extractions/install actions should validate:

```
 disk_used_bytes + upload_size <= disk_limit_bytes
```

If the check fails, the action is rejected with:

> Speicherlimit erreicht. Bitte Dateien löschen oder Speicher erhöhen.

## Manual test steps

1. **Simulate disk usage thresholds**
   - Increase instance disk usage above 85%, 100%, and 120%.
   - Run `disk:scan:reconcile` then `disk:enforce:reconcile`.
   - Verify UI badges and banners change (`warning`, `over_limit`, `hard_block`).

2. **Verify blocks**
   - Trigger update/install or upload actions for `over_limit` instances.
   - Confirm the action is blocked with the user-facing message.

3. **Verify suspend**
   - Push usage ≥ 120%, run enforcement.
   - Confirm instance status is `suspended` and a stop job is queued.

4. **Node protection**
   - Simulate node free disk < 5% and run `disk:scan:reconcile`.
   - Confirm Protect Mode badge appears and provisioning is blocked.
   - Set an override in the Nodes UI and ensure Protect Mode is lifted until expiry.
