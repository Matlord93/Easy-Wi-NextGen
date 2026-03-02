# Multi-Node Safe IP Reputation Monitor Specification (Implementation-Ready)

## 0. Scope and Hard Constraints

This specification defines an implementation-ready IP reputation monitor for outbound mail nodes in a multi-tenant control plane.

Hard constraints:
1. Deterministic scheduling controlled by panel.
2. Panel-driven job dispatch (`mail.reputationCheck`).
3. UUID v7 primary keys on new tables.
4. Quorum-based DNSBL/RBL verification using multiple resolvers.
5. No duplicate checks per `(ip, provider, window_id)`.
6. All timestamps UTC (`TIMESTAMPTZ`).
7. Multi-tenant isolation via `owner_id` in all owner-bound operations.

---

## 1) Architecture

## 1.1 Control Flow

**Control Plane (Symfony 8 + PostgreSQL 16):**
- Generates deterministic check windows.
- Dispatches `mail.reputationCheck` jobs to agents.
- Aggregates returned listing checks.
- Computes trend/slope degradation and stores scores.

**Execution Plane (Go 1.22 Agent):**
- Receives check assignment with fixed set of IPs/providers/resolvers.
- Executes DNSBL lookups using fixed resolver set from payload.
- Returns raw resolver-level responses in batch.

No local autonomous scheduling in agent.

## 1.2 DNSBL Lookup Strategy

Per `(ip, provider)` lookup:
1. Transform IP to provider-specific query name (e.g., reverse IPv4 octets + zone).
2. Perform `A` lookup against each assigned resolver.
3. Optional `TXT` lookup for reason text when provider supports it.
4. Capture result tuple per resolver:
   - `listed` boolean,
   - `rcode`,
   - `answer_records[]` sorted,
   - `latency_ms`.

Deterministic provider configuration:
- Provider definition includes `zone`, valid `A` answer allowlist/CIDR patterns, timeout, retry policy.
- Query string canonicalization is deterministic and lowercase.

## 1.3 Multi-Resolver Quorum Rule

Definitions:
- `N` = number of resolvers attempted.
- `Q` = quorum threshold from job payload (default `ceil(2N/3)`).

Decision:
- `listed=true` iff `listed_votes >= Q`.
- `listed=false` iff `unlisted_votes >= Q`.
- else `inconclusive`.

An inconclusive result is retried in same window with bounded backoff (see 1.4) and recorded as anomaly if unresolved.

## 1.4 Retry & Backoff

Retry conditions:
- Resolver timeout.
- SERVFAIL.
- Inconclusive quorum.

Deterministic retry policy:
- max attempts: 3.
- backoff schedule (seconds): `5, 20`.
- jitter: none (determinism requirement).
- final status: `confirmed|cleared|inconclusive|error`.

## 1.5 Slope Degradation Detection

For each `(owner_id nullable/global, ip)` compute rolling degradation signal:
- Window sizes: 1d, 7d, 30d.
- `listed_ratio_bp = listed_checks * 10000 / total_checks`.
- `severity_score = weighted_sum(provider_weight * listed_state)`.
- `slope_bp_per_day = linear_regression(severity_score over last 7 windows)` using deterministic fixed-point arithmetic.

Alert classes:
- `stable`: slope < 50 bp/day
- `warning`: 50..199 bp/day
- `critical`: >= 200 bp/day

All arithmetic integer/fixed-point; no floating persistence.

---

## 2) Database

## 2.1 `mail_ip_reputation_checks`

