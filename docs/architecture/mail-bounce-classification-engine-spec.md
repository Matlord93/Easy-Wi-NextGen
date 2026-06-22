# Bounce Classification Engine Specification (Production-Grade, Multi-Tenant)

## 0. Scope, Invariants, and Non-Negotiable Constraints

This specification defines an implementation-ready, deterministic Bounce Classification Engine for a multi-tenant mail hosting control plane.

Hard constraints enforced end-to-end:
1. UUID v7 for all primary keys.
2. Deterministic normalization outcomes for identical input payloads + ruleset version.
3. Multi-tenant isolation via `owner_id` constraints in every read/write path.
4. Agent emits raw bounce events; panel performs central normalization/classification.
5. Idempotent ingestion of bounce events.
6. All timestamps in UTC (`TIMESTAMPTZ`).

---

## 1) Architecture

## 1.1 Processing Topology

**Execution Plane (Go 1.22 Agent):**
- Parses mail logs/DSN artifacts minimally.
- Emits normalized transport envelope fields + raw diagnostic text to control plane.
- No policy decisions or tenant-specific classification in agent.

**Control Plane (Symfony 8 + PostgreSQL 16):**
- Ingests agent events idempotently.
- Performs deterministic DSN + SMTP enhanced status normalization.
- Applies centralized reason taxonomy and provider-specific override map.
- Stores final classification in `mail_bounce_events`.

## 1.2 DSN Parsing Model

Input sources:
- RFC 3464 delivery status notifications (message/delivery-status).
- Postfix bounce/defer log events where DSN payload is unavailable.

Parser extraction precedence:
1. `Final-Recipient`
2. `Original-Recipient` fallback
3. envelope recipient from agent event

Status extraction precedence:
1. `Status` (Enhanced Status Code, e.g., `5.1.1`)
2. `Diagnostic-Code` SMTP triple (e.g., `550 5.1.1`)
3. raw SMTP reply fallback

All parsed fields are canonicalized (trim, lowercase where applicable, whitespace collapse).

## 1.3 SMTP Enhanced Status Mapping

Primary class mapping by first digit:
- `2.x.x` => delivered (not bounce, ignored by bounce table)
- `4.x.x` => soft bounce
- `5.x.x` => hard bounce

Secondary category mapping by `x.y.z` pattern:
- `x.1.x` addressing issues
- `x.2.x` mailbox state
- `x.4.x` network/routing
- `x.7.x` policy/security/auth

Fallback precedence:
1. Enhanced code from DSN.
2. Enhanced code parsed from SMTP text.
3. SMTP code class (`4xx`, `5xx`).
4. Unknown classification (`unknown.soft` / `unknown.hard`) by smtp class.

## 1.4 Deterministic Reason Taxonomy

Canonical taxonomy fields:
- `bounce_class`: `hard|soft`
- `reason_code` (stable enum slug)
- `reason_group`: `addressing|mailbox|policy|content|routing|reputation|quota|dns|tls|unknown`
- `confidence_bp` integer basis points (0..10000)

Deterministic reason resolution order:
1. Provider-specific pattern matches (highest priority table rows).
2. Global pattern matches.
3. Enhanced-status code map.
4. SMTP-class fallback map.
5. `unknown.*` fallback.

Tie-break deterministic ordering:
- higher `priority` wins,
- then longer `pattern` length wins,
- then lexicographically smaller `id` (UUID string) wins.

---

## 2) Database Schema

## 2.1 `mail_bounce_events`

