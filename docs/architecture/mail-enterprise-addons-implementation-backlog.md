# Enterprise Mail Hosting Addons — Implementation Backlog (GitHub-Issue Style)

This backlog converts the architecture specs into implementation issues.

Global sequencing model:
- **First**: blocking foundations and minimal production path.
- **Next**: scale, hardening, and operator UX.
- **Later**: advanced controls and optimizations.

Issue template fields used below:
- **Title**
- **Description**
- **Tasks (DB / API / Agent / Jobs)**
- **Dependencies**
- **Acceptance Criteria**
- **Risk Notes**
- **Labels**

---

## Epic 1 — Rspamd Integration

### First

#### Issue RSP-01 — Create `mail_rspamd_policies` and `mail_rspamd_stats_buckets` migrations
- **Description:** Introduce deterministic DB schema, indexes, constraints, and retention metadata for policy + stats.
- **Tasks:**
  - **DB:** Add both tables, unique/partial indexes, check constraints, UTC timestamp defaults.
  - **API:** N/A.
  - **Agent:** N/A.
  - **Jobs:** Add purge job metadata for bucket retention windows.
- **Dependencies:** None.
- **Acceptance Criteria:** Migrations apply/rollback cleanly; duplicate scope rows prevented; basis-point columns enforced.
- **Risk Notes:** Migration locking on large installs.
- **Labels:** `backend`, `db`, `ops`

#### Issue RSP-02 — Implement effective policy resolver (mailbox > domain > global)
- **Description:** Add deterministic resolver service that materializes effective policy for every domain/mailbox.
- **Tasks:**
  - **DB:** Query paths with strict `owner_id` filtering.
  - **API:** Expose resolver output in policy read endpoints.
  - **Agent:** N/A.
  - **Jobs:** Resolver invoked by snapshot generation job.
- **Dependencies:** RSP-01.
- **Acceptance Criteria:** Tie-break ordering stable; same DB state yields identical resolved payload.
- **Risk Notes:** Incorrect precedence can cause false reject/allow behavior.
- **Labels:** `backend`, `api`, `security`

### Next

#### Issue RSP-03 — Build admin/customer policy endpoints with idempotency
- **Description:** Implement PUT policy endpoints and admin stats/overview reads.
- **Tasks:**
  - **DB:** Idempotency-key store + audit event writes.
  - **API:** `GET overview`, `GET stats`, `PUT admin/customer policy`.
  - **Agent:** N/A.
  - **Jobs:** Dispatch `mail.applyRspamdPolicy` on successful mutation.
- **Dependencies:** RSP-01, RSP-02.
- **Acceptance Criteria:** Replayed request with same key returns same result; conflict on body mismatch.
- **Risk Notes:** Missing idempotency opens duplicate jobs.
- **Labels:** `backend`, `api`, `security`

#### Issue RSP-04 — Implement agent renderer for `actions.conf` and `multimap.conf`
- **Description:** Deterministic render engine and map file writer with stable sorting and newline normalization.
- **Tasks:**
  - **DB:** N/A.
  - **API:** Snapshot contract fields finalized.
  - **Agent:** Render files + stable float formatting from basis points.
  - **Jobs:** Handle `mail.applyConfig` and `mail.applyRspamdPolicy` apply paths.
- **Dependencies:** RSP-02, RSP-03.
- **Acceptance Criteria:** Golden-file tests pass; permutation of input rows yields identical bytes.
- **Risk Notes:** Drift between panel and agent schema versions.
- **Labels:** `agent`, `backend`, `ops`

### Later

#### Issue RSP-05 — Add configtest/reload rollback orchestration and telemetry ingest
- **Description:** Finalize controlled activation flow with rollback and stats batch ingestion.
- **Tasks:**
  - **DB:** Persist apply outcomes + stats buckets dedupe.
  - **API:** `POST /api/v1/agent/mail/rspamd/stats/batch`.
  - **Agent:** `rspamadm configtest`, reload, rollback paths.
  - **Jobs:** Retry/reconcile failed apply jobs.
- **Dependencies:** RSP-04.
- **Acceptance Criteria:** Failed reload auto-rolls back; dedupe key prevents duplicate stats buckets.
- **Risk Notes:** Partial activation during service reload failures.
- **Labels:** `agent`, `backend`, `api`, `ops`, `security`