```sql
CREATE TABLE mail_ip_reputation_checks (
  id                           UUID PRIMARY KEY,
  owner_id                     UUID NULL,
  node_id                      VARCHAR(128) NOT NULL,

  ip                           INET NOT NULL,
  ip_family                    SMALLINT NOT NULL,

  provider                     VARCHAR(64) NOT NULL,
  resolver                     VARCHAR(255) NOT NULL,

  window_id                    VARCHAR(64) NOT NULL,
  checked_at                   TIMESTAMPTZ NOT NULL,

  listed                       BOOLEAN NULL,
  status                       VARCHAR(16) NOT NULL,
  rcode                        VARCHAR(16) NULL,

  answer_records_json          JSONB NOT NULL,
  answer_hash                  CHAR(64) NOT NULL,

  latency_ms                   INTEGER NULL,
  attempt_no                   SMALLINT NOT NULL,

  correlation_id               VARCHAR(64) NOT NULL,
  created_at                   TIMESTAMPTZ NOT NULL,

  CONSTRAINT chk_rep_ip_family
    CHECK (ip_family IN (4,6)),
  CONSTRAINT chk_rep_status
    CHECK (status IN ('confirmed','cleared','inconclusive','error','timeout')),
  CONSTRAINT chk_rep_attempt_no
    CHECK (attempt_no BETWEEN 1 AND 3),
  CONSTRAINT chk_rep_latency
    CHECK (latency_ms IS NULL OR latency_ms >= 0),
  CONSTRAINT chk_rep_answer_hash
    CHECK (answer_hash ~ '^[0-9a-f]{64}$'),
  CONSTRAINT chk_rep_answer_json_array
    CHECK (jsonb_typeof(answer_records_json) = 'array')
);

CREATE UNIQUE INDEX uq_rep_no_dup_window
  ON mail_ip_reputation_checks(ip, provider, resolver, window_id, attempt_no);

CREATE INDEX ix_rep_owner_ip_time
  ON mail_ip_reputation_checks(owner_id, ip, checked_at DESC);

CREATE INDEX ix_rep_node_window
  ON mail_ip_reputation_checks(node_id, window_id);

CREATE INDEX ix_rep_provider_time
  ON mail_ip_reputation_checks(provider, checked_at DESC);

CREATE INDEX ix_rep_window_status
  ON mail_ip_reputation_checks(window_id, status);
```

No-duplicate-per-window guarantee:
- Final aggregation consumes max one terminal result per `(ip, provider, resolver, window_id)`.
- `attempt_no` tracks retries, while unique key prevents duplicate identical attempt writes.

## 2.2 `mail_ip_reputation_scores`

```sql
CREATE TABLE mail_ip_reputation_scores (
  id                           UUID PRIMARY KEY,
  owner_id                     UUID NULL,

  ip                           INET NOT NULL,
  window_id                    VARCHAR(64) NOT NULL,
  window_start                 TIMESTAMPTZ NOT NULL,
  window_end                   TIMESTAMPTZ NOT NULL,

  total_checks                 INTEGER NOT NULL,
  listed_checks                INTEGER NOT NULL,
  inconclusive_checks          INTEGER NOT NULL,

  listed_ratio_bp              INTEGER NOT NULL,
  severity_score_bp            INTEGER NOT NULL,
  slope_bp_per_day             INTEGER NOT NULL,

  trend_class                  VARCHAR(16) NOT NULL,
  factors_json                 JSONB NOT NULL,

  computed_at                  TIMESTAMPTZ NOT NULL,

  CONSTRAINT chk_rep_score_counts
    CHECK (
      total_checks >= 0 AND
      listed_checks >= 0 AND
      inconclusive_checks >= 0 AND
      listed_checks <= total_checks AND
      inconclusive_checks <= total_checks
    ),
  CONSTRAINT chk_rep_ratio_bp
    CHECK (listed_ratio_bp BETWEEN 0 AND 10000),
  CONSTRAINT chk_rep_trend_class
    CHECK (trend_class IN ('stable','warning','critical')),
  CONSTRAINT chk_rep_window_order
    CHECK (window_end > window_start),
  CONSTRAINT chk_rep_factors_json
    CHECK (jsonb_typeof(factors_json) = 'object')
);

CREATE UNIQUE INDEX uq_rep_scores_window
  ON mail_ip_reputation_scores(owner_id, ip, window_id);

CREATE INDEX ix_rep_scores_owner_ip_time
  ON mail_ip_reputation_scores(owner_id, ip, window_start DESC);

CREATE INDEX ix_rep_scores_trend
  ON mail_ip_reputation_scores(trend_class, window_start DESC);

CREATE INDEX ix_rep_scores_factors_gin
  ON mail_ip_reputation_scores USING GIN(factors_json jsonb_path_ops);
```

Retention:
- `mail_ip_reputation_checks`: 180 days.
- `mail_ip_reputation_scores`: 730 days.
- Purge jobs are day-bounded and idempotent.

---

## 3) REST API

