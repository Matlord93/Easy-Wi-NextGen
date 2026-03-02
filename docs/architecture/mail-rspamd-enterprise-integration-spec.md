# Rspamd Enterprise Integration Specification (Implementation-Ready)

## 0. Scope, Invariants, and Non-Negotiable Rules

This specification defines the complete control-plane and agent-plane contract for Rspamd integration in a multi-tenant mail hosting platform.

Hard invariants:
1. PostgreSQL 16 is the single source of truth.
2. Every generated config artifact must be deterministic from a DB snapshot.
3. Identical snapshot state must produce byte-identical config files.
4. UUID v7 is mandatory for every new table PK.
5. Every timestamp is UTC (`TIMESTAMPTZ`).
6. Tenant isolation is mandatory (`owner_id` in every query path and uniqueness scope where required).
7. Every mutating API endpoint requires `Idempotency-Key`.
8. No shell injection; agent executes fixed binary + fixed argument vectors only.
9. No plaintext secrets in DB (only encrypted payload columns where explicitly approved).
10. Sensitive operations are audited with actor, correlation, and pre/post hashes.

---

## 1) Architecture & Design

### 1.1 Deployment Topology

**Control Plane (Symfony 8 + Doctrine + Messenger):**
- Stores policy state and stats buckets.
- Resolves effective policy (mailbox > domain > global default).
- Emits immutable, versioned mail snapshots.
- Enqueues `mail.applyRspamdPolicy` and `mail.applyConfig` jobs.

**Execution Plane (Go 1.22 Agent):**
- Pulls approved snapshot.
- Renders deterministic Rspamd files.
- Runs `rspamadm configtest`.
- Performs atomic activation + controlled reload + rollback on failure.

**Data Plane:**
- Postfix -> Rspamd milter (`worker-proxy`) for inbound filtering.
- Optional outbound filtering for submission traffic.

### 1.2 Inbound Filtering via Milter

Reference path:
1. SMTP inbound message enters Postfix (`smtpd`).
2. Postfix milter calls local Rspamd proxy (`inet:127.0.0.1:11332`).
3. Rspamd applies effective policy and symbol overrides.
4. Rspamd returns action (`reject`, `add_header`, `greylist`, `no action`).
5. Postfix enforces returned action.

Required Postfix integration flags:
- `smtpd_milters = inet:127.0.0.1:11332`
- `non_smtpd_milters = inet:127.0.0.1:11332` (only when outbound filtering enabled)
- `milter_default_action = tempfail`
- `milter_protocol = 6`

### 1.3 Optional Outbound Filtering

Outbound filtering is domain-scoped boolean `outbound_filtering_enabled`.

Behavior:
- If disabled: submission traffic bypasses Rspamd milter.
- If enabled: submission path includes milter and applies same effective policy chain.
- If enabled globally but disabled for a domain: domain wins (explicit false overrides global true).

### 1.4 Effective Policy Resolution Order

Resolution precedence for each message context:
1. Mailbox policy (`mailbox_id = resolved recipient mailbox UUID`) if `enabled = true`.
2. Domain policy (`mailbox_id IS NULL`, `domain_id = recipient domain UUID`) if `enabled = true`.
3. Global default policy (`scope = global`, synthetic in snapshot, immutable profile version).

Deterministic tie-breakers:
- For mailbox scope: at most one active row due to unique constraint.
- For domain scope: at most one active row due to partial unique constraint.
- If malformed state exists (should be impossible), highest `updated_at`, then lexicographically smallest UUID is selected and an alert event is emitted.

### 1.5 Deterministic Rendering Strategy

Determinism rules:
1. Snapshot payload includes all effective policies materialized with canonical ordering.
2. Agent never queries local runtime state for rendering decisions.
3. Renderer writes files with LF newlines only.
4. Stable sort keys:
   - Domains by `domain_name` ascending (bytewise UTF-8), then `domain_id`.
   - Mailboxes by `local_part` ascending, then `mailbox_id`.
   - Symbol override entries by symbol name ascending.
5. Numeric formatting uses fixed decimal conversion from integer basis points.
6. No timestamps, random IDs, or host-specific values in rendered content unless explicitly from snapshot.

---

## 2) Database Schema

## 2.1 `mail_rspamd_policies`