**Definition of Done (Epic Rspamd Integration):**
- Deterministic policy resolution and rendering proven by golden/permutation tests.
- Mutating APIs enforce idempotency and audit logging.
- Agent apply pipeline has validated rollback behavior in integration tests.

---

## Epic 2 — DMARC Parser

### First

#### Issue DMARC-01 — Implement raw + normalized + daily aggregate schema migrations
- **Description:** Add `mail_dmarc_reports_raw`, `mail_dmarc_report_rows`, `mail_dmarc_daily_agg`.
- **Tasks:**
  - **DB:** Constraints, dedupe on `(report_id, source_file_hash, owner_id)`, indexes.
  - **API:** N/A.
  - **Agent:** N/A.
  - **Jobs:** Retention job definitions.
- **Dependencies:** None.
- **Acceptance Criteria:** Dedupe uniqueness enforced; retention cutoffs configurable.
- **Risk Notes:** Large-row-volume indexing cost.
- **Labels:** `db`, `backend`, `ops`

#### Issue DMARC-02 — Build secure XML parser worker
- **Description:** Safe XML parse with XXE disabled, depth/size limits, gzip/zip handling.
- **Tasks:**
  - **DB:** Persist parse status/error taxonomy.
  - **API:** N/A.
  - **Agent:** N/A.
  - **Jobs:** `mail.dmarc.parse_report` worker with restart-safe transitions.
- **Dependencies:** DMARC-01.
- **Acceptance Criteria:** Malformed payloads fail deterministically; parser restart does not duplicate rows.
- **Risk Notes:** XML bombs and decompression attacks.
- **Labels:** `backend`, `security`, `ops`

### Next

#### Issue DMARC-03 — Implement ingest channels (mailbox + webhook normalization)
- **Description:** Support mailbox-ingested and webhook-ingested raw artifacts through identical pipeline.
- **Tasks:**
  - **DB:** Store ingest channel/node metadata.
  - **API:** Internal ingest endpoint with idempotency.
  - **Agent:** Mailbox collector posts artifacts.
  - **Jobs:** `mail.dmarc.ingest_raw` dispatcher.
- **Dependencies:** DMARC-01, DMARC-02.
- **Acceptance Criteria:** Same raw payload via either channel yields identical normalized rows.
- **Risk Notes:** Inconsistent metadata extraction between channels.
- **Labels:** `backend`, `agent`, `api`, `security`

#### Issue DMARC-04 — Implement daily aggregation + recompute worker
- **Description:** Deterministic day rollups and recompute for selected ranges.
- **Tasks:**
  - **DB:** Upsert daily agg with stable top-source JSON ordering.
  - **API:** Admin recompute trigger (internal/operator endpoint).
  - **Agent:** N/A.
  - **Jobs:** `mail.dmarc.aggregate_daily`, `mail.dmarc.recompute_day`.
- **Dependencies:** DMARC-02.
- **Acceptance Criteria:** Recompute output is byte-identical for same input rows.
- **Risk Notes:** Cross-day split rounding errors.
- **Labels:** `backend`, `db`, `ops`

### Later

#### Issue DMARC-05 — Deliver admin/customer DMARC reporting endpoints
- **Description:** Implement reports/sources/overview endpoints with owner scoping.
- **Tasks:**
  - **DB:** Query optimization and pagination indexes.
  - **API:** Admin reports + sources, customer domain overview.
  - **Agent:** N/A.
  - **Jobs:** Optional cache warmup job.
- **Dependencies:** DMARC-04.
- **Acceptance Criteria:** Tenant isolation verified by negative tests; response stability under replay.
- **Risk Notes:** Expensive wide-range queries.
- **Labels:** `backend`, `api`, `security`

**Definition of Done (Epic DMARC Parser):**
- Secure parser passes adversarial payload tests.
- Dedupe, recompute, and retention jobs are deterministic and idempotent.
- Admin/customer endpoints are owner-scoped and performance-tested.

---

## Epic 3 — Bounce Engine

### First

#### Issue BNC-01 — Create bounce schema migrations
- **Description:** Add `mail_bounce_events` and `mail_bounce_reason_map` with constraints/indexes.
- **Tasks:**
  - **DB:** Event dedupe keys, map rule uniqueness, provider override columns.
  - **API:** N/A.
  - **Agent:** N/A.
  - **Jobs:** Retention job metadata.
