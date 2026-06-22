# DMARC RUA Report Ingestion & Aggregation Specification (Implementation-Ready)

## 0. Scope, Constraints, and Invariants

This specification defines the production design for DMARC aggregate report (RUA) ingestion, normalization, storage, and deterministic daily aggregation for a multi-tenant mail hosting control plane.

Hard invariants:
1. PostgreSQL 16 is the only source of truth.
2. Every processing step is deterministic from persisted inputs.
3. UUID v7 is required for all new primary keys.
4. All timestamps are UTC (`TIMESTAMPTZ`).
5. Every tenant-bound query is constrained by `owner_id`.
6. Mutating APIs and ingestion writes enforce idempotency.
7. Raw payloads are encrypted at rest.
8. Duplicate detection uses `report_id + source_file_hash`.

---

## 1) Architecture

## 1.1 Ingestion Mode Decision: Mailbox Ingestion (Primary) + Webhook (Controlled Secondary)

### Decision
Use **mailbox ingestion as the authoritative path** and support **webhook ingestion as an optional secondary channel** for providers that can post validated RUA payloads directly.

### Justification
- DMARC RUA is naturally email-based and widely delivered as MIME attachments (zip/gzip/xml); mailbox ingestion aligns with protocol-native behavior.
- Mailbox flow allows deterministic replay from retained raw messages and strict chain-of-custody auditing.
- Webhook support is valuable for enterprise providers that centralize parsing, but should be normalized into the exact same raw+parse pipeline to preserve deterministic behavior.

### Ingestion Endpoints
- Primary: Agent picks up messages from dedicated mailbox(es) and posts to panel internal endpoint.
- Secondary: Panel accepts signed webhook payloads and stores raw artifact in the same `mail_dmarc_reports_raw` format.

## 1.2 Logical Pipeline

1. **Acquire** raw report artifact (`.xml`, `.xml.gz`, `.zip` containing xml).
2. **Hash** raw bytes (`source_file_hash = SHA-256(hex)`).
3. **Extract metadata** (`report_id`, `org_name`, `date_range`, `header_from`).
4. **Deduplicate** on `(owner_id, report_id, source_file_hash)`.
5. **Persist raw encrypted payload** in `mail_dmarc_reports_raw`.
6. **Parse XML safely** into normalized row records in `mail_dmarc_report_rows`.
7. **Aggregate** into deterministic daily rollups in `mail_dmarc_daily_agg`.
8. **Emit audit and metrics** with `correlation_id`.

## 1.3 Worker Model

Queues (Symfony Messenger):
- `mail.dmarc.ingest_raw` (I/O + dedupe + raw persist)
- `mail.dmarc.parse_report` (XML parse + row writes)
- `mail.dmarc.aggregate_daily` (rollups)
- `mail.dmarc.recompute_day` (deterministic recompute)

Execution model:
- `ingest_raw` is lightweight and idempotent.
- `parse_report` processes one report at a time under transaction boundaries.
- `aggregate_daily` can batch by `(owner_id, domain_id, day)` partitions.

## 1.4 Deterministic Recompute Capability

Provide administrative recompute command/API to rebuild `mail_dmarc_daily_agg` for a day or interval:
- Delete and rebuild rollups for target key range inside one transaction per `(owner_id, domain_id, day)`.
- Source only `mail_dmarc_report_rows` rows from committed raw reports.
- Sorting + top-source ranking is stable (see §6).
- Recompute produces byte-identical JSONB ordering by canonical serializer.

---

## 2) Database Schema

## 2.1 `mail_dmarc_reports_raw`