```sql
CREATE TABLE mail_bounce_events (
  id                           UUID PRIMARY KEY,
  owner_id                     UUID NOT NULL,
  domain_id                    UUID NOT NULL,
  mailbox_id                   UUID NULL,

  node_id                      VARCHAR(128) NOT NULL,
  source_event_id              VARCHAR(128) NOT NULL,
  source_event_hash            CHAR(64) NOT NULL,

  message_id                   VARCHAR(998) NULL,
  queue_id                     VARCHAR(64) NULL,
  recipient                    VARCHAR(320) NOT NULL,

  smtp_code                    SMALLINT NULL,
  enhanced_status              VARCHAR(16) NULL,
  diagnostic_text              TEXT NULL,

  provider                     VARCHAR(64) NULL,
  mx_host                      VARCHAR(255) NULL,

  bounce_class                 VARCHAR(8) NOT NULL,
  reason_code                  VARCHAR(64) NOT NULL,
  reason_group                 VARCHAR(32) NOT NULL,
  confidence_bp                INTEGER NOT NULL,

  ruleset_version              INTEGER NOT NULL,
  occurred_at                  TIMESTAMPTZ NOT NULL,
  ingested_at                  TIMESTAMPTZ NOT NULL,

  raw_payload_json             JSONB NOT NULL,

  CONSTRAINT chk_bounce_class
    CHECK (bounce_class IN ('hard','soft')),
  CONSTRAINT chk_reason_group
    CHECK (reason_group IN ('addressing','mailbox','policy','content','routing','reputation','quota','dns','tls','unknown')),
  CONSTRAINT chk_confidence_bp
    CHECK (confidence_bp BETWEEN 0 AND 10000),
  CONSTRAINT chk_source_hash_hex
    CHECK (source_event_hash ~ '^[0-9a-f]{64}$'),
  CONSTRAINT chk_smtp_code
    CHECK (smtp_code IS NULL OR smtp_code BETWEEN 100 AND 599),
  CONSTRAINT chk_raw_payload_object
    CHECK (jsonb_typeof(raw_payload_json) = 'object')
);

CREATE UNIQUE INDEX uq_bounce_event_dedupe
  ON mail_bounce_events(owner_id, node_id, source_event_id);

CREATE UNIQUE INDEX uq_bounce_event_hash_dedupe
  ON mail_bounce_events(owner_id, source_event_hash);

CREATE INDEX ix_bounce_owner_domain_time
  ON mail_bounce_events(owner_id, domain_id, occurred_at DESC);

CREATE INDEX ix_bounce_owner_reason_time
  ON mail_bounce_events(owner_id, reason_code, occurred_at DESC);

CREATE INDEX ix_bounce_owner_mailbox_time
  ON mail_bounce_events(owner_id, mailbox_id, occurred_at DESC)
  WHERE mailbox_id IS NOT NULL;

CREATE INDEX ix_bounce_owner_provider_time
  ON mail_bounce_events(owner_id, provider, occurred_at DESC);
```

Notes:
- Two dedupe keys support both explicit source IDs and content-hash idempotency.
- `ruleset_version` freezes classification reproducibility.

## 2.2 `mail_bounce_reason_map`

```sql
CREATE TABLE mail_bounce_reason_map (
  id                           UUID PRIMARY KEY,
  owner_id                     UUID NULL,

  provider                     VARCHAR(64) NULL,
  pattern_type                 VARCHAR(16) NOT NULL,
  pattern                      TEXT NOT NULL,

  enhanced_status_match        VARCHAR(16) NULL,
  smtp_code_match              SMALLINT NULL,

  reason_code                  VARCHAR(64) NOT NULL,
  reason_group                 VARCHAR(32) NOT NULL,
  bounce_class                 VARCHAR(8) NOT NULL,
  confidence_bp                INTEGER NOT NULL,

  priority                     INTEGER NOT NULL,
  is_active                    BOOLEAN NOT NULL DEFAULT TRUE,

  valid_from                   TIMESTAMPTZ NOT NULL,
  valid_to                     TIMESTAMPTZ NULL,

  created_at                   TIMESTAMPTZ NOT NULL,
  updated_at                   TIMESTAMPTZ NOT NULL,
  created_by_actor_id          UUID NOT NULL,
  updated_by_actor_id          UUID NOT NULL,

  CONSTRAINT chk_reason_map_pattern_type
    CHECK (pattern_type IN ('substring','regex','exact')),
  CONSTRAINT chk_reason_map_reason_group
    CHECK (reason_group IN ('addressing','mailbox','policy','content','routing','reputation','quota','dns','tls','unknown')),
  CONSTRAINT chk_reason_map_class
    CHECK (bounce_class IN ('hard','soft')),
  CONSTRAINT chk_reason_map_priority
    CHECK (priority BETWEEN 1 AND 100000),
  CONSTRAINT chk_reason_map_confidence
    CHECK (confidence_bp BETWEEN 0 AND 10000),
  CONSTRAINT chk_reason_map_smtp_code
    CHECK (smtp_code_match IS NULL OR smtp_code_match BETWEEN 100 AND 599),
  CONSTRAINT chk_reason_map_window
    CHECK (valid_to IS NULL OR valid_to > valid_from)
);

CREATE INDEX ix_reason_map_active_priority
  ON mail_bounce_reason_map(is_active, priority DESC, id);

CREATE INDEX ix_reason_map_provider_active
  ON mail_bounce_reason_map(provider, is_active, priority DESC);

CREATE INDEX ix_reason_map_owner_provider
  ON mail_bounce_reason_map(owner_id, provider, is_active, priority DESC);

CREATE UNIQUE INDEX uq_reason_map_deterministic
  ON mail_bounce_reason_map(
    COALESCE(owner_id::text, ''),
    COALESCE(provider, ''),
    pattern_type,
    md5(pattern),
    COALESCE(enhanced_status_match, ''),
    COALESCE(smtp_code_match::text, ''),
    reason_code,
    valid_from
  );
```