- **Dependencies:** None.
- **Acceptance Criteria:** Duplicate event insert blocked by unique constraints.
- **Risk Notes:** High-cardinality diagnostics impacting storage.
- **Labels:** `db`, `backend`, `ops`

#### Issue BNC-02 — Build deterministic classifier service
- **Description:** Implement pattern + enhanced code + SMTP class resolution chain with tie-breakers.
- **Tasks:**
  - **DB:** Fetch rule maps in deterministic order.
  - **API:** N/A.
  - **Agent:** N/A.
  - **Jobs:** Classifier worker callable from ingest path.
- **Dependencies:** BNC-01.
- **Acceptance Criteria:** Same input + ruleset version yields same reason tuple.
- **Risk Notes:** Regex performance and ambiguous matches.
- **Labels:** `backend`, `security`

### Next

#### Issue BNC-03 — Implement agent bounce batch ingest endpoint
- **Description:** Receive batch events with request-level + event-level idempotency.
- **Tasks:**
  - **DB:** Idempotency ledger and hash dedupe support.
  - **API:** `POST /api/v1/agent/mail/bounces/batch`.
  - **Agent:** Emit canonical event schema.
  - **Jobs:** Async normalization queue.
- **Dependencies:** BNC-02.
- **Acceptance Criteria:** Batch replay produces deduplicated results without drift.
- **Risk Notes:** Large batch handling and partial failures.
- **Labels:** `api`, `agent`, `backend`, `security`

#### Issue BNC-04 — Add admin bounces/reasons read endpoints
- **Description:** Build query endpoints with pagination and owner-scoped filters.
- **Tasks:**
  - **DB:** Query plans and covering indexes.
  - **API:** `GET /api/v1/admin/mail/bounces`, `GET .../reasons`.
  - **Agent:** N/A.
  - **Jobs:** N/A.
- **Dependencies:** BNC-03.
- **Acceptance Criteria:** Filtering by rule/provider/time works with stable totals.
- **Risk Notes:** Expensive aggregations at long ranges.
- **Labels:** `api`, `backend`, `db`

### Later

#### Issue BNC-05 — Implement reason-map CRUD with compile-time regex validation
- **Description:** Add admin CRUD for mapping table and audit all mutations.
- **Tasks:**
  - **DB:** Versioning/status fields and soft-delete behavior.
  - **API:** CRUD endpoints requiring `Idempotency-Key`.
  - **Agent:** N/A.
  - **Jobs:** Rule cache invalidation job.
- **Dependencies:** BNC-02.
- **Acceptance Criteria:** Invalid regex rejected at write time; audit record written for each change.
- **Risk Notes:** Unsafe patterns causing CPU spikes if unchecked.
- **Labels:** `api`, `backend`, `security`, `ops`

**Definition of Done (Epic Bounce Engine):**
- Classifier determinism validated with golden/replay tests.
- Ingest path is idempotent and robust under retries.
- Admin mapping and analytics endpoints are auditable and owner-scoped.

---

## Epic 4 — IP Reputation Monitor

### First

#### Issue REP-01 — Create reputation checks/scores schema migrations
- **Description:** Add `mail_ip_reputation_checks` and `mail_ip_reputation_scores` tables.
- **Tasks:**
  - **DB:** Window uniqueness and fixed-point score fields.
  - **API:** N/A.
  - **Agent:** N/A.
  - **Jobs:** Retention metadata.
- **Dependencies:** None.
- **Acceptance Criteria:** Duplicate check attempts per window prevented by constraints.
- **Risk Notes:** Write amplification from resolver fan-out.
- **Labels:** `db`, `backend`, `ops`

#### Issue REP-02 — Implement deterministic scheduler + `mail.reputationCheck`
- **Description:** Panel creates node-window jobs with deterministic target ordering.
- **Tasks:**
  - **DB:** Dispatch log/idempotency key table.
  - **API:** Internal dispatch controls.
  - **Agent:** N/A.
  - **Jobs:** `mail.reputationCheck` producer.
- **Dependencies:** REP-01.
- **Acceptance Criteria:** Same window + inventory yields identical job payload order.
- **Risk Notes:** Clock skew around window boundaries.
- **Labels:** `backend`, `ops`

### Next