```sql
CREATE TABLE mail_rspamd_policies (
  id                           UUID PRIMARY KEY,
  owner_id                     UUID NOT NULL,
  domain_id                    UUID NOT NULL,
  mailbox_id                   UUID NULL,

  enabled                      BOOLEAN NOT NULL DEFAULT TRUE,
  outbound_filtering_enabled   BOOLEAN NOT NULL DEFAULT FALSE,

  action_profile               VARCHAR(16) NOT NULL,

  -- basis points, score = value / 100.00
  reject_score_bp              INTEGER NOT NULL,
  add_header_score_bp          INTEGER NOT NULL,
  greylist_score_bp            INTEGER NOT NULL,

  symbols_override_json        JSONB NOT NULL DEFAULT '{}'::jsonb,

  created_at                   TIMESTAMPTZ NOT NULL,
  updated_at                   TIMESTAMPTZ NOT NULL,
  created_by_actor_id          UUID NOT NULL,
  updated_by_actor_id          UUID NOT NULL,

  CONSTRAINT chk_rspamd_profile
    CHECK (action_profile IN ('strict','balanced','lenient','custom')),
  CONSTRAINT chk_rspamd_reject_bp
    CHECK (reject_score_bp BETWEEN 0 AND 5000),
  CONSTRAINT chk_rspamd_header_bp
    CHECK (add_header_score_bp BETWEEN 0 AND 5000),
  CONSTRAINT chk_rspamd_grey_bp
    CHECK (greylist_score_bp BETWEEN 0 AND 5000),
  CONSTRAINT chk_rspamd_score_order
    CHECK (greylist_score_bp <= add_header_score_bp AND add_header_score_bp <= reject_score_bp),
  CONSTRAINT chk_rspamd_symbols_object
    CHECK (jsonb_typeof(symbols_override_json) = 'object')
);

-- UUIDv7 enforcement (application + DB check helper function optional).
-- Recommended: CHECK (uuid_extract_version(id) = 7) if extension/function exists.

CREATE UNIQUE INDEX uq_rspamd_owner_domain_mailbox
  ON mail_rspamd_policies(owner_id, domain_id, mailbox_id)
  WHERE mailbox_id IS NOT NULL;

CREATE UNIQUE INDEX uq_rspamd_owner_domain_default
  ON mail_rspamd_policies(owner_id, domain_id)
  WHERE mailbox_id IS NULL;

CREATE INDEX ix_rspamd_owner_domain
  ON mail_rspamd_policies(owner_id, domain_id);

CREATE INDEX ix_rspamd_owner_mailbox
  ON mail_rspamd_policies(owner_id, mailbox_id)
  WHERE mailbox_id IS NOT NULL;

CREATE INDEX ix_rspamd_updated_at
  ON mail_rspamd_policies(updated_at DESC);

CREATE INDEX ix_rspamd_symbols_gin
  ON mail_rspamd_policies USING GIN(symbols_override_json jsonb_path_ops);
```

Validation constraints outside SQL:
- `symbols_override_json` key allowlist only (Section 8).
- Maximum number of override keys per policy: 64.
- Maximum total JSON payload size: 16 KiB.

## 2.2 `mail_rspamd_stats_buckets`

```sql
CREATE TABLE mail_rspamd_stats_buckets (
  id                           UUID PRIMARY KEY,
  owner_id                     UUID NOT NULL,
  domain_id                    UUID NULL,
  mailbox_id                   UUID NULL,

  bucket_start                 TIMESTAMPTZ NOT NULL,
  bucket_size_seconds          INTEGER NOT NULL,

  messages_scanned             BIGINT NOT NULL,
  messages_rejected            BIGINT NOT NULL,
  messages_greylisted          BIGINT NOT NULL,
  messages_add_header          BIGINT NOT NULL,

  score_sum_bp                 BIGINT NOT NULL,
  score_p95_bp                 INTEGER NOT NULL,

  created_at                   TIMESTAMPTZ NOT NULL,

  CONSTRAINT chk_rspamd_bucket_size
    CHECK (bucket_size_seconds IN (60,300,3600)),
  CONSTRAINT chk_rspamd_counters_nonneg
    CHECK (
      messages_scanned >= 0 AND
      messages_rejected >= 0 AND
      messages_greylisted >= 0 AND
      messages_add_header >= 0
    ),
  CONSTRAINT chk_rspamd_score_sum_nonneg
    CHECK (score_sum_bp >= 0),
  CONSTRAINT chk_rspamd_score_p95
    CHECK (score_p95_bp BETWEEN 0 AND 5000),
  CONSTRAINT chk_rspamd_scope_relation
    CHECK (mailbox_id IS NULL OR domain_id IS NOT NULL)
);

CREATE UNIQUE INDEX uq_rspamd_stats_rollup
  ON mail_rspamd_stats_buckets(owner_id, domain_id, mailbox_id, bucket_start, bucket_size_seconds);

CREATE INDEX ix_rspamd_stats_owner_time
  ON mail_rspamd_stats_buckets(owner_id, bucket_start DESC);

CREATE INDEX ix_rspamd_stats_domain_time
  ON mail_rspamd_stats_buckets(owner_id, domain_id, bucket_start DESC);

CREATE INDEX ix_rspamd_stats_mailbox_time
  ON mail_rspamd_stats_buckets(owner_id, mailbox_id, bucket_start DESC)
  WHERE mailbox_id IS NOT NULL;
```

