# Mail Job/Queue Design (Control-Plane → Execution-Plane) — MUST HAVE #12

## 1) Design / Spec

### Objective
Deterministic, idempotent and observable orchestration for `mail.*` operations from Symfony control-plane to Go execution-plane.

### Mail job types
- `mail.applyDomain`
- `mail.rotateDkim`
- `mail.checkDns`
- `mail.flushQueue`
- `mail.restartService`

### Execution model
1. API/controller validates request and builds typed DTO.
2. `MailControlPlaneJobEnqueuer` dispatches `MailControlPlaneJobMessage` to Symfony Messenger `async` transport.
3. `MailControlPlaneJobMessageHandler` resolves target node and dispatches an orchestrator `agent_jobs` record.
4. Agent polls job, executes deterministically, reports result.
5. Result is persisted in `agent_jobs` and mapped into `job_results` style status/error envelope for UI.

### Correlation & traceability
- `correlation_id` generated at ingress and copied end-to-end:
  - request logs
  - messenger message
  - `agent_jobs.payload.correlation_id`
  - agent result payload
- `idempotency_key` propagated the same way.

---

## 2) Datenmodell / Migration (Assumptions)

This design intentionally reuses existing persistence:

### Required tables
- `agent_jobs` (execution dispatch queue, includes `idempotency_key`, status and result payload)
- `jobs` / `job_results` (control-plane reporting surface)

### Assumptions
- `agent_jobs.idempotency_key` indexed and queryable (`findLatestByIdempotencyKey`).
- `job_results` supports status + output/error payload mapping.
- payload JSON can safely store:
  - `correlation_id`
  - `idempotency_key`
  - typed command fields

No mandatory schema migration required for this step.

---

## 3) API / DTO

### Typed DTOs (PHP 8.4 readonly)
- `MailApplyDomainJobDto`
- `MailRotateDkimJobDto`
- `MailCheckDnsJobDto`
- `MailFlushQueueJobDto`
- `MailRestartServiceJobDto`

All DTOs expose `toPayload(): array` for deterministic serialization into queue payloads.

### Message envelope
`MailControlPlaneJobMessage`
- `nodeId`
- `type` (`MailJobType` enum)
- `correlationId`
- `idempotencyKey`
- `payload`
- `maxAttempts`

---

## 4) Agent-Contract

Agent receives `agent_jobs.type = mail.*` and payload fields required per type.

### Payload contract examples
```json
{
  "type": "mail.applyDomain",
  "payload": {
    "node_id": "node-1",
    "domain": "example.com",
    "snapshot_id": "snap_2026-10-15T21:00:00Z",
    "dry_run": false,
    "correlation_id": "e7f2...",
    "idempotency_key": "a16b..."
  }
}
```

```json
{
  "type": "mail.checkDns",
  "payload": {
    "domain": "example.com",
    "dkim_selector": "mail202610",
    "expected_mx": ["mx1.example.com"],
    "correlation_id": "e7f2...",
    "idempotency_key": "95d4..."
  }
}
```

---

## 5) Retry / Dead-letter strategy

### Symfony Messenger transport
- transport: `async`
- retries: exponential backoff (`max_retries=3`, multiplier 2)
- failure transport: `failed`

### Agent orchestrator retries
- `agent_jobs.retries` incremented by agent-worker loop.
- terminal failures mapped to failed state + error payload.

### Dead-letter policy
- Messages failing in Messenger after retry budget → `failed` transport.
- Operator can replay from `failed` after root-cause fix.

---

## 6) Idempotency model

Deterministic idempotency key over:
`hash(node_id + job_type + canonical_payload)`

Rules:
- same key + same payload + queued/running existing job => return existing effect.
- same key + already succeeded => treat as no-op success (same result surface).
- same key + failed => explicit requeue requires new idempotency key (or force override flag).

---

## 7) Result mapping (`agent_jobs` → `job_results` view)

Recommended normalized mapping:
- `agent_jobs.status=success` → `job_results.status=succeeded`
- `agent_jobs.status=failed` → `job_results.status=failed`
- error fields:
  - `error_code`
  - `error_message`
  - `payload` (diagnostic details + file/service context)

Correlation fields remain attached for all UI/API responses.

---

## 8) Symfony Messenger setup + handler skeletons

Implemented skeleton components:
- `core/src/Message/MailControlPlaneJobMessage.php`
- `core/src/Module/Core/Application/Mail/Queue/MailControlPlaneJobEnqueuer.php`
- `core/src/Module/Core/Application/Mail/Queue/MailControlPlaneJobMessageHandler.php`
- messenger routing in `core/config/packages/messenger.yaml`

These skeletons provide a concrete baseline for mail job orchestration while keeping behavior deterministic.

---

## 9) Example flow: Create Domain → Apply → Check DNS

1. Customer creates domain (`POST /api/v1/customer/mail/domains`).
2. Control-plane writes domain row and emits `mail.applyDomain` message.
3. Handler dispatches `agent_jobs` for target node (idempotent key persisted).
4. Agent executes apply from snapshot and returns success/failure payload.
5. Control-plane emits `mail.checkDns` message with selector + expected MX.
6. Agent returns structured findings; panel updates domain statuses and logs.

---

## 10) Edge cases

1. Duplicate request with same idempotency key and payload.
2. Duplicate request with same key but changed payload (reject as conflict).
3. Node unavailable during dispatch.
4. Agent timeout after apply started.
5. DNS check partial success (warning state, not hard fail).
6. Restart service requested for unsupported service name.
7. Correlation ID missing at ingress (generate server-side).
8. Poison message in Messenger (moved to `failed`).