```sql
CREATE TABLE mail_dmarc_reports_raw (
  id                           UUID PRIMARY KEY,
  owner_id                     UUID NOT NULL,
  domain_id                    UUID NOT NULL,

  report_id                    VARCHAR(255) NOT NULL,
  org_name                     VARCHAR(255) NOT NULL,
  email_from                   VARCHAR(320) NULL,

  period_start                 TIMESTAMPTZ NOT NULL,
  period_end                   TIMESTAMPTZ NOT NULL,

  source_filename              VARCHAR(512) NULL,
  source_content_type          VARCHAR(128) NULL,
  source_encoding              VARCHAR(32) NULL,
  source_file_hash             CHAR(64) NOT NULL,

  raw_payload_encrypted        BYTEA NOT NULL,
  raw_payload_key_id           VARCHAR(128) NOT NULL,
  raw_payload_nonce            BYTEA NOT NULL,
  raw_payload_algo             VARCHAR(32) NOT NULL DEFAULT 'AES-256-GCM',

  ingest_channel               VARCHAR(16) NOT NULL,
  ingest_node_id               VARCHAR(128) NULL,
  ingest_request_id            UUID NULL,

  parse_status                 VARCHAR(16) NOT NULL DEFAULT 'pending',
  parse_error_code             VARCHAR(64) NULL,
  parse_error_detail           TEXT NULL,

  created_at                   TIMESTAMPTZ NOT NULL,
  parsed_at                    TIMESTAMPTZ NULL,

  CONSTRAINT chk_dmarc_raw_ingest_channel
    CHECK (ingest_channel IN ('mailbox','webhook')),
  CONSTRAINT chk_dmarc_raw_parse_status
    CHECK (parse_status IN ('pending','parsed','failed','duplicate')),
  CONSTRAINT chk_dmarc_period_order
    CHECK (period_start <= period_end),
  CONSTRAINT chk_dmarc_hash_hex
    CHECK (source_file_hash ~ '^[0-9a-f]{64}$')
);

CREATE UNIQUE INDEX uq_dmarc_raw_dedupe
  ON mail_dmarc_reports_raw(owner_id, report_id, source_file_hash);

CREATE INDEX ix_dmarc_raw_owner_domain_period
  ON mail_dmarc_reports_raw(owner_id, domain_id, period_start, period_end);

CREATE INDEX ix_dmarc_raw_status_created
  ON mail_dmarc_reports_raw(parse_status, created_at);

CREATE INDEX ix_dmarc_raw_created_at
  ON mail_dmarc_reports_raw(created_at DESC);
```

### Encryption at Rest Requirements
- `raw_payload_encrypted` contains ciphertext only (never plaintext XML).
- Envelope encryption:
  - Per-record DEK generated by app service.
  - DEK encrypted by KMS master key identified by `raw_payload_key_id`.
  - `raw_payload_nonce` stores AEAD nonce.
- Decrypt permission restricted to parse worker service account.

## 2.2 `mail_dmarc_report_rows`

```sql
CREATE TABLE mail_dmarc_report_rows (
  id                           UUID PRIMARY KEY,
  owner_id                     UUID NOT NULL,
  domain_id                    UUID NOT NULL,
  report_raw_id                UUID NOT NULL REFERENCES mail_dmarc_reports_raw(id) ON DELETE CASCADE,

  source_ip                    INET NOT NULL,
  message_count                INTEGER NOT NULL,

  disposition                  VARCHAR(16) NOT NULL,
  dkim_aligned                 BOOLEAN NOT NULL,
  spf_aligned                  BOOLEAN NOT NULL,
  dkim_result                  VARCHAR(16) NOT NULL,
  spf_result                   VARCHAR(16) NOT NULL,

  header_from                  VARCHAR(255) NOT NULL,
  envelope_from                VARCHAR(255) NULL,
  envelope_to                  VARCHAR(255) NULL,

  created_at                   TIMESTAMPTZ NOT NULL,

  CONSTRAINT chk_dmarc_msg_count
    CHECK (message_count >= 0),
  CONSTRAINT chk_dmarc_disposition
    CHECK (disposition IN ('none','quarantine','reject'))
);

CREATE INDEX ix_dmarc_rows_owner_domain
  ON mail_dmarc_report_rows(owner_id, domain_id);

CREATE INDEX ix_dmarc_rows_report_raw_id
  ON mail_dmarc_report_rows(report_raw_id);

CREATE INDEX ix_dmarc_rows_owner_domain_source
  ON mail_dmarc_report_rows(owner_id, domain_id, source_ip);

CREATE INDEX ix_dmarc_rows_alignment
  ON mail_dmarc_report_rows(owner_id, domain_id, dkim_aligned, spf_aligned);
```

## 2.3 `mail_dmarc_daily_agg`