#### Issue REP-03 — Build agent DNSBL resolver-quorum execution + batch reporting
- **Description:** Agent executes lookups against fixed resolvers and reports resolver-level evidence.
- **Tasks:**
  - **DB:** Persist resolver responses and hashes.
  - **API:** `POST /api/v1/agent/mail/reputation/checks:batch`.
  - **Agent:** Quorum logic, deterministic retry/backoff.
  - **Jobs:** Ingest normalization worker.
- **Dependencies:** REP-02.
- **Acceptance Criteria:** Inconclusive/confirmed outcomes match quorum thresholds exactly.
- **Risk Notes:** Resolver variance, DNS poisoning attempts.
- **Labels:** `agent`, `api`, `backend`, `security`

#### Issue REP-04 — Implement slope degradation scoring + trend classes
- **Description:** Compute rolling score windows and classify stable/warning/critical.
- **Tasks:**
  - **DB:** Upsert score rows per `(owner,ip,window)`.
  - **API:** N/A.
  - **Agent:** N/A.
  - **Jobs:** Score aggregation worker.
- **Dependencies:** REP-03.
- **Acceptance Criteria:** Fixed input windows produce deterministic slope/trend.
- **Risk Notes:** Regression arithmetic bugs in fixed-point math.
- **Labels:** `backend`, `db`

### Later

#### Issue REP-05 — Deliver admin reputation and listings endpoints
- **Description:** Implement operator visibility APIs and filters.
- **Tasks:**
  - **DB:** Query index tuning for listings.
  - **API:** `GET /api/v1/admin/mail/reputation`, `/listings`.
  - **Agent:** N/A.
  - **Jobs:** Optional materialized cache refresh.
- **Dependencies:** REP-04.
- **Acceptance Criteria:** Endpoints align with score/check evidence and pass owner-scope tests.
- **Risk Notes:** Heavy pagination under long ranges.
- **Labels:** `api`, `backend`, `db`, `ops`

**Definition of Done (Epic IP Reputation Monitor):**
- Deterministic windowing and quorum outcomes validated.
- No duplicate checks per window in storage.
- Trend scoring and admin visibility are consistent and audited.

---

## Epic 5 — Warmup Mode

### First

#### Issue WRM-01 — Create warmup plan/event schema migrations
- **Description:** Add `mail_warmup_plans` and `mail_warmup_events` with state machine constraints.
- **Tasks:**
  - **DB:** Plan profiles, state enum (`active|paused|done`), bypass TTL fields.
  - **API:** N/A.
  - **Agent:** N/A.
  - **Jobs:** Retention metadata for warmup events.
- **Dependencies:** None.
- **Acceptance Criteria:** Plan states and cap invariants enforceable by DB checks.
- **Risk Notes:** Incorrect transition constraints.
- **Labels:** `db`, `backend`

#### Issue WRM-02 — Implement cap evaluator (`effective_cap = min(policy_limit, warmup_limit)`)
- **Description:** Deterministic cap computation for outbound controls.
- **Tasks:**
  - **DB:** N/A.
  - **API:** Expose effective cap in plan reads.
  - **Agent:** N/A.
  - **Jobs:** Used by warmup tick job.
- **Dependencies:** WRM-01.
- **Acceptance Criteria:** Unit tests cover all cap combinations and profile states.
- **Risk Notes:** Misapplied cap can throttle legitimate traffic.
- **Labels:** `backend`, `api`

### Next

#### Issue WRM-03 — Implement warmup plan APIs and emergency bypass
- **Description:** Add create/update/overview endpoints, bypass with TTL + audit.
- **Tasks:**
  - **DB:** Idempotency and bypass audit fields.
  - **API:** plan CRUD + overview.
  - **Agent:** N/A.
  - **Jobs:** Dispatch `mail.warmupTick` on plan activation changes.
- **Dependencies:** WRM-02.
- **Acceptance Criteria:** Bypass auto-expires and reverts deterministically.
- **Risk Notes:** Long-lived bypass if TTL not enforced.
- **Labels:** `api`, `backend`, `security`

#### Issue WRM-04 — Integrate warmup state into agent snapshot + atomic increments
- **Description:** Include effective caps in snapshot and enforce atomic send counters in agent.
- **Tasks:**
  - **DB:** Snapshot materialization additions.
  - **API:** Agent snapshot contract update.
  - **Agent:** Atomic increments/counters and limit checks.
  - **Jobs:** Reconcile drift worker.
