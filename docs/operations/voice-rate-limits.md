# Voice Query Rate Limits & Burst Control

## Ziel

`voice.probe` Requests werden im Agent jetzt aktiv gedrosselt, damit Query-Endpunkte bei Polling-Spitzen nicht in Ban/Cooldown laufen.

## Schutzmechanismen

- **Token Bucket pro Target** (`host + port + user`)
  - `VOICE_QUERY_RPS (Fallback: VOICE_QUERY_RATE_PER_SECOND)` (Default: `2`)
  - `VOICE_QUERY_BURST` (Default: `3`)
- **Concurrency-Limit pro Target**
  - `VOICE_QUERY_MAX_CONCURRENCY` (Default: `1`)
- **Backoff mit Jitter**
  - bei `429`/Timeouts exponentieller Backoff, optional mit `Retry-After`
  - `VOICE_QUERY_BACKOFF_BASE` (Default: `2s`)
  - `VOICE_QUERY_BACKOFF_MAX` (Default: `90s`)
- **Harter Cooldown/Circuit**
  - bei Ban-/Auth-Indikatoren (`ban`, `401`, `403`) harter Cooldown
  - `VOICE_BAN_COOLDOWN (Fallback: VOICE_QUERY_HARD_COOLDOWN)` (Default: `10m`)
- **Caching/Coalescing**
  - Singleflight für identische Status-Queries
  - `VOICE_QUERY_STATUS_CACHE_TTL` (Default: `15s`)
  - `VOICE_QUERY_INVARIANT_CACHE_TTL` (Default: `3m`)

## Telemetrie

Der Agent liefert in `voice.probe`-Responses und Logs:

- `metric_query_rps`
- `metric_ban_events`
- `metric_retries`
- `metric_latency_avg_ms`
- `correlation_id`

Zusätzlich strukturierte Log-Events:

- `voice.query.ok`
- `voice.query.backoff`
- `voice.query.alert`
- `voice.query.failed`

## Runbook bei Ban/Cooldown

1. `voice.query.alert` im Agent-Log prüfen (`correlation_id`, target, code).
2. `retry_after` aus API/Error beachten.
3. Polling-Rate im Scheduler/UI reduzieren.
4. Falls nötig temporär erhöhen:
   - `VOICE_BAN_COOLDOWN (Fallback: VOICE_QUERY_HARD_COOLDOWN)`
   - `VOICE_QUERY_RPS (Fallback: VOICE_QUERY_RATE_PER_SECOND)` senken
   - `VOICE_QUERY_BURST` senken


## Ban/Timeout-Erkennung

- Ban-Antworten (`ban`/`banned` im Body, HTTP `401/403`) setzen sofort einen harten Cooldown je Target.
- Wiederholte Timeouts aktivieren ebenfalls den Cooldown (Default-Schwelle: `3` Timeouts, steuerbar über `VOICE_QUERY_TIMEOUT_COOLDOWN_THRESHOLD`).
- Während Cooldown werden weitere Queries für das Target nicht mehr zum Upstream geschickt.