Priority-based matching semantics:
- Rules queried in deterministic order:
  1) tenant+provider active rules,
  2) global provider rules,
  3) tenant global rules,
  4) platform global rules.
- Within each scope: `priority DESC`, `length(pattern) DESC`, `id ASC`.

Provider-specific overrides:
- `provider` may be MX family slug (`google`, `microsoft`, `yahoo`, etc.).
- Provider rule outranks non-provider rule at same scope.

Retention:
- `mail_bounce_events`: 400 days online.
- `mail_bounce_reason_map`: no auto-delete (versioned rule history).

---

## 3) Doctrine Entities (PHP 8.4 readonly)

```php
<?php

declare(strict_types=1);

namespace App\Mail\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'mail_bounce_events')]
final class MailBounceEvent
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

        #[ORM\Column(type: 'string', length: 128)]
        public readonly string $nodeId,

        #[ORM\Column(type: 'string', length: 128)]
        public readonly string $sourceEventId,

        #[ORM\Column(type: 'string', length: 64)]
        public readonly string $sourceEventHash,

        #[ORM\Column(type: 'string', length: 998, nullable: true)]
        public readonly ?string $messageId,

        #[ORM\Column(type: 'string', length: 64, nullable: true)]
        public readonly ?string $queueId,

        #[ORM\Column(type: 'string', length: 320)]
        public readonly string $recipient,

        #[ORM\Column(type: 'smallint', nullable: true)]
        public readonly ?int $smtpCode,

        #[ORM\Column(type: 'string', length: 16, nullable: true)]
        public readonly ?string $enhancedStatus,

        #[ORM\Column(type: 'text', nullable: true)]
        public readonly ?string $diagnosticText,

        #[ORM\Column(type: 'string', length: 64, nullable: true)]
        public readonly ?string $provider,

        #[ORM\Column(type: 'string', length: 255, nullable: true)]
        public readonly ?string $mxHost,

        #[ORM\Column(type: 'string', length: 8)]
        public readonly string $bounceClass,

        #[ORM\Column(type: 'string', length: 64)]
        public readonly string $reasonCode,

        #[ORM\Column(type: 'string', length: 32)]
        public readonly string $reasonGroup,

        #[ORM\Column(type: 'integer')]
        public readonly int $confidenceBp,

        #[ORM\Column(type: 'integer')]
        public readonly int $rulesetVersion,

        #[ORM\Column(type: 'datetimetz_immutable')]
        public readonly \DateTimeImmutable $occurredAt,

        #[ORM\Column(type: 'datetimetz_immutable')]
        public readonly \DateTimeImmutable $ingestedAt,

        /** @var array<string,mixed> */
        #[ORM\Column(type: 'json', options: ['jsonb' => true])]
        public readonly array $rawPayload,
    ) {}
}
```