- **Dependencies:** WRM-03.
- **Acceptance Criteria:** Concurrent send attempts cannot exceed effective cap.
- **Risk Notes:** Race conditions in counter updates.
- **Labels:** `agent`, `backend`, `api`

### Later

#### Issue WRM-05 — Add `mail.warmupTick` scheduler with profile engines
- **Description:** Implement linear/step/exponential ramp engines and state transitions.
- **Tasks:**
  - **DB:** Persist per-tick events and next effective limit.
  - **API:** N/A.
  - **Agent:** N/A.
  - **Jobs:** `mail.warmupTick` queue and retry semantics.
- **Dependencies:** WRM-04.
- **Acceptance Criteria:** Tick replay is idempotent; profile outputs deterministic per day.
- **Risk Notes:** Timezone/dst errors if not strictly UTC.
- **Labels:** `backend`, `db`, `ops`

**Definition of Done (Epic Warmup Mode):**
- Effective cap invariant enforced end-to-end.
- Warmup ticks are deterministic and idempotent.
- Emergency bypass is auditable, bounded, and auto-reverting.

---

## Epic 6 — Abuse Detection

### First

#### Issue ABS-01 — Create abuse rules/incidents schema migrations
- **Description:** Add `mail_abuse_rules` and `mail_abuse_incidents` with deterministic dedupe.
- **Tasks:**
  - **DB:** Rule constraints, incident fingerprint uniqueness.
  - **API:** N/A.
  - **Agent:** N/A.
  - **Jobs:** Incident retention metadata.
- **Dependencies:** None.
- **Acceptance Criteria:** Duplicate incident per scope/window blocked.
- **Risk Notes:** Fingerprint schema drift.
- **Labels:** `db`, `backend`

#### Issue ABS-02 — Implement deterministic rule evaluator for four v1 rules
- **Description:** Evaluate auth failure, bounce rate, fanout anomaly, DKIM fail with fixed ordering.
- **Tasks:**
  - **DB:** Deterministic rule fetch ordering.
  - **API:** N/A.
  - **Agent:** N/A.
  - **Jobs:** `abuse.evaluateWindow` consumer.
- **Dependencies:** ABS-01.
- **Acceptance Criteria:** Golden tests stable under row permutation.
- **Risk Notes:** Incorrect baseline math causing false positives.
- **Labels:** `backend`, `security`

### Next

#### Issue ABS-03 — Implement enforcement router via `mail.applyConfig`
- **Description:** Map action to alert/throttle/block and dispatch apply jobs only from panel.
- **Tasks:**
  - **DB:** Store enforcement status/version on incidents.
  - **API:** Internal enforcement commands.
  - **Agent:** Apply config only (no local policy mutation).
  - **Jobs:** `mail.applyConfig` dispatch + ack handling.
- **Dependencies:** ABS-02.
- **Acceptance Criteria:** No enforcement action occurs without apply job trace.
- **Risk Notes:** Delayed apply may prolong abuse window.
- **Labels:** `backend`, `agent`, `security`, `ops`

#### Issue ABS-04 — Add rule/incident admin APIs + manual resolve
- **Description:** CRUD/list endpoints with idempotency and audit.
- **Tasks:**
  - **DB:** Query indexes for filters/time ranges.
  - **API:** rule CRUD + incident list/resolve.
  - **Agent:** N/A.
  - **Jobs:** N/A.
- **Dependencies:** ABS-03.
- **Acceptance Criteria:** Missing Idempotency-Key rejected on mutating routes.
- **Risk Notes:** Manual overrides bypassing intended controls.
- **Labels:** `api`, `backend`, `security`

### Later

#### Issue ABS-05 — Add cooldown/suppression and expiry auto-revert jobs
- **Description:** Prevent flapping and auto-revert temporary controls when TTL ends.
- **Tasks:**
  - **DB:** Suppression state + expiry timestamps.
  - **API:** N/A.
  - **Agent:** N/A.
  - **Jobs:** cooldown evaluator + expiry reverter.
- **Dependencies:** ABS-03.
- **Acceptance Criteria:** Repeated triggers in cooldown become suppressed incidents.
- **Risk Notes:** Missed expiry can leave unintended throttles.
- **Labels:** `backend`, `db`, `ops`