```sql
CREATE TABLE mail_dmarc_daily_agg (
  id                           UUID PRIMARY KEY,
  owner_id                     UUID NOT NULL,
  domain_id                    UUID NOT NULL,

  day_utc                      DATE NOT NULL,

  pass_count                   BIGINT NOT NULL,
  fail_count                   BIGINT NOT NULL,
  quarantine_count             BIGINT NOT NULL,
  reject_count                 BIGINT NOT NULL,

  dkim_aligned_count           BIGINT NOT NULL,
  spf_aligned_count            BIGINT NOT NULL,

  total_count                  BIGINT NOT NULL,

  top_sources_json             JSONB NOT NULL,

  computed_at                  TIMESTAMPTZ NOT NULL,

  CONSTRAINT chk_dmarc_daily_nonneg
    CHECK (
      pass_count >= 0 AND fail_count >= 0 AND quarantine_count >= 0 AND reject_count >= 0 AND
      dkim_aligned_count >= 0 AND spf_aligned_count >= 0 AND total_count >= 0
    ),
  CONSTRAINT chk_dmarc_top_sources_object
    CHECK (jsonb_typeof(top_sources_json) = 'array')
);

CREATE UNIQUE INDEX uq_dmarc_daily_owner_domain_day
  ON mail_dmarc_daily_agg(owner_id, domain_id, day_utc);

CREATE INDEX ix_dmarc_daily_owner_day
  ON mail_dmarc_daily_agg(owner_id, day_utc DESC);

CREATE INDEX ix_dmarc_daily_domain_day
  ON mail_dmarc_daily_agg(owner_id, domain_id, day_utc DESC);

CREATE INDEX ix_dmarc_daily_top_sources_gin
  ON mail_dmarc_daily_agg USING GIN(top_sources_json jsonb_path_ops);
```

### Retention Policy
- `mail_dmarc_reports_raw`: 400 days encrypted retention (audit/replay window).
- `mail_dmarc_report_rows`: 400 days.
- `mail_dmarc_daily_agg`: 5 years.
- Purge is deterministic, day-bounded, idempotent job.

---

## 3) Doctrine Entity Structures (PHP 8.4 readonly)

```php
<?php

declare(strict_types=1);

namespace App\Mail\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'mail_dmarc_reports_raw')]
final class MailDmarcReportRaw
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        public readonly string $id,

        #[ORM\Column(type: 'uuid')]
        public readonly string $ownerId,

        #[ORM\Column(type: 'uuid')]
        public readonly string $domainId,

        #[ORM\Column(type: 'string', length: 255)]
        public readonly string $reportId,

        #[ORM\Column(type: 'string', length: 255)]
        public readonly string $orgName,

        #[ORM\Column(type: 'string', length: 320, nullable: true)]
        public readonly ?string $emailFrom,

        #[ORM\Column(type: 'datetimetz_immutable')]
        public readonly \DateTimeImmutable $periodStart,

        #[ORM\Column(type: 'datetimetz_immutable')]
        public readonly \DateTimeImmutable $periodEnd,

        #[ORM\Column(type: 'string', length: 64)]
        public readonly string $sourceFileHash,

        #[ORM\Column(type: 'blob')]
        public readonly mixed $rawPayloadEncrypted,

        #[ORM\Column(type: 'string', length: 128)]
        public readonly string $rawPayloadKeyId,

        #[ORM\Column(type: 'blob')]
        public readonly mixed $rawPayloadNonce,

        #[ORM\Column(type: 'string', length: 32)]
        public readonly string $rawPayloadAlgo,

        #[ORM\Column(type: 'string', length: 16)]
        public readonly string $ingestChannel,

        #[ORM\Column(type: 'string', length: 16)]
        public readonly string $parseStatus,

        #[ORM\Column(type: 'string', length: 64, nullable: true)]
        public readonly ?string $parseErrorCode,

        #[ORM\Column(type: 'text', nullable: true)]
        public readonly ?string $parseErrorDetail,

        #[ORM\Column(type: 'datetimetz_immutable')]
        public readonly \DateTimeImmutable $createdAt,

        #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
        public readonly ?\DateTimeImmutable $parsedAt,
    ) {}
}
```

