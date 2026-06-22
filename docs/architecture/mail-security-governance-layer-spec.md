# Cross-Feature Security & Governance Layer Specification (Enterprise Mail Hosting Control Plane)

## 0) Scope, Principles, and Hard Constraints

This specification defines the shared Security & Governance layer across all mail-control features (policy, telemetry, abuse, DMARC, ARC, reputation, logs).

Platform stack:
- Control plane: Symfony 8 + PostgreSQL 16
- Execution plane: Go agent

Hard constraints:
1. Strict multi-tenant isolation.
2. Idempotency enforced for all mutating operations.
3. mTLS + JWT required for panel<->agent communication.
4. Replay protection for control and telemetry channels.
5. Full audit trail for sensitive actions.
6. Defined retention and deterministic purge strategy.
7. GDPR-compliant tenant data export/delete workflows.

---

## 1) Owner Scoping Enforcement Pattern

## 1.1 Core Rule

Every tenant-bound query MUST include `owner_id` predicate at repository boundary and be validated by API auth context.

Enforcement layers (defense-in-depth):
1. **Controller layer**: resolve authenticated principal -> `effective_owner_ids`.
2. **Application service layer**: pass `ownerId` explicitly in all command/query DTOs.
3. **Repository layer**: hard-code owner scoping in SQL/QueryBuilder.
4. **Database policy layer** (recommended): row-level security (RLS) for tenant tables.

No implicit global reads in tenant APIs.

## 1.2 Scoping Contract Pattern

Required method signatures (logical):
- `findByIdForOwner(id, ownerId)`
- `listForOwner(ownerId, filters, page)`
- `updateForOwner(id, ownerId, patch)`

Forbidden patterns:
- `findById(id)` for tenant entities.
- `UPDATE ... WHERE id=:id` without owner filter.

## 1.3 SQL/RLS Strategy

For tables with `owner_id`:
- add composite indexes `(owner_id, <primary access columns...>)`.
- enable RLS where operationally feasible.

RLS example policy:
```sql
ALTER TABLE mail_logs ENABLE ROW LEVEL SECURITY;
CREATE POLICY p_owner_scope_mail_logs
  ON mail_logs
  USING (owner_id::text = current_setting('app.current_owner_id', true));
```

App session setup per request:
- `SET LOCAL app.current_owner_id = '<uuid>'`.

Super-admin path:
- explicit privileged role; never by omission of owner filters.

## 1.4 Cross-Tenant Leak Prevention

- Existence probing for foreign tenant resources returns `404` (not `403`).
- Pagination totals are owner-scoped.
- Correlation IDs never encode tenant identifiers.

---

## 2) Audit Event Schema

## 2.1 Table: `audit_events`

```sql
CREATE TABLE audit_events (
  id                           UUID PRIMARY KEY,

  owner_id                     UUID NULL,
  actor_id                     UUID NULL,
  actor_type                   VARCHAR(16) NOT NULL,

  event_type                   VARCHAR(128) NOT NULL,
  category                     VARCHAR(32) NOT NULL,
  severity                     VARCHAR(16) NOT NULL,

  resource_type                VARCHAR(64) NOT NULL,
  resource_id                  UUID NULL,

  correlation_id               VARCHAR(64) NOT NULL,
  idempotency_key_hash         CHAR(64) NULL,

  before_hash                  CHAR(64) NULL,
  after_hash                   CHAR(64) NULL,
  diff_json                    JSONB NULL,

  request_meta_json            JSONB NOT NULL,
  outcome                      VARCHAR(16) NOT NULL,

  occurred_at                  TIMESTAMPTZ NOT NULL,
  created_at                   TIMESTAMPTZ NOT NULL,

  CONSTRAINT chk_audit_actor_type
    CHECK (actor_type IN ('user','agent','system')),
  CONSTRAINT chk_audit_severity
    CHECK (severity IN ('info','warning','critical')),
  CONSTRAINT chk_audit_outcome
    CHECK (outcome IN ('success','failure','denied')),
  CONSTRAINT chk_audit_req_meta_obj
    CHECK (jsonb_typeof(request_meta_json) = 'object'),
  CONSTRAINT chk_audit_diff_obj
    CHECK (diff_json IS NULL OR jsonb_typeof(diff_json) = 'object')
);

CREATE INDEX ix_audit_owner_time
  ON audit_events(owner_id, occurred_at DESC);

CREATE INDEX ix_audit_event_type_time
  ON audit_events(event_type, occurred_at DESC);

CREATE INDEX ix_audit_actor_time
  ON audit_events(actor_id, occurred_at DESC);

CREATE INDEX ix_audit_correlation
  ON audit_events(correlation_id);

CREATE INDEX ix_audit_request_meta_gin
  ON audit_events USING GIN(request_meta_json jsonb_path_ops);
```