**Definition of Done (Epic Abuse Detection):**
- Rule evaluator deterministic and replay-safe.
- Enforcement strictly panel-issued and fully auditable.
- Cooldown/expiry lifecycle validated in end-to-end tests.

---

## Epic 7 — ARC Support

### First

#### Issue ARC-01 — Implement `mail_arc_keys` + `mail_domains` ARC fields migrations
- **Description:** Add metadata-only ARC schema and domain mode fields.
- **Tasks:**
  - **DB:** Constraints for selector uniqueness and mode consistency.
  - **API:** N/A.
  - **Agent:** N/A.
  - **Jobs:** N/A.
- **Dependencies:** None.
- **Acceptance Criteria:** Only one active key per domain; canary percent bounded.
- **Risk Notes:** Migration ordering with existing domain table changes.
- **Labels:** `db`, `backend`

#### Issue ARC-02 — Build enable/rotate/dns APIs with idempotency and audit
- **Description:** Implement customer-facing lifecycle APIs.
- **Tasks:**
  - **DB:** Idempotency key records, rotation state fields.
  - **API:** enable, rotate, dns output.
  - **Agent:** N/A.
  - **Jobs:** queue keygen/apply operations.
- **Dependencies:** ARC-01.
- **Acceptance Criteria:** Replay-safe enable/rotate; cross-tenant access masked.
- **Risk Notes:** Rotation overlap misconfiguration.
- **Labels:** `api`, `backend`, `security`

### Next

#### Issue ARC-03 — Implement node-local key generation and metadata return
- **Description:** Agent generates private key locally, returns public key only.
- **Tasks:**
  - **DB:** Store public key + deterministic key path metadata.
  - **API:** Agent callback endpoint for key metadata update.
  - **Agent:** fixed-command keygen and key path checks.
  - **Jobs:** keygen job runner.
- **Dependencies:** ARC-02.
- **Acceptance Criteria:** No private key material appears in DB/logs.
- **Risk Notes:** File path traversal bugs.
- **Labels:** `agent`, `backend`, `security`, `ops`

#### Issue ARC-04 — Add deterministic OpenARC renderer + config validation/reload
- **Description:** Generate KeyTable/SigningTable/TrustedHosts and handle validation/reload flow.
- **Tasks:**
  - **DB:** Snapshot inputs for ARC state.
  - **API:** Snapshot schema updates.
  - **Agent:** deterministic render, `openarc -n -c ...`, reload + health check.
  - **Jobs:** integrate with `mail.applyConfig`.
- **Dependencies:** ARC-03.
- **Acceptance Criteria:** Same snapshot renders byte-identical files; failed reload triggers rollback.
- **Risk Notes:** OpenARC runtime variance by distro.
- **Labels:** `agent`, `backend`, `ops`, `security`

### Later

#### Issue ARC-05 — Implement canary sampling and selector rollback automation
- **Description:** Deterministic canary percentage gating and automatic fallback on failures.
- **Tasks:**
  - **DB:** Track apply errors and fallback TTL.
  - **API:** expose canary and fallback state.
  - **Agent:** apply canary decision from panel-derived function.
  - **Jobs:** fallback/retry orchestrator.
- **Dependencies:** ARC-04.
- **Acceptance Criteria:** Canary bucket function deterministic; failed promotion reverts selector.
- **Risk Notes:** Message-id absence affecting hash source.
- **Labels:** `backend`, `agent`, `security`, `ops`

**Definition of Done (Epic ARC Support):**
- ARC key lifecycle works with metadata-only panel storage.
- Node-local key and permission guarantees validated.
- Deterministic render/apply/rollback confirmed in integration tests.

---

## Epic 8 — Security & Governance

### First

#### Issue SEC-01 — Enforce owner-scoped repository pattern across mail modules
- **Description:** Refactor repositories/services to require owner-scoped methods.
- **Tasks:**
  - **DB:** Add/verify `(owner_id, ...)` indexes.
  - **API:** Ensure tenant endpoints pass owner context explicitly.
  - **Agent:** N/A.
  - **Jobs:** N/A.
- **Dependencies:** None.
- **Acceptance Criteria:** Cross-tenant access tests fail closed (404 masking).
- **Risk Notes:** Legacy queries bypassing scoped methods.
- **Labels:** `backend`, `db`, `security`