```php
<?php

declare(strict_types=1);

namespace App\Mail\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'mail_dmarc_report_rows')]
final class MailDmarcReportRow
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        public readonly string $id,

        #[ORM\Column(type: 'uuid')]
        public readonly string $ownerId,

        #[ORM\Column(type: 'uuid')]
        public readonly string $domainId,

        #[ORM\Column(type: 'uuid')]
        public readonly string $reportRawId,

        #[ORM\Column(type: 'string', length: 45)]
        public readonly string $sourceIp,

        #[ORM\Column(type: 'integer')]
        public readonly int $messageCount,

        #[ORM\Column(type: 'string', length: 16)]
        public readonly string $disposition,

        #[ORM\Column(type: 'boolean')]
        public readonly bool $dkimAligned,

        #[ORM\Column(type: 'boolean')]
        public readonly bool $spfAligned,

        #[ORM\Column(type: 'string', length: 16)]
        public readonly string $dkimResult,

        #[ORM\Column(type: 'string', length: 16)]
        public readonly string $spfResult,

        #[ORM\Column(type: 'string', length: 255)]
        public readonly string $headerFrom,

        #[ORM\Column(type: 'datetimetz_immutable')]
        public readonly \DateTimeImmutable $createdAt,
    ) {}
}
```

```php
<?php

declare(strict_types=1);

namespace App\Mail\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'mail_dmarc_daily_agg')]
final class MailDmarcDailyAgg
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        public readonly string $id,

        #[ORM\Column(type: 'uuid')]
        public readonly string $ownerId,

        #[ORM\Column(type: 'uuid')]
        public readonly string $domainId,

        #[ORM\Column(type: 'date_immutable')]
        public readonly \DateTimeImmutable $dayUtc,

        #[ORM\Column(type: 'bigint')]
        public readonly string $passCount,

        #[ORM\Column(type: 'bigint')]
        public readonly string $failCount,

        #[ORM\Column(type: 'bigint')]
        public readonly string $quarantineCount,

        #[ORM\Column(type: 'bigint')]
        public readonly string $rejectCount,

        #[ORM\Column(type: 'bigint')]
        public readonly string $dkimAlignedCount,

        #[ORM\Column(type: 'bigint')]
        public readonly string $spfAlignedCount,

        #[ORM\Column(type: 'bigint')]
        public readonly string $totalCount,

        /** @var array<int, array{sourceIp:string,count:int,shareBp:int,dispositionBreakdown:array<string,int>}> */
        #[ORM\Column(type: 'json', options: ['jsonb' => true])]
        public readonly array $topSources,

        #[ORM\Column(type: 'datetimetz_immutable')]
        public readonly \DateTimeImmutable $computedAt,
    ) {}
}
```

---

## 4) REST API

All endpoints return `correlationId`. Time range filters are UTC RFC3339 unless `day` (`YYYY-MM-DD`).

## 4.1 Admin: `GET /api/v1/admin/mail/dmarc/reports`

Purpose:
- List raw report ingestion records, parse status, dedupe markers.

Query params:
- `ownerId` optional UUID (super-admin only)
- `domainId` optional UUID
- `status` optional (`pending|parsed|failed|duplicate`)
- `from` required RFC3339
- `to` required RFC3339
- `page` default 1
- `pageSize` default 50 max 200

