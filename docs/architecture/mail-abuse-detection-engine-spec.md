# Abuse Detection Engine v1 (Deterministic, No-ML) Specification

## 0. Scope and Hard Constraints

This document defines a production-ready deterministic Abuse Detection Engine for multi-tenant mail hosting.

Hard constraints:
1. Deterministic rule evaluation only (no ML, no stochastic scoring).
2. Agent never mutates enforcement policy locally.
3. All enforcement changes flow through panel-issued `mail.applyConfig` jobs.
4. UUID v7 for all new primary keys.
5. Multi-tenant isolation by `owner_id` in all tenant-bound queries.
6. UTC timestamps (`TIMESTAMPTZ`) only.

---

## 1) Architecture

## 1.1 Components

**Execution Plane (Go 1.22 Agent):**
- Emits telemetry events/counters (auth failures, bounces, recipient fanout, DKIM failures).
- Applies configuration snapshots when panel dispatches `mail.applyConfig`.
- Does not decide or enforce abuse policies autonomously.

**Control Plane (Symfony 8 + PostgreSQL 16):**
- Stores deterministic rule definitions (`mail_abuse_rules`).
- Evaluates rules over normalized metric buckets.
- Creates incidents (`mail_abuse_incidents`) and determines actions.
- Issues `mail.applyConfig` jobs for temporary throttle/block enforcement.

## 1.2 Data Flow

1. Agent posts telemetry buckets (already normalized).
2. Panel persists buckets and triggers `abuse.evaluateWindow` worker.
3. Worker loads active rules ordered deterministically.
4. Worker evaluates rules against fixed windows and creates incidents.
5. Action router maps incident severity to `alert|temporary_throttle|temporary_block`.
6. For enforcement actions, panel creates policy diff + dispatches `mail.applyConfig`.
7. Agent applies config atomically and reports result.

## 1.3 Supported v1 Rule Families

1. **auth failure spike**
2. **bounce rate spike**
3. **recipient fanout anomaly**
4. **DKIM fail spike**

Each rule is deterministic threshold-based and window-bound.

---

## 2) Database

## 2.1 `mail_abuse_rules`

```sql
CREATE TABLE mail_abuse_rules (
  id                           UUID PRIMARY KEY,
  owner_id                     UUID NULL,

  rule_key                     VARCHAR(64) NOT NULL,
  scope_type                   VARCHAR(16) NOT NULL,
  scope_id                     UUID NULL,

  enabled                      BOOLEAN NOT NULL DEFAULT TRUE,
  action                       VARCHAR(32) NOT NULL,

  metric_name                  VARCHAR(64) NOT NULL,
  window_seconds               INTEGER NOT NULL,
  baseline_window_seconds      INTEGER NULL,

  threshold_bp                 INTEGER NULL,
  threshold_abs                BIGINT NULL,
  min_volume                   BIGINT NOT NULL DEFAULT 0,

  cooldown_seconds             INTEGER NOT NULL DEFAULT 0,
  ttl_seconds                  INTEGER NOT NULL,
  priority                     INTEGER NOT NULL,

  params_json                  JSONB NOT NULL DEFAULT '{}'::jsonb,

  valid_from                   TIMESTAMPTZ NOT NULL,
  valid_to                     TIMESTAMPTZ NULL,

  created_at                   TIMESTAMPTZ NOT NULL,
  updated_at                   TIMESTAMPTZ NOT NULL,
  created_by_actor_id          UUID NOT NULL,
  updated_by_actor_id          UUID NOT NULL,

  CONSTRAINT chk_abuse_rule_key
    CHECK (rule_key IN ('auth_failure_spike','bounce_rate_spike','recipient_fanout_anomaly','dkim_fail_spike')),
  CONSTRAINT chk_abuse_scope_type
    CHECK (scope_type IN ('platform','owner','domain','mailbox')),
  CONSTRAINT chk_abuse_action
    CHECK (action IN ('alert','temporary_throttle','temporary_block')),
  CONSTRAINT chk_abuse_window
    CHECK (window_seconds IN (60,300,900,3600,86400)),
  CONSTRAINT chk_abuse_baseline_window
    CHECK (baseline_window_seconds IS NULL OR baseline_window_seconds IN (3600,21600,86400,604800)),
  CONSTRAINT chk_abuse_threshold
    CHECK (threshold_bp IS NOT NULL OR threshold_abs IS NOT NULL),
  CONSTRAINT chk_abuse_threshold_bp
    CHECK (threshold_bp IS NULL OR threshold_bp BETWEEN 1 AND 100000),
  CONSTRAINT chk_abuse_ttl
    CHECK (ttl_seconds BETWEEN 60 AND 2592000),
  CONSTRAINT chk_abuse_priority
    CHECK (priority BETWEEN 1 AND 100000),
  CONSTRAINT chk_abuse_validity
    CHECK (valid_to IS NULL OR valid_to > valid_from),
  CONSTRAINT chk_abuse_params_json
    CHECK (jsonb_typeof(params_json) = 'object')
);

CREATE UNIQUE INDEX uq_abuse_rule_deterministic
  ON mail_abuse_rules(
    COALESCE(owner_id::text,''),
    rule_key,
    scope_type,
    COALESCE(scope_id::text,''),
    priority,
    valid_from
  );

CREATE INDEX ix_abuse_rules_eval
  ON mail_abuse_rules(enabled, rule_key, priority DESC, id);

CREATE INDEX ix_abuse_rules_scope
  ON mail_abuse_rules(scope_type, scope_id, enabled, priority DESC);
```

