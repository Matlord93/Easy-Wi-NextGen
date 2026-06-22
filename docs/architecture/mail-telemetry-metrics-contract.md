# Mail Telemetry Metrics Contract (MUST HAVE #8)

## 1) Design / Spec

### Zielbild
- Agent liefert **strukturierte, normalisierte Metriken** ohne externe Binaries.
- Panel persistiert in `mail_metric_buckets` (1m/5m/1h) als Source of Truth für Dashboards.
- Admin-UI liest ausschließlich aggregierte Buckets für stabile Abfragezeiten bei 10k Domains / 100k Mailboxen.

### Agent Pull Contract
`GET /v1/agent/mail/metrics`

JSON Schema (logical):
- `generated_at` (RFC3339)
- `node_id` (string)
- `window_seconds` (int)
- `metrics[]`
  - `name` (`queue.depth`, `queue.deferred`, `delivery.bounce`, `dkim.failures`, `auth.failures`, `mail.sent`)
  - `type` (`gauge|counter`)
  - `unit` (`messages|events`)
  - `value` (number)
  - `labels` (optional key/value)
  - `bucket_size_seconds` (60/300/3600)
  - `timestamp` (RFC3339)
- `top_senders[]` (`key`, `value`)
- `top_domains[]` (`key`, `value`)
- `queue` (`depth`, `deferred`, `active`)

## 2) Datenmodell / Migration

Neue Tabelle: `mail_metric_buckets`
- `id BIGSERIAL PK`
- `domain_id BIGINT NULL FK domains(id)`
- `bucket_start TIMESTAMP`
- `bucket_size_seconds INT`
- `metric_name VARCHAR(64)`
- `metric_value DOUBLE PRECISION`
- `dimensions JSONB`
- `created_at TIMESTAMP`

Indexstrategie:
- `(bucket_start, bucket_size_seconds)` für Zeitfenster
- `(metric_name, bucket_start)` für Metrik-Zeitreihen
- `(domain_id, metric_name, bucket_start)` für Tenant-/Domain-Dashboards
- `GIN(dimensions)` für Top-N/Dimension-Filter
- Unique-Rollup `(domain_id, bucket_start, bucket_size_seconds, metric_name, md5(dimensions::text))`

Retention Policy:
- 1m buckets: 14 Tage
- 5m buckets: 90 Tage
- 1h buckets: 400 Tage
- Nightly rollup + purge Job via Messenger/cron (idempotent)

## 3) API / DTO (Panel)

Admin Endpoints:
- `GET /api/v1/admin/mail/overview?from=&to=`
- `GET /api/v1/admin/mail/queue?from=&to=&bucket=300`
- `GET /api/v1/admin/mail/metrics?metric=mail.sent&dimension=sender&from=&to=&limit=20`

### Beispiel `/v1/agent/mail/metrics`
```json
{
  "generated_at": "2026-10-15T20:00:00Z",
  "node_id": "node-1",
  "window_seconds": 60,
  "metrics": [
    {"name": "queue.depth", "type": "gauge", "unit": "messages", "value": 128, "bucket_size_seconds": 60, "timestamp": "2026-10-15T20:00:00Z"},
    {"name": "queue.deferred", "type": "gauge", "unit": "messages", "value": 33, "bucket_size_seconds": 60, "timestamp": "2026-10-15T20:00:00Z"},
    {"name": "delivery.bounce", "type": "counter", "unit": "messages", "value": 9, "bucket_size_seconds": 60, "timestamp": "2026-10-15T20:00:00Z"},
    {"name": "dkim.failures", "type": "counter", "unit": "events", "value": 2, "bucket_size_seconds": 60, "timestamp": "2026-10-15T20:00:00Z"},
    {"name": "auth.failures", "type": "counter", "unit": "events", "value": 17, "bucket_size_seconds": 60, "timestamp": "2026-10-15T20:00:00Z"},
    {"name": "mail.sent", "type": "counter", "unit": "messages", "value": 420, "labels": {"domain": "example.com"}, "bucket_size_seconds": 60, "timestamp": "2026-10-15T20:00:00Z"}
  ],
  "top_senders": [{"key": "noreply@example.com", "value": 172}],
  "top_domains": [{"key": "example.com", "value": 521}],
  "queue": {"depth": 128, "deferred": 33, "active": 95}
}
```

## 4) Agent Contract

Agent HTTP Handler:
- `agent/cmd/agent/mail_metrics_http.go`
- registered in service mux: `/v1/agent/mail/metrics`

Collector Package:
- `agent/internal/mail/telemetry`
- Interface `Collector` mit `Collect(ctx)` => `Snapshot`
- deterministic metric naming; keine shell execution über `sh -c`.

## 5) Tests / Edgecases

1. Leerer Collector liefert dennoch valides JSON mit 0-Werten.
2. Collector-Fehler => HTTP `503 METRICS_UNAVAILABLE`.
3. Ungültige Methode (`POST`) => `405`.
4. Zeitfenster `from > to` im Panel wird auf `to-1h..to` geklammert.
5. `bucket` Query wird auf `60..3600` begrenzt.
6. Top-N Queries limitieren hart auf max 100.
7. Hohe Kardinalität in `dimensions` wird per GIN + retention abgefangen.
8. Counter Reset vom Agent beeinflusst nur neue Buckets, nicht historische.
