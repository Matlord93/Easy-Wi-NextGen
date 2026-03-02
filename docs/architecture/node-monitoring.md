# Node Monitoring & Metrics Pipeline

- Agent collects CPU, RAM, disk, network, temperature (if available), load, uptime, process_count and top processes.
- Agent sends heartbeat plus compressed metric batches (`/agent/metrics-batch`) with bounded queue (backpressure, max 120 samples, max batch 50).
- Backend writes raw samples (`metric_samples`) and rollups (`metric_aggregates`) for buckets `1m`, `5m`, `1h` (min/avg/max).
- Timestamps are parsed and normalized to UTC.
- Node status is derived from heartbeat grace period + metric thresholds:
  - `offline` when heartbeat is older than grace period.
  - `warning` when cpu/memory/disk >= 85%.
  - `critical` when cpu/memory/disk >= 95%.
  - otherwise `ok`.
- Integrity: all push endpoints keep signed agent auth and agent binding.
- Retention: `app:metrics:cleanup` now removes old rows from `metric_samples`, `instance_metric_samples` and `metric_aggregates`.