Notes:
- `owner_id NULL` means platform-global rule.
- `scope_id` references entity implied by `scope_type` (validated at service layer).

## 2.2 `mail_abuse_incidents`

```sql
CREATE TABLE mail_abuse_incidents (
  id                           UUID PRIMARY KEY,
  owner_id                     UUID NOT NULL,
  domain_id                    UUID NULL,
  mailbox_id                   UUID NULL,

  rule_id                      UUID NOT NULL REFERENCES mail_abuse_rules(id),
  rule_key                     VARCHAR(64) NOT NULL,

  window_id                    VARCHAR(64) NOT NULL,
  window_start                 TIMESTAMPTZ NOT NULL,
  window_end                   TIMESTAMPTZ NOT NULL,

  observed_value               BIGINT NOT NULL,
  baseline_value               BIGINT NULL,
  computed_ratio_bp            INTEGER NULL,
  threshold_bp                 INTEGER NULL,
  threshold_abs                BIGINT NULL,

  action                       VARCHAR(32) NOT NULL,
  status                       VARCHAR(16) NOT NULL,

  enforcement_config_version   BIGINT NULL,
  enforced_until               TIMESTAMPTZ NULL,

  fingerprint                  CHAR(64) NOT NULL,
  details_json                 JSONB NOT NULL,

  created_at                   TIMESTAMPTZ NOT NULL,
  updated_at                   TIMESTAMPTZ NOT NULL,
  resolved_at                  TIMESTAMPTZ NULL,

  correlation_id               VARCHAR(64) NOT NULL,

  CONSTRAINT chk_incident_rule_key
    CHECK (rule_key IN ('auth_failure_spike','bounce_rate_spike','recipient_fanout_anomaly','dkim_fail_spike')),
  CONSTRAINT chk_incident_action
    CHECK (action IN ('alert','temporary_throttle','temporary_block')),
  CONSTRAINT chk_incident_status
    CHECK (status IN ('open','enforcing','resolved','expired','suppressed')),
  CONSTRAINT chk_incident_ratio_bp
    CHECK (computed_ratio_bp IS NULL OR computed_ratio_bp BETWEEN 0 AND 100000),
  CONSTRAINT chk_incident_fingerprint
    CHECK (fingerprint ~ '^[0-9a-f]{64}$'),
  CONSTRAINT chk_incident_details_json
    CHECK (jsonb_typeof(details_json) = 'object'),
  CONSTRAINT chk_incident_window
    CHECK (window_end > window_start)
);

CREATE UNIQUE INDEX uq_incident_fingerprint
  ON mail_abuse_incidents(owner_id, fingerprint);

CREATE UNIQUE INDEX uq_incident_window_rule_scope
  ON mail_abuse_incidents(owner_id, rule_id, COALESCE(domain_id::text,''), COALESCE(mailbox_id::text,''), window_id);

CREATE INDEX ix_incident_owner_time
  ON mail_abuse_incidents(owner_id, created_at DESC);

CREATE INDEX ix_incident_status_action
  ON mail_abuse_incidents(status, action, created_at DESC);

CREATE INDEX ix_incident_rule_window
  ON mail_abuse_incidents(rule_key, window_start DESC);
```

