# Reliability: Retry, Backoff, Idempotency

## Agent outbound HTTP policy

- Central retry policy is implemented in `agent/internal/api/retry.go` and used by both core-agent and panel-agent API clients.
- Default policy:
  - max attempts: 4
  - exponential backoff with jitter (20%)
  - retries on `429`, `5xx`, timeout/temporary network errors
  - `Retry-After` header takes precedence over computed backoff
  - circuit-breaker opens after repeated failures and cools down before allowing new attempts

## Endpoint classification

- **Safe retry**
  - `GET` endpoints (read-only polling)
  - mutating calls carrying `Idempotency-Key`
- **No retry**
  - non-idempotent mutating calls without `Idempotency-Key`

## Idempotency expectations (Core)

Server-side behavior expected from Core:

1. Treat `(agent_id, path, method, idempotency_key)` as dedupe scope.
2. Store the first terminal result (success or business error) for a bounded TTL.
3. Return the stored response for duplicate requests to prevent duplicate side effects.
4. Reject malformed/empty idempotency keys with `400`.

## Operational verification

- Watch `429` and `5xx` rates in API metrics.
- Confirm retry storms are bounded by max-attempts and breaker cooldown.
- During incidents, prefer `Retry-After` tuning over ad-hoc client restarts.
