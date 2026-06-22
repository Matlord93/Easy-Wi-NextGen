# Mail Hosting Enterprise Addons Roadmap (MUST HAVE #10, Spec-First)

## Scope & Principles

This document defines concrete, implementation-ready specs (without full code implementation) for enterprise mail-hosting addons on top of the current control plane.

Hard constraints:
- DB is source of truth.
- Agent renders configs deterministically from DB snapshot.
- No shell injection (`exec.CommandContext` with fixed args only, no `sh -c`).
- DKIM private keys are node-local only (`0600 root:opendkim`), never stored in DB.
- Multi-tenant isolation by `owner_id` / domain scoping in every query and API.

---

## 1) Rspamd Integration (Policy + Stats)

### (1) Design / Spec
- Add Rspamd as optional inbound and outbound filtering plane.
- Per-domain and per-mailbox policy hooks map from panel policy objects to Rspamd symbols/settings (thresholds, actions, whitelist/blacklist, force actions).
- Agent writes deterministic Rspamd local.d maps and policy snippets from snapshot.
- Enforcement points:
  - Postfix milter/rspamd proxy for SMTP ingress.
  - Optional outbound check path for submission traffic.

### (2) Data model additions
- `mail_rspamd_policies`
  - `id`, `domain_id`, `mailbox_id nullable`, `owner_id`
  - `reject_score` (float), `add_header_score` (float), `greylist_score` (float)
  - `action_profile` (`strict|balanced|lenient`)
  - `symbols_override jsonb` (bounded keys)
  - `enabled bool`, `created_at`, `updated_at`
- `mail_rspamd_stats_buckets`
  - `bucket_start`, `bucket_size_seconds`, `domain_id nullable`, `owner_id`
  - `messages_scanned`, `messages_rejected`, `messages_greylisted`, `avg_score`, `p95_score`
- Indexing:
  - `(domain_id, mailbox_id)` unique for effective policy
  - `(bucket_start, domain_id)` for dashboard windows

### (3) Panel endpoints / DTO
- `GET /api/v1/admin/mail/rspamd/overview?from=&to=`
- `GET /api/v1/admin/mail/rspamd/stats?domain=&bucket=`
- `PUT /api/v1/admin/mail/rspamd/policy/{domainId}`
- DTO:
  - `reject_score`, `add_header_score`, `greylist_score`, `action_profile`, `symbols_override`

### (4) Agent contract
- Snapshot contains effective Rspamd policy per domain/mailbox.
- Render targets:
  - `/etc/rspamd/local.d/worker-proxy.inc`
  - `/etc/rspamd/local.d/actions.conf`
  - `/etc/rspamd/local.d/multimap.conf` (+ generated map files)
- Validation command through fixed args only (`rspamadm configtest`), then controlled reload.
- Telemetry export includes counters + score distribution.

### (5) Risks
- Symbol override explosion (cardinality). Mitigation: allowlist symbol names + hard limits.
- False positives on strict profiles. Mitigation: staged rollout + per-domain dry-run mode.

---

## 2) ARC Support

### (1) Design / Spec
- ARC signing for forwarded messages to preserve authentication chain across hops.
- Implement via OpenARC (or Rspamd ARC module if Rspamd-first deployment standardizes there).
- Selector + key lifecycle similar to DKIM but logically separate (`arc_selector`).

### (2) Data model additions
- `mail_arc_keys`
  - `id`, `domain_id`, `owner_id`
  - `selector`, `public_key`, `private_key_path` (node-local metadata only)
  - `status` (`active|rotating|retired`), `created_at`, `rotated_at nullable`
- `mail_domains` extension:
  - `arc_enabled bool default false`
  - `arc_status string`

### (3) Panel endpoints / DTO
- `POST /api/v1/customer/mail/domains/{id}/arc/enable`
- `POST /api/v1/customer/mail/domains/{id}/arc/rotate`
- `GET /api/v1/customer/mail/domains/{id}/arc/dns`

### (4) Agent contract
- Deterministic render for OpenARC:
  - KeyTable/SigningTable/TrustedHosts