Incident dedupe:
- fingerprint input = canonical JSON of `{owner_id,domain_id,mailbox_id,rule_id,window_id,observed_value,baseline_value,action}` hashed by SHA-256.

Retention:
- incidents retained 400 days.
- rules retained indefinitely (version history).

---

## 3) REST API

All mutating endpoints require `Idempotency-Key`.
All tenant lookups enforce `owner_id` scope.
Error model includes `correlationId`.

### 3.1 Admin Rule CRUD

#### `POST /api/v1/admin/mail/abuse/rules`
Creates a rule.

Request example:
```json
{
  "ownerId": "01970500-8d33-729b-b1a7-bbe1dd0ca4fe",
  "ruleKey": "auth_failure_spike",
  "scopeType": "domain",
  "scopeId": "01970500-ec6f-7778-b65d-0463dc68ba4c",
  "enabled": true,
  "action": "temporary_throttle",
  "metricName": "auth.failures",
  "windowSeconds": 300,
  "baselineWindowSeconds": 86400,
  "thresholdBp": 25000,
  "thresholdAbs": 200,
  "minVolume": 50,
  "cooldownSeconds": 1800,
  "ttlSeconds": 3600,
  "priority": 7000,
  "params": {"throttleRatePerMinute": 120}
}
```

Response (`201`):
```json
{
  "id": "01970502-295b-7bf6-a45e-f2328d0debf7",
  "version": 1,
  "correlationId": "01K..."
}
```

#### `PATCH /api/v1/admin/mail/abuse/rules/{id}`
Partial update (enabled/action/thresholds/ttl/priority).

#### `GET /api/v1/admin/mail/abuse/rules`
List rules with filters: `ownerId`, `ruleKey`, `enabled`, `scopeType`, `scopeId`, `page`, `pageSize`.

### 3.2 Admin Incident APIs

#### `GET /api/v1/admin/mail/abuse/incidents`
Query params:
- `ownerId` optional UUID (super-admin)
- `domainId` optional UUID
- `mailboxId` optional UUID
- `ruleKey` optional
- `status` optional
- `action` optional
- `from` required RFC3339
- `to` required RFC3339
- `page`, `pageSize`

Response example:
```json
{
  "items": [
    {
      "id": "01970506-e66f-7b6c-8c00-a04ed632f2fa",
      "ownerId": "01970500-8d33-729b-b1a7-bbe1dd0ca4fe",
      "domainId": "01970500-ec6f-7778-b65d-0463dc68ba4c",
      "ruleKey": "bounce_rate_spike",
      "windowId": "2026-11-15T10:00:00Z/PT5M",
      "observedValue": 142,
      "baselineValue": 23,
      "computedRatioBp": 61739,
      "action": "temporary_block",
      "status": "enforcing",
      "enforcedUntil": "2026-11-15T11:00:00Z",
      "createdAt": "2026-11-15T10:05:10Z"
    }
  ],
  "pagination": {"page": 1, "pageSize": 50, "total": 1},
  "correlationId": "01K..."
}
```

#### `POST /api/v1/admin/mail/abuse/incidents/{id}/resolve`
Manual resolve; requires `Idempotency-Key`, audited.

---

## 4) Rule Evaluation Model

## 4.1 Evaluation Inputs

The evaluator consumes immutable metric buckets for a given `window_id`:
- `auth.failures`
- `bounce.rate_bp` or (`bounces`,`sent`) to compute ratio
- `recipient.fanout`
- `dkim.failures`

`window_id` format: `<window_start_utc>/<duration_iso8601>`.

## 4.2 Deterministic Rule Selection Order

Rules are loaded in exact order:
1. owner-specific scoped rules (mailbox -> domain -> owner -> platform fallback)
2. priority DESC
3. rule id ASC

Only active rules with validity covering `window_end` are considered.

## 4.3 Threshold Logic by Rule Type