Retention policy:
- 60s buckets: 14 days.
- 300s buckets: 90 days.
- 3600s buckets: 400 days.

---

## 3) Doctrine Entity Structures (PHP 8.4 readonly)

```php
<?php

declare(strict_types=1);

namespace App\Mail\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'mail_rspamd_policies')]
final class MailRspamdPolicy
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        public readonly string $id,

        #[ORM\Column(type: 'uuid')]
        public readonly string $ownerId,

        #[ORM\Column(type: 'uuid')]
        public readonly string $domainId,

        #[ORM\Column(type: 'uuid', nullable: true)]
        public readonly ?string $mailboxId,

        #[ORM\Column(type: 'boolean')]
        public readonly bool $enabled,

        #[ORM\Column(type: 'boolean', options: ['default' => false])]
        public readonly bool $outboundFilteringEnabled,

        #[ORM\Column(type: 'string', length: 16)]
        public readonly string $actionProfile,

        #[ORM\Column(type: 'integer')]
        public readonly int $rejectScoreBp,

        #[ORM\Column(type: 'integer')]
        public readonly int $addHeaderScoreBp,

        #[ORM\Column(type: 'integer')]
        public readonly int $greylistScoreBp,

        /** @var array<string,int> */
        #[ORM\Column(type: 'json', options: ['jsonb' => true])]
        public readonly array $symbolsOverride,

        #[ORM\Column(type: 'datetimetz_immutable')]
        public readonly \DateTimeImmutable $createdAt,

        #[ORM\Column(type: 'datetimetz_immutable')]
        public readonly \DateTimeImmutable $updatedAt,

        #[ORM\Column(type: 'uuid')]
        public readonly string $createdByActorId,

        #[ORM\Column(type: 'uuid')]
        public readonly string $updatedByActorId,
    ) {}
}
```

```php
<?php

declare(strict_types=1);

namespace App\Mail\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'mail_rspamd_stats_buckets')]
final class MailRspamdStatsBucket
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        public readonly string $id,

        #[ORM\Column(type: 'uuid')]
        public readonly string $ownerId,

        #[ORM\Column(type: 'uuid', nullable: true)]
        public readonly ?string $domainId,

        #[ORM\Column(type: 'uuid', nullable: true)]
        public readonly ?string $mailboxId,

        #[ORM\Column(type: 'datetimetz_immutable')]
        public readonly \DateTimeImmutable $bucketStart,

        #[ORM\Column(type: 'integer')]
        public readonly int $bucketSizeSeconds,

        #[ORM\Column(type: 'bigint')]
        public readonly string $messagesScanned,

        #[ORM\Column(type: 'bigint')]
        public readonly string $messagesRejected,

        #[ORM\Column(type: 'bigint')]
        public readonly string $messagesGreylisted,

        #[ORM\Column(type: 'bigint')]
        public readonly string $messagesAddHeader,

        #[ORM\Column(type: 'bigint')]
        public readonly string $scoreSumBp,

        #[ORM\Column(type: 'integer')]
        public readonly int $scoreP95Bp,

        #[ORM\Column(type: 'datetimetz_immutable')]
        public readonly \DateTimeImmutable $createdAt,
    ) {}
}
```

Implementation notes:
- Keep entities immutable; updates create new instance via repository save patterns.
- `BIGINT` mapped to string in Doctrine for 32-bit safety.

---

## 4) REST API

All mutating endpoints require header:
- `Idempotency-Key: <opaque 1..128 chars>`
- Reusing same key with identical body returns original response.
- Same key with different body returns `409 IDEMPOTENCY_CONFLICT`.