- Validation with fixed-arg config checks, reload only after successful lint.
- Key material generation on node, private key file perms `0600 root:opendkim` (or openarc group equivalent where required).

### (5) Risks
- Misaligned DKIM/ARC canonicalization causing delivery regressions.
- Mitigation: canary enablement and per-domain rollback switch.

---

## 3) DMARC Report Parser (RUA ingest + aggregates)

### (1) Design / Spec
- Ingest RUA aggregate reports (XML attachments via dedicated mailbox/webhook ingestion pipeline).
- Normalize into daily rollups per source IP / SPF / DKIM disposition and alignment status.
- Provide domain-level dashboard and anomaly hints (sudden fail spikes).

### (2) Data model additions
- `mail_dmarc_reports_raw`
  - `id`, `owner_id`, `domain_id`, `report_id`, `org_name`, `period_start`, `period_end`
  - `source_file_hash`, `ingested_at`, `raw_payload_encrypted`
- `mail_dmarc_report_rows`
  - FK `report_id`, `source_ip`, `count`
  - `disposition`, `dkim_aligned`, `spf_aligned`, `dkim_result`, `spf_result`, `header_from`
- `mail_dmarc_daily_agg`
  - `domain_id`, `day`, `pass_count`, `fail_count`, `quarantine_count`, `reject_count`, `top_sources jsonb`

### (3) Panel endpoints / DTO
- `GET /api/v1/admin/mail/dmarc/reports?domain=&from=&to=`
- `GET /api/v1/admin/mail/dmarc/sources?domain=&day=`
- `GET /api/v1/customer/mail/domains/{id}/dmarc/overview?from=&to=`

### (4) Agent contract
- Not agent-heavy by default; parser can run panel-side worker.
- Optional agent pipeline if mailbox is node-local:
  - secure pickup of report mailbox
  - upload normalized payload to panel API batch endpoint.

### (5) Risks
- XML bombs / malformed reports. Mitigation: parser limits (size, depth, entity expansion disabled).
- Duplicate vendor reports. Mitigation: dedupe by `report_id + source_file_hash`.

---

## 4) Bounce Classification Engine

### (1) Design / Spec
- Parse SMTP DSN and classify into hard/soft bounce categories.
- Normalize reasons using deterministic code mapping table (`5.x.x` hard, `4.x.x` soft, plus vendor patterns).
- Feed suppression and sender reputation analytics.

### (2) Data model additions
- `mail_bounce_events`
  - `id`, `owner_id`, `domain_id`, `mailbox_id nullable`, `message_id`, `recipient`, `bounce_class`
  - `smtp_status`, `enhanced_status`, `reason_code`, `reason_text`, `created_at`
- `mail_bounce_reason_map`
  - `pattern`, `reason_code`, `bounce_class`, `provider optional`, `priority`

### (3) Panel endpoints / DTO
- `GET /api/v1/admin/mail/bounces?domain=&class=&from=&to=`
- `GET /api/v1/admin/mail/bounces/reasons?domain=&from=&to=`

### (4) Agent contract
- Agent logstream parser emits normalized bounce events in batch.
- Panel can apply secondary normalization against centrally managed mapping table.

### (5) Risks
- Provider-specific wording drift. Mitigation: versioned mapping table + fallback unknown bucket.

---

## 5) IP Reputation Monitor (RBL + feedback loops)

### (1) Design / Spec
- Scheduled reputation checks per outbound IP:
  - DNSBL/RBL lookups
  - provider feedback loop imports (where available)
- Store signal history and alert on degradation slope.

### (2) Data model additions
- `mail_ip_reputation_checks`
  - `id`, `node_id`, `ip`, `provider`, `listed bool`, `listing_code`, `checked_at`
- `mail_ip_reputation_scores`
  - `ip`, `score`, `window_start`, `window_end`, `factors jsonb`

### (3) Panel endpoints / DTO
- `GET /api/v1/admin/mail/reputation?ip=&from=&to=`
- `GET /api/v1/admin/mail/reputation/listings?ip=`