1. **auth_failure_spike**
   - trigger if:
     - `observed >= threshold_abs` (if set), and
     - `observed * 10000 / max(baseline,1) >= threshold_bp` (if set), and
     - `observed >= min_volume`

2. **bounce_rate_spike**
   - compute `observed_rate_bp = bounces * 10000 / max(sent,1)`
   - trigger if `observed_rate_bp >= threshold_bp` and `sent >= min_volume`

3. **recipient_fanout_anomaly**
   - `observed = distinct_recipients_per_sender_window` (or configured metric)
   - trigger on abs/bp criteria.

4. **dkim_fail_spike**
   - similar to auth failure spike against DKIM failures metric.

Cooldown behavior:
- If prior incident for same fingerprint scope is within cooldown, new incident status=`suppressed` and no enforcement job emitted.

## 4.4 Action Resolution

Action chosen from rule (`alert|temporary_throttle|temporary_block`), then clamped by global safety policy if needed.

Action effects:
- `alert`: create incident only.
- `temporary_throttle`: create incident + config diff to rate-limit outbound.
- `temporary_block`: create incident + config diff to block outbound path for target scope.

---

## 5) Enforcement Workflow

1. Evaluator creates incident row (`open`).
2. If action requires enforcement:
   - Build deterministic config patch from incident scope and TTL.
   - Write policy state in DB.
   - Enqueue `mail.applyConfig` with correlation_id.
   - Mark incident `enforcing` once agent ack success.
3. Expiry worker checks `enforced_until` and reverts temporary controls via new `mail.applyConfig` job.
4. Incident transitions to `expired` or `resolved`.

No direct agent-side mutation:
- Agent only applies panel-provided snapshot/config version.

---

## 6) Audit Logging

Audited operations:
1. Rule create/update/disable/delete.
2. Incident creation/suppression/resolution.
3. Enforcement apply/revert dispatch and agent ack/failure.

Audit event required fields:
- `actor_id` (or system actor)
- `owner_id`
- `correlation_id`
- `event_type`
- `before_hash`
- `after_hash`
- `occurred_at` UTC

Hashing:
- canonical JSON serialization with sorted keys, SHA-256 hex.

---

## 7) Acceptance Criteria

1. Identical metrics + rule set + window produce identical incident outcomes.
2. Agent never performs autonomous policy mutation.
3. Enforcement always travels via `mail.applyConfig` jobs.
4. No duplicate incidents for same fingerprint/window/scope.
5. Rule priority and tie-break ordering are deterministic and test-covered.
6. Cooldown suppresses repeated triggers deterministically.
7. Manual resolve and rule mutations are idempotent and audited.
8. Multi-tenant boundaries are enforced in all API and evaluation queries.

---

## 8) Edge Cases

1. Missing baseline window data:
   - baseline defaults to 1 for ratio math; mark `details_json.baseline_missing=true`.

2. Zero sent count for bounce-rate rule:
   - treat rate as 0; do not trigger unless abs threshold path explicitly satisfied.

3. Conflicting rules in same scope:
   - highest priority wins for enforcement; all matches can still generate alert-only incidents if configured.

4. Job retry after partial failure:
   - unique constraints + idempotency keys prevent duplicate enforcement state.

5. Clock skew between nodes:
   - evaluation keyed to panel window boundaries, not agent wall clock.

6. Rule changed mid-window:
   - evaluator uses rule snapshot valid at evaluation time and persists `rule_id` + `rule_key` for traceability.

---

## 9) Determinism Guarantees

Formal function:
- `D(rule_snapshot, metric_buckets, window_id) -> incidents + actions`

Determinism controls:
1. Fixed window ID generation and UTC alignment.
2. Stable rule ordering (`scope precedence`, `priority DESC`, `id ASC`).
3. Integer-only arithmetic (`basis points`, no floats persisted).
4. Canonical incident fingerprint hashing.
5. Idempotent upsert semantics for incidents/enforcement dispatch.

Required verification tests:
- Golden tests for each rule family with fixed inputs.
- Permutation tests with shuffled rule rows/metric rows.
- Replay tests for duplicate ingestion/evaluation jobs.
- Enforcement replay test ensures one effective `mail.applyConfig` per incident action.