All endpoints are admin-scope.
All responses include `correlationId`.

## 3.1 `GET /api/v1/admin/mail/reputation`

Purpose:
- Time-series and current trend per IP.

Query params:
- `ownerId` optional UUID (super-admin)
- `ip` optional (single IP filter)
- `from` required RFC3339
- `to` required RFC3339
- `window` optional enum (`1h|6h|1d`) default `1d`
- `trendClass` optional (`stable|warning|critical`)
- `page` default 1
- `pageSize` default 50 max 200

Response example:
```json
{
  "items": [
    {
      "ip": "203.0.113.10",
      "ownerId": null,
      "windowId": "2026-11-15T10:00:00Z/PT1H",
      "windowStart": "2026-11-15T10:00:00Z",
      "windowEnd": "2026-11-15T11:00:00Z",
      "totalChecks": 42,
      "listedChecks": 14,
      "inconclusiveChecks": 2,
      "listedRatioBp": 3333,
      "severityScoreBp": 2900,
      "slopeBpPerDay": 210,
      "trendClass": "critical"
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

## 3.2 `GET /api/v1/admin/mail/reputation/listings`

Purpose:
- Resolver/provider-level listing evidence for investigation.

Query params:
- `ownerId` optional UUID (super-admin)
- `ip` required
- `provider` optional
- `windowId` optional
- `from` required RFC3339
- `to` required RFC3339
- `status` optional (`confirmed|cleared|inconclusive|error|timeout`)
- `page` default 1
- `pageSize` default 100 max 500

Response example:
```json
{
  "items": [
    {
      "id": "01970411-c69b-7149-ad6d-d2c8a2663cc3",
      "ip": "203.0.113.10",
      "provider": "spamhaus-zen",
      "resolver": "9.9.9.9",
      "windowId": "2026-11-15T10:00:00Z/PT1H",
      "checkedAt": "2026-11-15T10:05:03Z",
      "listed": true,
      "status": "confirmed",
      "rcode": "NOERROR",
      "answerRecords": ["127.0.0.4"],
      "latencyMs": 28,
      "attemptNo": 1,
      "correlationId": "01K..."
    }
  ],
  "pagination": {
    "page": 1,
    "pageSize": 100,
    "total": 1
  },
  "correlationId": "01K..."
}
```

Validation:
- `from <= to` and max range 31 days for listings endpoint.
- Cross-tenant references return `404`.

---

## 4) Agent Contract

## 4.1 Job Payload (`mail.reputationCheck`)

```json
{
  "jobId": "01970414-aef5-7efd-95c0-45f70f9443d4",
  "nodeId": "node-eu-1",
  "windowId": "2026-11-15T10:00:00Z/PT1H",
  "windowStart": "2026-11-15T10:00:00Z",
  "windowEnd": "2026-11-15T11:00:00Z",
  "quorum": {
    "resolvers": ["1.1.1.1", "9.9.9.9", "8.8.8.8"],
    "threshold": 2
  },
  "targets": [
    {
      "ownerId": null,
      "ip": "203.0.113.10",
      "providers": ["spamhaus-zen", "spamcop", "barracuda"]
    }
  ],
  "retryPolicy": {
    "maxAttempts": 3,
    "backoffSeconds": [5, 20]
  },
  "correlationId": "01K..."
}
```

## 4.2 Batch Reporting Endpoint

`POST /api/v1/agent/mail/reputation/checks:batch`

Headers:
- `Authorization: Bearer <agent-token>`
- `Idempotency-Key` required
- `X-Correlation-Id` optional

Request example:
```json
{
  "nodeId": "node-eu-1",
  "windowId": "2026-11-15T10:00:00Z/PT1H",
  "generatedAt": "2026-11-15T10:11:00Z",
  "checks": [
    {
      "ownerId": null,
      "ip": "203.0.113.10",
      "provider": "spamhaus-zen",
      "resolver": "9.9.9.9",
      "listed": true,
      "status": "confirmed",
      "rcode": "NOERROR",
      "answerRecords": ["127.0.0.4"],
      "latencyMs": 28,
      "attemptNo": 1,
      "checkedAt": "2026-11-15T10:05:03Z"
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
  "correlationId": "01K..."
}
```

## 4.3 Deterministic Window ID

Format:
- `window_id = <window_start_rfc3339_utc>/<duration_iso8601>`
- examples:
  - `2026-11-15T10:00:00Z/PT1H`
  - `2026-11-15T00:00:00Z/P1D`

Generation rule:
- `window_start = floor(now_utc, cadence)` where cadence from schedule.
- Always UTC; no locale/timezone dependence.

No duplicate checks per window is enforced by:
- job scheduler dedupe key `(node_id, window_id)`
- DB unique key per `(ip, provider, resolver, window_id, attempt_no)`
- aggregation consuming terminal resolver result once.

---

## 5) Job Type: `mail.reputationCheck`

Dispatcher behavior:
1. Determine active node/IP inventory snapshot at window start.
2. Build deterministic target order:
   - `owner_id null first`, then UUID ascending;
   - within owner, IP ascending bytewise;
   - providers ascending.
3. Emit one job per node per window.
4. Persist dispatch log with idempotency key `nodeId:windowId`.

Execution semantics:
- at-least-once delivery, exactly-once effect via idempotency keys + DB constraints.

---

## 6) Security

## 6.1 DNS Poisoning Mitigation

1. Multi-resolver quorum (independent anycast providers).
2. Resolver allowlist configured centrally; agent cannot self-supply resolvers.
3. Optional DNSSEC validation flag per provider where supported.
4. Answer allowlist validation for each provider zone.
5. Reject private/bogon resolver IPs in config.

## 6.2 Resolver Anomaly Detection

For each resolver maintain anomaly counters:
- mismatch rate vs peer majority (`mismatch_bp`).
- timeout rate (`timeout_bp`).
- NXDOMAIN divergence rate (`nxdomain_divergence_bp`).

Rules:
- If resolver anomaly exceeds threshold for 3 consecutive windows, mark resolver degraded.
- Degraded resolver excluded from quorum set in next schedule version.
- Exclusion changes are audited and versioned.

## 6.3 Input and Payload Controls

- Max 5,000 checks per batch payload.
- Max 8 MiB request body.
- `answerRecords` max 16 entries/check.
- Strict schema validation and UTF-8 enforcement.

---

## 7) Acceptance Criteria

1. Same schedule window always generates identical `window_id` and target ordering.
2. Duplicate dispatch attempts for same `(node_id, window_id)` do not duplicate effective checks.
3. Quorum decision is deterministic for identical resolver inputs.
4. Inconclusive results follow fixed retry/backoff and terminate deterministically.
5. No duplicate stored check rows per `(ip, provider, resolver, window_id, attempt_no)`.
6. Reputation score row unique per `(owner_id, ip, window_id)`.
7. Slope trend class transitions follow configured integer thresholds exactly.
8. Listings endpoint returns resolver-level evidence supporting computed scores.

---

## 8) Edge Cases

1. **Resolver split-brain (1 listed, 1 clear, 1 timeout)**
   - inconclusive on first attempt; retry until quorum or terminal inconclusive.

2. **Provider returns unexpected A record**
   - treat as `error` and flagged `ANSWER_OUT_OF_POLICY`.

3. **Node offline entire window**
   - panel marks node report missing; job re-dispatch once before closing window.

4. **IPv6 target against IPv4-only provider zone**
   - mark check `error` with deterministic code `UNSUPPORTED_IP_FAMILY`.

5. **Clock skew on agent**
   - server trusts job window and validates `checked_at` within `[window_start-5m, window_end+5m]`.

6. **Large inventory spike**
   - scheduler shards deterministically by node and IP hash modulo shard count.

---

## 9) Determinism Guarantees

Formal function:
- `R(window_definition, target_snapshot, resolver_results) -> checks + scores`

Determinism controls:
1. Deterministic windowing and target ordering.
2. Fixed resolver set and quorum threshold in job payload.
3. Retry schedule without jitter.
4. Stable sorting of answer records before hashing.
5. Integer-only score and slope calculations.
6. Deterministic upsert semantics for score rows.

Verification tests required:
- Golden test: same resolver batch input -> byte-identical score JSON output.
- Replay test: resubmitting same batch with same idempotency key -> zero net new rows.
- Permutation test: shuffled check report order -> identical aggregated scores.
- Resolver anomaly test: repeated mismatch windows trigger deterministic degradation.