### (4) Agent contract
- Agent performs DNS-based RBL queries via Go resolver libs.
- Batch POST findings to panel; panel computes score trend.

### (5) Risks
- False listing due to resolver anomalies. Mitigation: quorum checks across multiple resolvers + retry windows.

---

## 6) Automatic Warmup Mode (Rate ramps)

### (1) Design / Spec
- Gradual sending ramp for new domains/IPs.
- Policy defines daily/hourly caps and progression curve.
- Tight integration with existing `mail_rate_limits` counters and policy engine.

### (2) Data model additions
- `mail_warmup_plans`
  - `id`, `domain_id`, `ip`, `start_at`, `phase`, `state` (`active|paused|done`)
  - `max_per_hour`, `max_per_day`, `ramp_profile jsonb`
- `mail_warmup_events`
  - phase transitions and enforcement hits.

### (3) Panel endpoints / DTO
- `POST /api/v1/admin/mail/warmup/plans`
- `PATCH /api/v1/admin/mail/warmup/plans/{id}`
- `GET /api/v1/admin/mail/warmup/overview`

### (4) Agent contract
- Snapshot includes effective warmup cap per mailbox/domain/IP.
- Agent rate-limit upsert uses min(policy_limit, warmup_limit).

### (5) Risks
- Over-throttling business traffic. Mitigation: emergency bypass with audited override.

---

## 7) Abuse Detection (Anomaly rules)

### (1) Design / Spec
- Rule engine over telemetry + logs + rate-limit counters.
- Initial deterministic rule set (no opaque ML in v1):
  - sudden auth-failure spike
  - bounce rate > threshold
  - recipient fanout anomaly
  - domain mismatch / DKIM fail spike
- Actions: alert, temporary throttle, temporary block, challenge queue.

### (2) Data model additions
- `mail_abuse_rules`
  - `id`, `owner_id nullable`, `scope` (`global|domain|mailbox`), `rule_type`, `threshold`, `window_seconds`, `action`, `enabled`
- `mail_abuse_incidents`
  - `id`, `rule_id`, `domain_id`, `mailbox_id nullable`, `severity`, `evidence jsonb`, `status`, `created_at`, `resolved_at`

### (3) Panel endpoints / DTO
- `GET /api/v1/admin/mail/abuse/incidents?status=&severity=&from=&to=`
- `POST /api/v1/admin/mail/abuse/rules`
- `PATCH /api/v1/admin/mail/abuse/incidents/{id}`

### (4) Agent contract
- Agent sends high-fidelity counters/events.
- Enforcement remains deterministic from panel-issued policy snapshot (no ad-hoc local mutation).

### (5) Risks
- Alert fatigue / noisy rules. Mitigation: staged severity model and per-tenant tuning.

---

## Prioritization (First / Next / Later)

### First (highest ROI + lowest integration risk)
1. **DMARC Report Parser**
2. **Bounce Classification Engine**
3. **Rspamd Integration (balanced baseline profile)**

Reasoning:
- Immediate visibility and deliverability impact.
- Strong operational value with moderate implementation risk.
- Leverages already implemented logging/telemetry pipeline.

### Next (depends on baseline signals)
4. **IP Reputation Monitor**
5. **Automatic Warmup Mode**

Reasoning:
- Needs reliable metrics and bounce/delivery signals first.
- Directly improves outbound reputation control.

### Later (higher complexity / ecosystem dependency)
6. **Abuse Detection advanced policies**
7. **ARC Support**

Reasoning:
- Abuse engine quality depends on mature telemetry + normalized event taxonomy.
- ARC rollout is useful but can introduce subtle trust-chain regressions and should follow stronger observability.

---

## Cross-feature API & Governance Requirements

- Every endpoint must enforce tenant scope (`owner_id`) and role-based access.
- All sensitive actions audited with actor, scope, before/after payload hash.
- Idempotent apply jobs only; never mutate data-plane config outside snapshot-driven renderer.
- Retention defaults:
  - high-cardinality raw events: 14–30 days
  - hourly aggregates: 400 days
- Backfill/recompute workers must be deterministic and restart-safe.