#### Issue SEC-02 — Implement global idempotency middleware for mutating endpoints
- **Description:** Centralized idempotency-key validation, hashing, replay return, and conflict handling.
- **Tasks:**
  - **DB:** Idempotency ledger table with TTL.
  - **API:** Apply middleware to POST/PUT/PATCH/DELETE routes.
  - **Agent:** Include keys on all mutating callbacks.
  - **Jobs:** cleanup job for expired idempotency entries.
- **Dependencies:** SEC-01.
- **Acceptance Criteria:** Identical replay returns same payload/status; divergent replay returns conflict.
- **Risk Notes:** Ledger growth without cleanup.
- **Labels:** `backend`, `api`, `security`, `ops`

### Next

#### Issue SEC-03 — Implement mTLS + JWT binding for panel-agent channels
- **Description:** Require both certificate and token identity match for all agent endpoints.
- **Tasks:**
  - **DB:** Store node trust metadata and revocation state.
  - **API:** mTLS authn filter + JWT verifier + identity binding check.
  - **Agent:** certificate presentation + JWT signing/refresh.
  - **Jobs:** certificate/JWT rotation jobs.
- **Dependencies:** SEC-02.
- **Acceptance Criteria:** Requests fail if either factor invalid; mismatch identity rejected.
- **Risk Notes:** Operational outages during cert rollover.
- **Labels:** `security`, `api`, `agent`, `ops`

#### Issue SEC-04 — Add replay protection (nonce, jti, timestamp window)
- **Description:** Prevent replay attacks on ingestion/control APIs.
- **Tasks:**
  - **DB:** nonce/jti cache store with TTL and indexes.
  - **API:** verify timestamp skew and uniqueness checks.
  - **Agent:** include nonce/timestamp/jti claims.
  - **Jobs:** nonce cache purge.
- **Dependencies:** SEC-03.
- **Acceptance Criteria:** Reused nonce/jti rejected deterministically in test matrix.
- **Risk Notes:** Time sync drift causing false rejects.
- **Labels:** `security`, `api`, `agent`, `backend`

#### Issue SEC-05 — Build canonical audit event pipeline and schema
- **Description:** Implement `audit_events` table, writer service, and mandatory hooks.
- **Tasks:**
  - **DB:** create schema/indexes.
  - **API:** write audit entries for governance-sensitive endpoints.
  - **Agent:** include correlation IDs for apply/report actions.
  - **Jobs:** retention/purge + integrity verification job.
- **Dependencies:** SEC-02.
- **Acceptance Criteria:** Sensitive operations emit complete before/after hash records.
- **Risk Notes:** Missing hooks reducing forensic quality.
- **Labels:** `backend`, `db`, `security`, `ops`

### Later

#### Issue SEC-06 — Implement GDPR export/delete workflows and legal hold controls
- **Description:** Add deterministic owner export and two-step erasure with reporting.
- **Tasks:**
  - **DB:** legal hold flags + deletion manifests.
  - **API:** export job endpoints and erase approval flow.
  - **Agent:** N/A.
  - **Jobs:** export generator + erase executor + verification.
- **Dependencies:** SEC-05.
- **Acceptance Criteria:** Export manifest checksums deterministic; erase flow fully audited.
- **Risk Notes:** Regulatory/legal conflict across regions.
- **Labels:** `backend`, `api`, `db`, `security`, `ops`

**Definition of Done (Epic Security & Governance):**
- Owner scoping, idempotency, and replay controls enforced platform-wide.
- mTLS+JWT binding and audit coverage proven by tests.
- Retention and GDPR workflows operational, auditable, and policy-compliant.

---

## Suggested Global Implementation Order (Cross-Epic)

### First
- RSP-01, RSP-02
- DMARC-01, DMARC-02
- BNC-01, BNC-02
- REP-01, REP-02
- WRM-01, WRM-02
- ABS-01, ABS-02
- ARC-01, ARC-02
- SEC-01, SEC-02

### Next
- RSP-03, RSP-04
- DMARC-03, DMARC-04
- BNC-03, BNC-04
- REP-03, REP-04
- WRM-03, WRM-04
- ABS-03, ABS-04
- ARC-03, ARC-04
- SEC-03, SEC-04, SEC-05

### Later
- RSP-05
- DMARC-05
- BNC-05
- REP-05
- WRM-05
- ABS-05
- ARC-05
- SEC-06