```php
<?php

declare(strict_types=1);

namespace App\Mail\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'mail_bounce_reason_map')]
final class MailBounceReasonMap
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        public readonly string $id,

        #[ORM\Column(type: 'uuid', nullable: true)]
        public readonly ?string $ownerId,

        #[ORM\Column(type: 'string', length: 64, nullable: true)]
        public readonly ?string $provider,

        #[ORM\Column(type: 'string', length: 16)]
        public readonly string $patternType,

        #[ORM\Column(type: 'text')]
        public readonly string $pattern,

        #[ORM\Column(type: 'string', length: 16, nullable: true)]
        public readonly ?string $enhancedStatusMatch,

        #[ORM\Column(type: 'smallint', nullable: true)]
        public readonly ?int $smtpCodeMatch,

        #[ORM\Column(type: 'string', length: 64)]
        public readonly string $reasonCode,

        #[ORM\Column(type: 'string', length: 32)]
        public readonly string $reasonGroup,

        #[ORM\Column(type: 'string', length: 8)]
        public readonly string $bounceClass,

        #[ORM\Column(type: 'integer')]
        public readonly int $confidenceBp,

        #[ORM\Column(type: 'integer')]
        public readonly int $priority,

        #[ORM\Column(type: 'boolean')]
        public readonly bool $isActive,

        #[ORM\Column(type: 'datetimetz_immutable')]
        public readonly \DateTimeImmutable $validFrom,

        #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
        public readonly ?\DateTimeImmutable $validTo,

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

---

## 4) REST API

All responses include `correlationId`.
Query boundaries are UTC.
Cross-tenant access returns `404` (not `403`).

## 4.1 Admin: `GET /api/v1/admin/mail/bounces`

Query params:
- `ownerId` optional UUID (super-admin)
- `domainId` optional UUID
- `mailboxId` optional UUID
- `bounceClass` optional (`hard|soft`)
- `reasonCode` optional string
- `provider` optional string
- `from` required RFC3339
- `to` required RFC3339
- `page` default 1
- `pageSize` default 50 max 200

Response example:
```json
{
  "items": [
    {
      "id": "01970316-a6ef-7d1a-a31a-00f06cb9e278",
      "ownerId": "01970316-2b1b-73fd-9b42-67ca48009bc8",
      "domainId": "01970316-7eef-7dbe-8af0-c5574b700485",
      "mailboxId": "01970316-c1cf-718f-b740-b4d163b74f58",
      "recipient": "user@example.com",
      "smtpCode": 550,
      "enhancedStatus": "5.1.1",
      "provider": "google",
      "bounceClass": "hard",
      "reasonCode": "mailbox_not_found",
      "reasonGroup": "addressing",
      "confidenceBp": 9800,
      "occurredAt": "2026-11-15T10:11:12Z"
    }
  ],
  "pagination": {
    "page": 1,
    "pageSize": 50,
    "total": 1
  },
  "correlationId": "01K..."
}
```

## 4.2 Admin: `GET /api/v1/admin/mail/bounces/reasons`

Query params:
- `ownerId` optional UUID (super-admin)
- `domainId` optional UUID
- `from` required RFC3339
- `to` required RFC3339
- `limit` default 20 max 100

Response example:
```json
{
  "from": "2026-11-01T00:00:00Z",
  "to": "2026-11-30T23:59:59Z",
  "reasons": [
    {
      "reasonCode": "mailbox_not_found",
      "reasonGroup": "addressing",
      "bounceClass": "hard",
      "count": 8421,
      "shareBp": 3920
    },
    {
      "reasonCode": "recipient_rate_limited",
      "reasonGroup": "policy",
      "bounceClass": "soft",
      "count": 5210,
      "shareBp": 2424
    }
  ],
  "correlationId": "01K..."
}
```

## 4.3 Admin CRUD for mapping table

### `GET /api/v1/admin/mail/bounces/reason-map`
- List map rules with filters: `ownerId`, `provider`, `isActive`, `page`, `pageSize`.

### `POST /api/v1/admin/mail/bounces/reason-map`
Headers:
- `Idempotency-Key` required.

Request example:
```json
{
  "ownerId": null,
  "provider": "google",
  "patternType": "regex",
  "pattern": "(?i)user unknown|mailbox unavailable",
  "enhancedStatusMatch": "5.1.1",
  "smtpCodeMatch": 550,
  "reasonCode": "mailbox_not_found",
  "reasonGroup": "addressing",
  "bounceClass": "hard",
  "confidenceBp": 9800,
  "priority": 9000,
  "validFrom": "2026-11-01T00:00:00Z",
  "validTo": null,
  "isActive": true
}
```

Response (`201`):
```json
{
  "id": "01970318-edfa-72a0-9b8d-fc2268a45e87",
  "version": 1,
  "correlationId": "01K..."
}
```

### `PUT /api/v1/admin/mail/bounces/reason-map/{id}`
- Requires `Idempotency-Key`.
- Full replace semantics.

### `PATCH /api/v1/admin/mail/bounces/reason-map/{id}`
- Requires `Idempotency-Key`.
- Partial update for `isActive`, `priority`, validity window.

### `DELETE /api/v1/admin/mail/bounces/reason-map/{id}`
- Soft-delete only (`isActive=false`, set `validTo=now_utc`).
- Requires `Idempotency-Key`.

Validation rules (all mutating endpoints):
- UUID v7 format for IDs.
- `pattern` max length 2048 chars.
- regex patterns precompiled with timeout and complexity guard.
- `priority` integer 1..100000.
- `confidenceBp` integer 0..10000.
- `validTo > validFrom` when present.

Audit requirements:
- Every CRUD mutation writes audit event with `actor_id`, `correlation_id`, `before_hash`, `after_hash`.

---

## 5) Agent Event Schema and Ingestion

## 5.1 Agent Event Schema (logical JSON)

```json
{
  "eventId": "evt-6b7d2d1b-2f0a-4fa4-b65f-3bb14dfc56f9",
  "eventTime": "2026-11-15T10:11:12Z",
  "nodeId": "node-eu-1",
  "ownerId": "01970316-2b1b-73fd-9b42-67ca48009bc8",
  "domainId": "01970316-7eef-7dbe-8af0-c5574b700485",
  "mailboxId": "01970316-c1cf-718f-b740-b4d163b74f58",
  "messageId": "<abc123@example.com>",
  "queueId": "A1B2C3D4E5",
  "recipient": "user@example.com",
  "smtpCode": 550,
  "enhancedStatus": "5.1.1",
  "diagnosticText": "550-5.1.1 The email account that you tried to reach does not exist.",
  "provider": "google",
  "mxHost": "gmail-smtp-in.l.google.com",
  "dsnFields": {
    "finalRecipient": "rfc822;user@example.com",
    "status": "5.1.1",
    "diagnosticCode": "smtp;550 5.1.1 User unknown"
  }
}
```

Schema constraints:
- Max payload size: 32 KiB per event.
- `diagnosticText` max 4096 chars.
- `recipient` RFC 5321 mailbox format validation.
- `enhancedStatus` regex `^[245]\.\d{1,3}\.\d{1,3}$` if present.

## 5.2 Batch Ingest Endpoint

`POST /api/v1/agent/mail/bounces/batch`

Headers:
- `Authorization: Bearer <agent-token>`
- `Idempotency-Key` required
- `X-Correlation-Id` optional (server generates if absent)

Request:
```json
{
  "nodeId": "node-eu-1",
  "generatedAt": "2026-11-15T10:12:00Z",
  "events": [
    {
      "eventId": "evt-6b7d2d1b-2f0a-4fa4-b65f-3bb14dfc56f9",
      "eventTime": "2026-11-15T10:11:12Z",
      "ownerId": "01970316-2b1b-73fd-9b42-67ca48009bc8",
      "domainId": "01970316-7eef-7dbe-8af0-c5574b700485",
      "mailboxId": "01970316-c1cf-718f-b740-b4d163b74f58",
      "recipient": "user@example.com",
      "smtpCode": 550,
      "enhancedStatus": "5.1.1",
      "diagnosticText": "550 5.1.1 user unknown",
      "provider": "google"
    }
  ]
}
```

Response:
```json
{
  "accepted": 1,
  "deduplicated": 0,
  "failed": 0,
  "errors": [],
  "correlationId": "01K..."
}
```

Idempotency model:
- Request-level: `Idempotency-Key` dedupes full batch replay.
- Event-level: unique constraints on `(owner_id,node_id,source_event_id)` and `(owner_id,source_event_hash)` prevent duplicates.
- Replayed accepted events return as `deduplicated` without reclassification drift.

Normalization flow per event:
1. Validate payload shape and size.
2. Canonicalize fields (trim, lowercase provider, normalize status format).
3. Compute `source_event_hash = sha256(canonical_event_json)`.
4. Insert-or-ignore event staging.
5. Apply classifier with active ruleset version.
6. Persist classified `mail_bounce_events` row.

---

## 6) Security

1. **Input validation**
   - Strict JSON schema validation.
   - Reject unknown top-level fields (or capture under quarantined metadata key if policy permits).
   - Reject invalid UTF-8.

2. **Payload size limits**
   - Max 1,000 events per batch.
   - Max 32 KiB/event.
   - Max 4 MiB batch body.

3. **Regex safety**
   - RE2-compatible engine or PCRE with match timeout and backtracking limits.
   - Pre-compile and cache active patterns by ruleset version.

4. **Tenant isolation**
   - Authenticated node token mapped to allowed `owner_id` scope.
   - Domain/mailbox ownership checks before insertion.

5. **Injection resistance**
   - Pattern definitions treated as data, never executed in shell.
   - All SQL parameterized.

6. **Auditability**
   - Mapping-table mutations and ingestion failures are auditable events.

---

## 7) Acceptance Criteria

1. Identical input event + same ruleset version always yields identical (`bounce_class`, `reason_code`, `reason_group`, `confidence_bp`).
2. Duplicate events do not create extra DB rows.
3. Provider-specific rules override global rules deterministically.
4. Priority and tie-break ordering are stable and testable.
5. CRUD mapping operations require `Idempotency-Key` and are audited.
6. Invalid payloads exceeding limits are rejected with deterministic error code.
7. Multi-tenant boundary violations are rejected and never leak cross-tenant data.
8. Reason analytics endpoint returns stable share basis-points summing to 10000 for non-zero totals.

---

## 8) Edge Cases

1. **No enhanced status, only SMTP 550 text**
   - classify via smtp class + pattern map.

2. **Contradictory codes (`smtp=450`, enhanced=`5.1.1`)**
   - enhanced status takes precedence; emit warning flag in metadata.

3. **Multiple DSN recipients in one report**
   - split into one event per recipient deterministically ordered by recipient string.

4. **Unknown provider**
   - skip provider overrides; use global rules.

5. **Regex pattern error introduced by admin**
   - reject mutation at write-time via compile validation.

6. **Ruleset update during batch ingest**
   - batch pins `ruleset_version` at ingest start; no mid-batch drift.

7. **Mailbox deleted before ingest commit**
   - keep event with `mailbox_id = NULL` if domain still valid; retain recipient string.

---

## 9) Determinism Guarantees

Formal function:
- `H(canonical_event_json, ruleset_version) -> classification_tuple`

Determinism controls:
1. Canonical JSON serialization for hashing (`sorted keys`, normalized scalars).
2. Stable rule query ordering and tie-breakers.
3. Integer-only confidence representation (`basis points`).
4. Ruleset version pinning per event.
5. No dependence on wall-clock except persisted UTC timestamps.

Required verification tests:
- Golden tests for known DSN samples.
- Permutation test for rules with same priority but different IDs.
- Replay test for duplicate batch submissions.
- Cross-tenant negative tests for access and insert paths.