Error format:
```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "rejectScoreBp must be between 0 and 5000",
    "details": [{"field":"rejectScoreBp","rule":"range","min":0,"max":5000}],
    "correlation_id": "01J..."
  }
}
```

### 4.1 GET `/api/v1/admin/mail/rspamd/overview`

Query params:
- `from` RFC3339 UTC required
- `to` RFC3339 UTC required
- `ownerId` optional (super-admin filter)

Response example:
```json
{
  "from": "2026-11-01T00:00:00Z",
  "to": "2026-11-01T23:59:59Z",
  "totals": {
    "messagesScanned": 1200044,
    "messagesRejected": 4442,
    "messagesGreylisted": 10923,
    "messagesAddHeader": 33211,
    "rejectRateBp": 370,
    "greylistRateBp": 910,
    "p95ScoreBp": 880
  },
  "domains": [
    {
      "domainId": "0196f4a4-fb15-7d45-8fe4-c5ab7a29e8f7",
      "domainName": "example.com",
      "messagesScanned": 88901,
      "rejectRateBp": 415,
      "p95ScoreBp": 920
    }
  ]
}
```

### 4.2 GET `/api/v1/admin/mail/rspamd/stats`

Query params:
- `from` RFC3339 UTC required
- `to` RFC3339 UTC required
- `bucket` in `{60,300,3600}` required
- `domainId` optional UUID
- `mailboxId` optional UUID

Response example:
```json
{
  "bucketSizeSeconds": 300,
  "series": [
    {
      "bucketStart": "2026-11-01T10:00:00Z",
      "messagesScanned": 820,
      "messagesRejected": 4,
      "messagesGreylisted": 11,
      "messagesAddHeader": 18,
      "avgScoreBp": 302,
      "p95ScoreBp": 890
    }
  ]
}
```

### 4.3 PUT `/api/v1/admin/mail/rspamd/policy/{domainId}`

AuthZ:
- Admin only.
- Must enforce owner scope when not super-admin.

Request example:
```json
{
  "mailboxId": null,
  "enabled": true,
  "outboundFilteringEnabled": true,
  "actionProfile": "balanced",
  "rejectScoreBp": 1500,
  "addHeaderScoreBp": 600,
  "greylistScoreBp": 400,
  "symbolsOverride": {
    "HFILTER_HOSTNAME_UNKNOWN": 50,
    "R_MISSING_CHARSET": 25
  }
}
```

Successful response (`200`):
```json
{
  "policyId": "0196f4a7-5ac3-7c18-bcb2-046f0e7ac54a",
  "ownerId": "0196f4a4-5250-7267-9560-dae92d3d06cf",
  "domainId": "0196f4a4-fb15-7d45-8fe4-c5ab7a29e8f7",
  "mailboxId": null,
  "version": 12,
  "effective": {
    "rejectScoreBp": 1500,
    "addHeaderScoreBp": 600,
    "greylistScoreBp": 400
  },
  "updatedAt": "2026-11-01T12:00:00Z",
  "correlationId": "01J..."
}
```

Validation rules:
- `domainId` path: valid UUID v7.
- `mailboxId`: null or UUID v7 belonging to same `owner_id` and `domain_id`.
- Score ordering: `greylist <= add_header <= reject`.
- `symbolsOverride` keys in allowlist, values integer `[-1000, 1000]` bp delta.
- Max 64 override entries.

### 4.4 PUT `/api/v1/customer/mail/rspamd/policy/{domainId}`

Same payload schema as admin endpoint but with stricter authorization:
- `owner_id` bound to authenticated customer tenant.
- Customer cannot modify global defaults.
- Optional policy capability flags may lock `actionProfile` to approved presets.

Idempotency behavior:
- Missing `Idempotency-Key` -> `400 IDEMPOTENCY_KEY_REQUIRED`.
- Duplicate request (same key + same body hash + same tenant + same path) -> return original status/body.

Audit write on every success:
- `actor_id`, `correlation_id`, `before_hash`, `after_hash`, `event_type=mail.rspamd.policy.updated`.

---

## 5) Agent Contract

## 5.1 Snapshot Requirements

`/v1/agent/mail/snapshot` payload MUST include:
- `rspamd.globalDefaultPolicy`
- `rspamd.domainPolicies[]` (effective domain-level)
- `rspamd.mailboxPolicies[]` (effective mailbox-level)
- `rspamd.allowlistedSymbols[]`
- `rspamd.outboundFilteringEnabledByDomain[]`
- `snapshotVersion` monotonically increasing integer
- `generatedAt` UTC RFC3339

