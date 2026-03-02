# API Contracts (`docs/api/`)

## Single Source of Truth

For Core ↔ Agent communication, the canonical contract is:

- `core-agent.v1.openapi.yaml`

Legacy files (for example `agent-v1.openapi.yaml`) are kept only as transitional aliases and are **not** authoritative.

## Versioning Policy

- We use URI major versioning (`/api/v1/...`).
- **Additive** changes in `v1` are allowed (new optional fields, additional non-breaking response fields, new endpoints).
- **Breaking** changes require a new major (`v2`) and migration window.

## Deprecation Policy (Legacy vs v1)

- Legacy/alias specs are marked deprecated and point to the canonical `v1` file.
- Endpoint deprecations inside `v1` must:
  1. be documented in this folder,
  2. keep backward compatibility during the deprecation window,
  3. include replacement endpoint guidance.

## CI Checks

CI enforces:

1. OpenAPI structural validation of `core-agent.v1.openapi.yaml`.
2. Breaking-change diff against `origin/main` for the same file.
3. Contract tests in both `core` and `agent` pipelines.

## Shared Error Envelope

- Canonical schema: `error-envelope.schema.yaml`.
- Core and Agent APIs must return errors as:

```json
{
  "error": {
    "code": "STRING_ENUM",
    "message": "string",
    "request_id": "string",
    "details": {}
  }
}
```

- `request_id` must be propagated from `X-Request-ID` (or generated when missing).

## Retry & Idempotency Contract

- Clients may automatically retry `GET` requests for transient failures (`429`, `5xx`, timeout).
- Mutating requests (`POST/PUT/PATCH/DELETE`) must include `Idempotency-Key` to be retry-safe.
- Core should persist idempotency keys per endpoint + agent for a bounded TTL and return the original outcome for duplicates.
- When rate limiting (`429`), Core should send `Retry-After`; clients must honor it before the next attempt.


## Voice Probe Rate-Limit Verhalten (v1)

Für `/api/v1/customer/voice/{id}/probe` gilt:

- `429` mit `error.code=voice_rate_limited` wenn Core/Agent Token-Bucket oder Backoff aktiv ist.
- `error.code=voice_circuit_open` wenn der Core-Circuit (mehrfache Probe-Fehler) aktiv ist.
- Agent-seitige Probe-Fehler werden in Job-Outputs mit folgenden Codes gespiegelt:
  - `voice_query_rate_limited`
  - `voice_query_timeout`
  - `voice_query_banned`
  - `voice_query_auth_failed`
  - `voice_query_failed`
- Wenn vorhanden, muss `Retry-After` bzw. `retry_after` durch Clients respektiert werden.
- `X-Correlation-ID` wird bis zum Agent-Query propagiert und in Logs/Outputs zurückgegeben.

- `webspace-lifecycle-contract.md`: Webspace lifecycle + file API contract (TASK-015).