## 2.2 Hash Canonicalization Rules

`before_hash`/`after_hash` generation:
1. Canonical JSON serializer (sorted keys, UTF-8, no insignificant whitespace).
2. SHA-256 hex lowercase.
3. Hash only governance-approved fields (exclude volatile timestamps).

## 2.3 Minimum Audited Events

Mandatory event coverage:
- authn/authz failures for privileged endpoints,
- policy/config changes,
- key lifecycle operations (ARC/DKIM/etc metadata),
- enforcement actions (throttle/block/apply/revert),
- data export/delete requests and execution,
- agent credential rotation and trust changes.

---

## 3) Telemetry Ingestion Security

## 3.1 Transport Security

Agent -> Panel ingestion requires:
1. mTLS with panel-trusted agent client cert chain.
2. JWT bearer bound to agent identity (`agent_id`, `node_id`).
3. Certificate SAN must match registered node identity.
4. TLS 1.2+ (prefer 1.3), modern cipher suites only.

Authentication model: **AND**, not OR.
- Request accepted only if both mTLS cert and JWT validate and identities match registration.

## 3.2 Replay Protection

Every ingestion request includes:
- `Idempotency-Key`
- `X-Request-Timestamp` (UTC RFC3339)
- `X-Request-Nonce` (96-bit+ random)
- signed claim `jti` in JWT

Server-side replay checks:
1. timestamp skew tolerance ±120s.
2. nonce uniqueness per `(agent_id, timestamp_bucket)` for retention window (e.g., 15 min).
3. reject reused `(agent_id, jti)`.
4. idempotency cache keyed by `(agent_id, endpoint, idempotency_key)`.

## 3.3 Payload Validation and Quotas

- Strict JSON schema validation.
- UTF-8 only; reject invalid byte sequences.
- Max payload size per endpoint (feature-specific, centrally configured).
- Max batch cardinality and per-item field length limits.
- Rate limits per agent/node and global backpressure thresholds.

## 3.4 Ingestion Write Model

- Use append-only staging tables for raw telemetry where required.
- Normalize via deterministic workers.
- Store `payload_hash` to support dedupe and forensic verification.

---

## 4) Secrets Handling Strategy

## 4.1 Secret Classes

1. **Transport secrets**: JWT signing keys, mTLS CA/intermediate keys.
2. **At-rest data keys**: envelope/KMS keys for encrypted payload columns.
3. **Operational tokens**: agent registration/rotation tokens.

## 4.2 Storage Policy

- No plaintext secrets in PostgreSQL.
- Panel DB may store only encrypted blobs + key references (`key_id`, `algo`, `nonce`).
- Root of trust resides in external KMS/HSM/Vault.

## 4.3 Rotation and Revocation

- JWT signing keys rotated on schedule (e.g., 90 days) with overlapping `kid` validity.
- mTLS cert rotation with short-lived certs (e.g., <=30 days) and CRL/OCSP checks.
- Agent compromise playbook: immediate cert revocation + JWT audience denylist + forced re-registration.

## 4.4 Agent Secret Hygiene

- Secrets stored only in root-readable files or OS keyring, minimal scope.
- Never log secret values.
- Process memory scrubbing where practical for transient secret material.

---

## 5) Retention & Purge Policy

Retention is table-class based, centrally configured, and executed by idempotent purge jobs.

## 5.1 Baseline Retention Matrix

- Security audit events: **400 days** (or legal override).
- Operational telemetry raw: **30-90 days** by feature.
- Aggregated telemetry: **400-730 days**.
- Incident/enforcement records: **400 days**.
- Idempotency/replay nonce cache records: **7-30 days**.

## 5.2 Purge Execution Rules

1. Purge jobs are deterministic and day-bounded (`DELETE ... WHERE created_at < cutoff`).
2. Purge order honors FK dependencies (child before parent).
3. Purge jobs emit audit events including deleted row counts and checksum before/after.
4. Purge re-run is safe (idempotent).