All arrays sorted before serialization by deterministic keys defined in §1.5.

## 5.2 Render Targets

Agent renders and atomically swaps:
1. `/etc/rspamd/local.d/actions.conf`
2. `/etc/rspamd/local.d/multimap.conf`
3. `/etc/rspamd/maps.d/*.map` (one map file per logical list)

`worker-proxy.inc` is outside this task scope unless deployment enables custom socket parameters.

### 5.3 Stable Sorting Rules

Inside generated files:
- Sections in fixed order: global, domain, mailbox.
- Domain blocks sorted by `domain_name`, then UUID.
- Mailbox blocks sorted by fully-qualified mailbox address, then UUID.
- Map entries sorted lexicographically bytewise, deduplicated exact-match.

### 5.4 Numeric/Float Formatting Rules

Internal representation remains integer basis points.

When Rspamd syntax requires decimal score:
- Format as `bp / 100` with exactly 2 fractional digits.
- Decimal separator is dot (`.`).
- No scientific notation.
- Examples:
  - `1500 -> 15.00`
  - `25 -> 0.25`
  - `0 -> 0.00`

### 5.5 Newline & File Encoding Rules

- UTF-8 without BOM.
- LF (`\n`) only.
- Ensure single trailing newline at end-of-file.
- No trailing whitespace.

### 5.6 Configtest + Reload Flow

Strict flow:
1. Render into staging dir `/etc/rspamd/.staged/<snapshotVersion>/...`.
2. Run fixed command:
   - `exec.CommandContext(ctx, "/usr/bin/rspamadm", "configtest")`
3. If configtest passes: atomic rename/symlink switch to active path.
4. Reload fixed command:
   - `exec.CommandContext(ctx, "/bin/systemctl", "reload", "rspamd")`
5. Post-reload health probe with fixed timeout (e.g., local worker ping).

Failure handling:
- If configtest fails: do not activate.
- If reload fails after activation: immediate rollback to previous active snapshot and second reload attempt.
- Emit structured error event with `snapshotVersion`, `stderrHash`, and correlation ID.

### 5.7 Rollback Strategy

Maintain two retained snapshots on disk:
- `active`
- `previous`

Rollback algorithm:
1. Identify `previous` symlink target.
2. Atomically repoint `active` to `previous`.
3. Reload service.
4. Mark apply result `ROLLED_BACK` and report to control plane.

---

## 6) Job Types

### 6.1 `mail.applyConfig`

Purpose:
- Apply complete mail stack config package (Postfix, Dovecot, OpenDKIM, optional Rspamd).

Payload (logical):
- `jobId` UUID v7
- `ownerId` nullable for global
- `nodeId`
- `snapshotVersion`
- `components[]` including `rspamd`
- `correlationId`

Idempotency key for internal dispatch:
- `nodeId:snapshotVersion:componentsHash`

### 6.2 `mail.applyRspamdPolicy`

Purpose:
- Fast-path policy-only apply for Rspamd without full stack churn.

Payload:
- `jobId` UUID v7
- `ownerId`
- `domainId`
- `mailboxId` nullable
- `snapshotVersion`
- `reason` (`admin_update|customer_update|system_reconcile`)
- `correlationId`

Execution semantics:
- Coalesce queue entries by `(nodeId, snapshotVersion)`.
- Exactly-once effect via compare-and-set on `last_applied_snapshot_version`.

---

## 7) Telemetry Model

## 7.1 Counters

Per bucket and scope:
- `messages_scanned`
- `messages_rejected`
- `messages_greylisted`
- `messages_add_header`

Derived rates in basis points (computed in query layer):
- `reject_rate_bp = messages_rejected * 10000 / NULLIF(messages_scanned,0)`
- `greylist_rate_bp = messages_greylisted * 10000 / NULLIF(messages_scanned,0)`

## 7.2 p95 Score Distribution

Agent computes p95 over message scores per bucket.
- Input score unit: basis points.
- Deterministic percentile algorithm: nearest-rank with stable sorted integer list.
- Persist as `score_p95_bp` integer.

## 7.3 Batch Ingestion Endpoint

`POST /api/v1/agent/mail/rspamd/stats/batch`