Response example:
```json
{
  "items": [
    {
      "id": "01970270-25fb-7df9-95df-ec4900bcdb2f",
      "ownerId": "0197026f-32b8-70ee-846d-2bb6acf4fbd1",
      "domainId": "0197026f-a189-7af6-bd8b-d6d9733a0060",
      "reportId": "4f6a9ea7-9b26-4f8d-9a0f-9f3cdb5f07de",
      "orgName": "google.com",
      "periodStart": "2026-11-10T00:00:00Z",
      "periodEnd": "2026-11-10T23:59:59Z",
      "sourceFileHash": "89f9c1d2a9...",
      "parseStatus": "parsed",
      "createdAt": "2026-11-11T01:02:03Z",
      "parsedAt": "2026-11-11T01:02:04Z"
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

## 4.2 Admin: `GET /api/v1/admin/mail/dmarc/sources`

Purpose:
- Return top source IPs and dispositions from aggregated or raw rows.

Query params:
- `ownerId` optional UUID (super-admin only)
- `domainId` required UUID
- `from` required RFC3339
- `to` required RFC3339
- `limit` default 20 max 100

Response example:
```json
{
  "domainId": "0197026f-a189-7af6-bd8b-d6d9733a0060",
  "from": "2026-11-01T00:00:00Z",
  "to": "2026-11-30T23:59:59Z",
  "sources": [
    {
      "sourceIp": "203.0.113.14",
      "count": 42013,
      "shareBp": 2815,
      "disposition": {
        "none": 40100,
        "quarantine": 1400,
        "reject": 513
      },
      "alignment": {
        "dkimAligned": 39000,
        "spfAligned": 40220
      }
    }
  ],
  "correlationId": "01K..."
}
```

## 4.3 Customer: `GET /api/v1/customer/mail/domains/{id}/dmarc/overview`

Purpose:
- Tenant-scoped daily trend view from `mail_dmarc_daily_agg`.

Path params:
- `id` domain UUID v7 (must belong to authenticated owner)

Query params:
- `fromDay` required (`YYYY-MM-DD`)
- `toDay` required (`YYYY-MM-DD`)

Response example:
```json
{
  "domainId": "0197026f-a189-7af6-bd8b-d6d9733a0060",
  "series": [
    {
      "day": "2026-11-10",
      "totalCount": 148220,
      "passCount": 142004,
      "failCount": 6216,
      "quarantineCount": 912,
      "rejectCount": 310,
      "dkimAlignedCount": 140110,
      "spfAlignedCount": 141420,
      "topSources": [
        {
          "sourceIp": "203.0.113.14",
          "count": 42013,
          "shareBp": 2833,
          "dispositionBreakdown": {
            "none": 40100,
            "quarantine": 1400,
            "reject": 513
          }
        }
      ]
    }
  ],
  "correlationId": "01K..."
}
```

Validation:
- `fromDay <= toDay`, max range 366 days.
- Cross-tenant access returns `404`.

---

## 5) Worker Design

## 5.1 Secure XML Parsing Requirements

Parser must run with the following hard limits:
- External entities disabled (XXE off).
- DTD/entity expansion disabled.
- Max decompressed XML size: 10 MiB.
- Max compressed attachment size: 5 MiB.
- Max XML depth: 64.
- Max element count: 500,000.
- Max attributes per element: 32.
- Reject control chars outside XML 1.0 legal ranges.

## 5.2 Attachment Handling (`.xml`, `.xml.gz`, `.zip`)

Deterministic extraction order:
1. If direct XML, parse as-is.
2. If gzip, decompress once; nested compression forbidden.
3. If zip, enumerate entries by filename ascending byte order.
4. Select first XML entry matching allowlist pattern (`*.xml`), reject archives with >1,000 entries.

Safety checks:
- Zip slip prevention: reject entries containing `..` or absolute paths.
- Compression ratio cap: 20x (to block zip bombs).

## 5.3 Duplicate Detection

Before parse:
- Compute `source_file_hash` from original attachment bytes.
- Extract `report_id` from XML metadata header.
- Attempt insert into `mail_dmarc_reports_raw` with unique key `(owner_id, report_id, source_file_hash)`.
- On conflict: mark event duplicate, do not parse again.

## 5.4 Restart Safety / Idempotent State Machine

`parse_status` transitions:
- `pending -> parsed`
- `pending -> failed`
- `pending -> duplicate`

Worker rules:
- Parse worker acquires row lock (`FOR UPDATE SKIP LOCKED`) on pending records.
- If worker crashes mid-parse, transaction rolls back and status remains `pending`.
- Retry with bounded backoff for transient decrypt/DB errors.
- Permanent parser errors write deterministic `parse_error_code`.

---

## 6) Aggregation Logic

## 6.1 Daily Rollups

Aggregation key: `(owner_id, domain_id, day_utc)`.

Day derivation:
- `day_utc = date_trunc('day', period_start AT TIME ZONE 'UTC')::date`.
- If report spans multiple days, split counts proportionally by covered seconds to each day (deterministic integer apportionment; remainder assigned to earliest day).

Rollup formulas:
- `total_count = sum(message_count)`
- `pass_count = sum(message_count where dkim_aligned = true and spf_aligned = true)`
- `fail_count = total_count - pass_count`
- `quarantine_count = sum(message_count where disposition='quarantine')`
- `reject_count = sum(message_count where disposition='reject')`
- `dkim_aligned_count = sum(message_count where dkim_aligned=true)`
- `spf_aligned_count = sum(message_count where spf_aligned=true)`

## 6.2 `top_sources_json` Structure

Canonical JSON array sorted by:
1. `count` descending
2. `sourceIp` ascending bytewise

Max entries: 20.

Schema per item:
```json
{
  "sourceIp": "203.0.113.14",
  "count": 42013,
  "shareBp": 2833,
  "dispositionBreakdown": {
    "none": 40100,
    "quarantine": 1400,
    "reject": 513
  }
}
```

`shareBp` computation:
- `shareBp = floor(count * 10000 / NULLIF(total_count,0))`
- Remainder basis points assigned by stable source ordering to guarantee sum=10000 when total>0.

## 6.3 Performance Strategy

- Parse and row inserts are batched (e.g., 1,000 rows per insert chunk).
- Daily aggregation uses incremental upsert for new parsed reports:
  - stage temp aggregation by report/day.
  - merge into `mail_dmarc_daily_agg` via `INSERT ... ON CONFLICT ... DO UPDATE`.
- For large tenants, optionally partition `mail_dmarc_report_rows` by month on `created_at`.
- Use covering indexes for dominant filters (`owner_id`, `domain_id`, time).

---

## 7) Security & Abuse Mitigation

1. **Parser hardening**
   - XXE and DTD disabled.
   - Strict size/depth limits.
   - Compression bomb ratio cap.

2. **Data integrity**
   - Store raw hash and verify before parse.
   - Audit events for ingest, parse success/failure, and recompute.

3. **Tenant isolation**
   - `owner_id` mandatory in all repository predicates.
   - Domain ownership verified before read/write.

4. **Rate limiting**
   - Ingestion endpoint per-node and per-owner request quotas.
   - Batch size caps to avoid queue starvation.

5. **Encrypted payload handling**
   - Only parser worker principal can decrypt raw payload.
   - Key rotation supported via `raw_payload_key_id`; old key decrypt path retained during rotation window.

6. **Abuse throttling**
   - If owner submits >N failed payloads/hour (e.g., 100), temporarily quarantine new reports for manual review.

---

## 8) Acceptance Criteria

1. Duplicate raw report (same `owner_id`, `report_id`, `source_file_hash`) is accepted idempotently and not reparsed.
2. XML with external entities is rejected with deterministic error code.
3. Oversized compressed/decompressed payloads are rejected and audited.
4. Worker restart during parse does not create partial rows.
5. Daily aggregation totals exactly match sum of normalized rows.
6. `top_sources_json` ordering is stable across recomputes.
7. Recompute for same day produces identical row values and JSON payload ordering.
8. Customer overview endpoint exposes only owner-scoped domains.
9. Raw payload remains encrypted at rest; plaintext never persisted.
10. All timestamps in stored and returned data are UTC.

---

## 9) Edge Cases

1. **Malformed XML but valid gzip/zip**
   - raw row saved, parse status `failed`, error code `XML_INVALID`.

2. **Missing `report_id` in XML**
   - fallback deterministic synthetic ID: `sha256(org_name + period_start + period_end + source_file_hash)`; flagged `report_id_synthesized=true` in parser metadata.

3. **Report covering multiple domains**
   - split rows by `header_from` domain; unknown domains discarded with audit event.

4. **Source IP invalid format in payload**
   - row dropped with `ROW_INVALID_SOURCE_IP` counter increment; report may still be `parsed_with_warnings` in telemetry while DB status remains `parsed`.

5. **Huge source cardinality**
   - aggregate stores only top 20; tail count preserved in totals.

6. **Clock skew in webhook timestamp**
   - use server receive time for `created_at`, keep payload timestamp in metadata only.

7. **Conflicting owner/domain association attempt**
   - reject ingestion with `TENANT_SCOPE_MISMATCH`.

---

## 10) Determinism Guarantees

Formal function:
- `G(raw_reports_encrypted + parser_version + ruleset_version) -> rows + daily_agg`

Determinism controls:
1. Canonical UTF-8 normalization before XML parse.
2. Stable zip entry ordering and deterministic first-match selection.
3. Fixed parser limits and fixed error taxonomy.
4. Stable SQL ordering in all aggregation queries.
5. Canonical JSON serialization for `top_sources_json` (sorted keys and stable item order).
6. Integer-only arithmetic for counts and basis-point shares.
7. Recompute reads immutable normalized rows only.

Required verification tests:
- Golden parse test: same raw payload twice => identical normalized rows.
- Permutation test: shuffled row ingest order => identical daily agg.
- Replay test: rerun recompute day => no diff in `mail_dmarc_daily_agg`.
- Duplicate test: second insert same `(owner_id, report_id, source_file_hash)` => no new rows.

