# Observability: Request and Correlation IDs

## Standard

- **Request header**: `X-Request-ID`
- **Correlation header**: `X-Correlation-ID`
- **Format**: RFC4122 UUID (v4)

## Rules

1. Inbound requests:
   - Accept incoming `X-Request-ID` / `X-Correlation-ID` if valid UUID.
   - If `X-Request-ID` is missing/invalid, generate a UUID.
   - If `X-Correlation-ID` is missing/invalid, fallback to `X-Request-ID`.
2. Core and Agent must set both headers on outbound responses.
3. Core→Agent and Agent→Core client calls must propagate both headers from request/job context.
4. Job payload/metadata must include `correlation_id` and preserve it through execution.
5. Logs and error/success envelopes should expose `request_id` and `correlation_id` for support and tracing.

## JSON Log Schema (Minimum)

All operational logs MUST be structured JSON and include the minimum schema below:

- `level`
- `service`
- `timestamp`
- `request_id`
- `correlation_id`
- `agent_id`
- `event`
- `error_code`
- `msg`

Optional implementation-specific details can be added via an additional object (for example `fields`).

### Example: Agent info event

```json
{
  "level": "info",
  "service": "agent",
  "timestamp": "2026-03-02T13:37:10.521Z",
  "request_id": "63dc9bca-f96c-4cd2-a713-7e2a197de5d8",
  "correlation_id": "63dc9bca-f96c-4cd2-a713-7e2a197de5d8",
  "agent_id": "agent-eu-central-1",
  "event": "agent.heartbeat_sent",
  "error_code": "",
  "msg": "heartbeat submitted",
  "fields": {
    "interval_seconds": 30
  }
}
```

### Example: Core Monolog event

```json
{
  "message": "job dispatch failed",
  "context": {
    "job_id": "2014"
  },
  "level": 400,
  "level_name": "ERROR",
  "channel": "app",
  "datetime": "2026-03-02T13:37:12.104581+00:00",
  "extra": {
    "request_id": "3ab12f72-52ee-4d0b-9bd1-9f6d799156c1",
    "correlation_id": "3ab12f72-52ee-4d0b-9bd1-9f6d799156c1"
  }
}
```

## Operational checks

- Validate headers in edge logs or reverse proxy access logs.
- Verify Monolog entries include `extra.request_id` + `extra.correlation_id`.
- Verify queued jobs include `payload.correlation_id`.
- Verify API error payloads include `request_id` and `correlation_id`.

## Mail backend abuse/alert telemetry

- Mail operations are gated by `mail_enabled` and `mail_backend` (`none|local|panel|external`). If disabled, mailbox/alias operations return `MAIL_BACKEND_DISABLED` with an operator hint.
- Agent mail log batches now emit `mail.security.auth_failures_alert` audit events when `auth_failure`/login-failure patterns are detected.
- Keep ingest batches <= 1000 events per request to avoid drops and enforce bounded ingest cost.