Headers:
- `Authorization: Bearer <agent-token>`
- `X-Correlation-Id`
- `Idempotency-Key`

Request example:
```json
{
  "nodeId": "node-eu-1",
  "generatedAt": "2026-11-01T12:05:00Z",
  "buckets": [
    {
      "ownerId": "0196f4a4-5250-7267-9560-dae92d3d06cf",
      "domainId": "0196f4a4-fb15-7d45-8fe4-c5ab7a29e8f7",
      "mailboxId": null,
      "bucketStart": "2026-11-01T12:00:00Z",
      "bucketSizeSeconds": 300,
      "messagesScanned": 820,
      "messagesRejected": 4,
      "messagesGreylisted": 11,
      "messagesAddHeader": 18,
      "scoreSumBp": 248000,
      "scoreP95Bp": 890
    }
  ]
}
```

Response:
```json
{
  "accepted": 1,
  "deduplicated": 0,
  "correlationId": "01J..."
}
```

Dedup key:
- `(owner_id, domain_id, mailbox_id, bucket_start, bucket_size_seconds)`.

---

## 8) Security Considerations

1. **Symbol override allowlist**
   - Control plane keeps static allowlist of overrideable symbols.
   - Reject any unknown key.
   - Allowlist changes versioned and audited.

2. **Max cardinality limits**
   - Max 64 override symbols per policy.
   - Max 10,000 distinct mailbox policy rows per owner per domain.
   - Max 2,000 map entries per generated map file.
   - Hard fail snapshot generation if limits exceeded (deterministic error code).

3. **Tenant isolation**
   - Every select/update/delete includes `owner_id` predicate.
   - Domain/mailbox FK checks enforce owner coherence in application layer and DB constraints where possible.

4. **Auditability**
   - Mutations create append-only audit records with hash diff.
   - Correlation ID propagated API -> Messenger -> agent logs.

5. **Command execution safety**
   - Fixed executable path and args only.
   - Timeouts mandatory.
   - No interpolated shell strings.

---

## 9) Acceptance Criteria

1. Two identical snapshots produce byte-identical `actions.conf`, `multimap.conf`, and `*.map` outputs.
2. Policy resolution always follows mailbox > domain > global with deterministic tie-breakers.
3. All mutating policy endpoints reject missing `Idempotency-Key`.
4. Replayed idempotency request returns original response body and status.
5. Agent never activates invalid config (`rspamadm configtest` gate).
6. Reload failure triggers automatic rollback and reports failure state.
7. Stats ingestion is deduplicated by bucket key and idempotency key.
8. All persisted scores are integer basis points, no floating DB columns.
9. All timestamps persisted in UTC.
10. Every policy mutation writes an audit record with before/after hash.

---

## 10) Edge Cases

1. **Mailbox deleted but mailbox policy exists**
   - Policy excluded from effective snapshot, marked orphaned for cleanup job.

2. **Domain suspended**
   - Effective policy generation returns disabled enforcement for that domain.

3. **Outbound filtering toggled while apply job in flight**
   - Snapshot version ordering ensures older snapshot cannot overwrite newer version.

4. **Huge telemetry burst**
   - Batch endpoint enforces max 5,000 buckets/request; overflow returns partial failure with indices.

5. **Invalid symbol override values**
   - Reject request with field-level validation details.

6. **Cross-tenant UUID probe attempts**
   - Return `404` (not `403`) to avoid tenant existence leaks.

7. **Clock skew on agent telemetry timestamps**
   - Server clamps future buckets to now + 120s tolerance; else rejects.

---

## 11) Determinism Guarantees (Formalized)

Determinism function:
- `F(snapshot_json_canonical) -> rendered_files_bytes`

Guarantees:
1. Canonical JSON serializer with sorted object keys and stable array ordering.
2. Renderer has no external entropy source (time/random/hostname).
3. Numeric conversion from bp -> decimal string uses fixed 2-decimal algorithm.
4. File writer normalizes newline/encoding deterministically.
5. Map dedup + sort is stable and locale-independent (bytewise compare).
6. Staging-to-active activation is atomic; partial writes cannot become active.
7. Validation gate (`configtest`) is deterministic for identical binary + config inputs.

Verification tests required:
- Golden-file test: same snapshot rendered twice => SHA-256 equal for every target file.
- Permutation test: input rows shuffled in DB query result => identical output files.
- Replay test: repeated `mail.applyRspamdPolicy` same snapshot => no file changes, no reload.