## 5.3 Legal Hold

- Tenant or global legal hold flag blocks purge for covered datasets.
- Holds are auditable and include actor, reason, start, optional expiry.

## 5.4 Backup and Restore Governance

- Backups encrypted with dedicated backup key hierarchy.
- Restore operations require dual-control approval and mandatory audit events.

---

## 6) GDPR-Compliant Tenant Data Export/Delete

## 6.1 Tenant Data Export

Endpoint family (logical):
- `POST /api/v1/admin/tenants/{ownerId}/export`
- `GET /api/v1/admin/tenants/{ownerId}/export/{jobId}`

Requirements:
1. Export is owner-scoped and deterministic snapshot at `as_of` timestamp.
2. Archive format documented (JSONL/CSV + manifest).
3. Manifest includes schema versions and checksums.
4. Export artifact encrypted and time-limited download URL.
5. Full audit trail from request to download.

## 6.2 Tenant Data Delete / Erasure

Endpoint family (logical):
- `POST /api/v1/admin/tenants/{ownerId}/erase`

Workflow:
1. Preflight impact report (records by table, legal hold checks).
2. Two-step confirmation (request + approve).
3. Deterministic deletion order per data domain.
4. Tombstone minimal legal metadata if required by policy.
5. Post-delete verification report + audit event.

Deletion semantics:
- Hard-delete where legally permitted.
- Pseudonymize/anonymize where retention obligations conflict.

---

## 7) Threat Model (v1)

## 7.1 Assets

- Tenant data (mail metadata, incidents, policies).
- Control-plane policy state and config snapshots.
- Signing/transport secrets and trust anchors.
- Audit trail integrity.

## 7.2 Adversaries

1. External attacker targeting APIs.
2. Compromised agent node.
3. Malicious/curious tenant actor.
4. Insider with privileged access.

## 7.3 Key Threats and Mitigations

1. **Cross-tenant data access**
   - Mitigation: owner-scoped repositories + optional RLS + 404 masking.

2. **Replay of telemetry/control requests**
   - Mitigation: nonce + timestamp + JWT `jti` + idempotency caches.

3. **Agent impersonation**
   - Mitigation: mTLS cert validation + JWT identity binding + cert revocation.

4. **Audit tampering**
   - Mitigation: append-only audit semantics, hash chaining optional extension, restricted write path.

5. **Secrets leakage**
   - Mitigation: KMS-backed envelope encryption, no plaintext DB secrets, redacted logging.

6. **Purge abuse / destructive actions**
   - Mitigation: dual control for high-risk operations + mandatory audit + legal hold checks.

## 7.4 Security Control Ownership

- Panel: identity, authorization, idempotency, audit, governance workflows.
- Agent: secure transport client behavior, strict command execution, telemetry integrity.
- DB: constraints, indexes, optional RLS, retention execution support.

---

## 8) Acceptance Criteria

1. All tenant-bound endpoints prove owner scoping in query path and tests.
2. Mutating endpoints reject missing `Idempotency-Key`.
3. Telemetry ingestion rejects requests failing either mTLS or JWT validation.
4. Replay attempts (nonce/jti/idempotency reuse) are blocked deterministically.
5. Sensitive operations create audit events with `actor_id`, `correlation_id`, `before_hash`, `after_hash`.
6. Retention jobs delete only data older than policy cutoff and are idempotent.
7. Tenant export produces deterministic manifest/checksum for same `as_of` snapshot.
8. Tenant delete workflow enforces preflight + approval + auditable completion.
9. Threat model controls are traceable to implementation tasks/tests.

---

## 9) Implementation Guardrails and Verification Tests

Required tests:
1. Owner-scope negative tests (cross-tenant read/update/delete blocked).
2. Idempotency replay tests for all mutating endpoints.
3. mTLS/JWT matrix tests (must fail on single-factor pass).
4. Replay nonce/jti/timestamp window tests.
5. Audit completeness tests for governance-sensitive operations.
6. Retention purge replay tests (second run yields zero additional deletions).
7. GDPR export/delete end-to-end tests with verification manifests.

Determinism guarantees:
- Security decisions depend only on explicit inputs, policy state, and bounded UTC windows.
- No random/jitter in authorization/idempotency/replay decisions.
- Canonical hashing and structured event ordering provide reproducible audit outcomes.

