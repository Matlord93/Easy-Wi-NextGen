# ARC Support Specification (Enterprise Mail Hosting, Implementation-Ready)

## 0) Scope and Hard Constraints

This specification defines deterministic ARC (Authenticated Received Chain) support for enterprise mail hosting.

Hard constraints:
1. UUID v7 for all new primary keys.
2. ARC private keys are node-local only (never stored in panel DB).
3. Deterministic key path derived from `(domain_id, selector)`.
4. Panel stores metadata only.
5. Canary enablement is supported and deterministic.
6. Config rendering is deterministic from snapshot state.
7. All timestamps in UTC (`TIMESTAMPTZ`).

---

## 1) Architecture

## 1.1 Technology Choice: **OpenARC** (selected) over Rspamd ARC module

### Decision
Use **OpenARC** as the primary ARC signer/verifier component for v1.

### Justification
1. Clear separation of concerns: Postfix milter chain with dedicated ARC responsibility.
2. Operational isolation: ARC failures/reloads do not directly couple to Rspamd policy pipeline.
3. Mature KeyTable/SigningTable operational model aligns with deterministic file rendering.
4. Supports incremental rollout in mixed deployments where Rspamd may remain optional.

Rspamd ARC module remains a future option, but v1 baseline standardizes on OpenARC for deterministic interoperability.

## 1.2 High-Level Flow

1. Panel persists ARC metadata (`mail_arc_keys` + `mail_domains.arc_*`).
2. Panel issues snapshot containing effective ARC state by domain.
3. Agent ensures key files exist node-locally for active selectors.
4. Agent renders OpenARC config files deterministically.
5. Agent validates config, reloads OpenARC, and confirms health.
6. Postfix milter chain applies ARC signing for enabled domains.

## 1.3 Canary Enablement Model

Per-domain rollout fields:
- `arc_enabled` boolean.
- `arc_mode` enum (`off|canary|enforce`).
- `arc_canary_percent` integer 0..10000 (basis points).

Behavior:
- `off`: ARC bypass for domain.
- `canary`: deterministic sampling by message hash bucket `< arc_canary_percent`.
- `enforce`: ARC applied to all eligible messages.

Sampling function (deterministic):
- `bucket = crc32(message_id_or_queue_id) % 10000`.
- Apply ARC when `bucket < arc_canary_percent`.

---

## 2) Database

## 2.1 `mail_arc_keys`

```sql
CREATE TABLE mail_arc_keys (
  id                           UUID PRIMARY KEY,
  owner_id                     UUID NOT NULL,
  domain_id                    UUID NOT NULL,

  selector                     VARCHAR(63) NOT NULL,
  algorithm                    VARCHAR(16) NOT NULL DEFAULT 'rsa2048',

  key_path                     VARCHAR(512) NOT NULL,
  public_key_pem               TEXT NOT NULL,
  dns_txt_value                TEXT NOT NULL,

  status                       VARCHAR(16) NOT NULL,
  can_sign                     BOOLEAN NOT NULL DEFAULT TRUE,

  activated_at                 TIMESTAMPTZ NULL,
  rotated_at                   TIMESTAMPTZ NULL,

  created_at                   TIMESTAMPTZ NOT NULL,
  updated_at                   TIMESTAMPTZ NOT NULL,
  created_by_actor_id          UUID NOT NULL,
  updated_by_actor_id          UUID NOT NULL,

  CONSTRAINT chk_arc_status
    CHECK (status IN ('active','rotating','retired','pending')),
  CONSTRAINT chk_arc_selector
    CHECK (selector ~ '^[a-z0-9][a-z0-9-]{0,62}$'),
  CONSTRAINT chk_arc_algo
    CHECK (algorithm IN ('rsa2048')),
  CONSTRAINT chk_arc_key_path_nonempty
    CHECK (char_length(key_path) > 0)
);

CREATE UNIQUE INDEX uq_arc_domain_selector
  ON mail_arc_keys(domain_id, selector);

CREATE UNIQUE INDEX uq_arc_domain_active_key
  ON mail_arc_keys(domain_id)
  WHERE status = 'active';

CREATE INDEX ix_arc_owner_domain_status
  ON mail_arc_keys(owner_id, domain_id, status, updated_at DESC);

CREATE INDEX ix_arc_rotated_at
  ON mail_arc_keys(rotated_at DESC);
```

Panel metadata only rules:
- `key_path` is deterministic metadata path, not key material.
- `public_key_pem` and derived DNS TXT are stored.
- Private key never leaves node filesystem.

## 2.2 `mail_domains` extension fields

```sql
ALTER TABLE mail_domains
  ADD COLUMN arc_enabled                BOOLEAN NOT NULL DEFAULT FALSE,
  ADD COLUMN arc_mode                   VARCHAR(16) NOT NULL DEFAULT 'off',
  ADD COLUMN arc_canary_percent         INTEGER NOT NULL DEFAULT 0,
  ADD COLUMN arc_active_key_id          UUID NULL REFERENCES mail_arc_keys(id),
  ADD COLUMN arc_last_applied_at        TIMESTAMPTZ NULL,
  ADD COLUMN arc_last_error_code         VARCHAR(64) NULL,
  ADD COLUMN arc_last_error_at          TIMESTAMPTZ NULL;

ALTER TABLE mail_domains
  ADD CONSTRAINT chk_arc_mode
    CHECK (arc_mode IN ('off','canary','enforce')),
  ADD CONSTRAINT chk_arc_canary_percent
    CHECK (arc_canary_percent BETWEEN 0 AND 10000),
  ADD CONSTRAINT chk_arc_mode_enabled_consistency
    CHECK (
      (arc_enabled = FALSE AND arc_mode = 'off') OR
      (arc_enabled = TRUE AND arc_mode IN ('canary','enforce'))
    );

CREATE INDEX ix_domains_arc_mode
  ON mail_domains(owner_id, arc_enabled, arc_mode);
```

---

## 3) REST API

All mutating endpoints require `Idempotency-Key` and emit `correlationId`.
All domain operations are owner-scoped; cross-tenant returns `404`.

## 3.1 Enable ARC

`POST /api/v1/customer/mail/domains/{id}/arc/enable`

Request example:
```json
{
  "mode": "canary",
  "canaryPercent": 1500,
  "selector": "arc202611",
  "algorithm": "rsa2048"
}
```

Behavior:
1. Validate domain ownership and mode constraints.
2. Create or reuse `pending` key metadata row.
3. Enqueue keygen/apply job for target node(s).
4. Return operation record.

Response (`202`):
```json
{
  "domainId": "0197061a-d12a-7600-9282-bf0d5f61322f",
  "arcEnabled": true,
  "mode": "canary",
  "canaryPercent": 1500,
  "selector": "arc202611",
  "status": "pending_apply",
  "correlationId": "01K..."
}
```

## 3.2 Rotate ARC key

`POST /api/v1/customer/mail/domains/{id}/arc/rotate`

Request example:
```json
{
  "newSelector": "arc202701",
  "overlapSeconds": 604800
}
```

Behavior:
1. Create `rotating` key metadata row.
2. Agent generates new key node-local and returns public key.
3. Panel updates DNS output and marks overlap window.
4. On promotion time, old key becomes `retired`, new key `active`.

Response (`202`):
```json
{
  "domainId": "0197061a-d12a-7600-9282-bf0d5f61322f",
  "oldSelector": "arc202611",
  "newSelector": "arc202701",
  "status": "rotation_in_progress",
  "correlationId": "01K..."
}
```

## 3.3 DNS output

`GET /api/v1/customer/mail/domains/{id}/arc/dns`

Response example:
```json
{
  "domainId": "0197061a-d12a-7600-9282-bf0d5f61322f",
  "records": [
    {
      "host": "arc202701._domainkey.example.com",
      "type": "TXT",
      "value": "v=DKIM1; k=rsa; p=MIIBIjANBgkq..."
    }
  ],
  "activeSelector": "arc202611",
  "nextSelector": "arc202701",
  "correlationId": "01K..."
}
```

Admin visibility endpoint (optional extension):
- `GET /api/v1/admin/mail/arc/overview?ownerId=&mode=&status=`.

---

## 4) Agent Contract

## 4.1 Key generation

Agent receives panel job with deterministic metadata:
- `domain_id`, `selector`, `algorithm`, `key_path`, `correlation_id`.

Allowed command execution:
- fixed binaries + fixed args only (no shell interpolation).

Example:
- `exec.CommandContext(ctx, "/usr/bin/openssl", "genrsa", "-out", keyPath, "2048")`

Post-generation:
1. Derive public key from private key.
2. Return `public_key_pem` to panel.
3. Never transmit private key bytes.

## 4.2 Deterministic key path

Path function:
- `key_path = /etc/openarc/keys/{domain_id}/{selector}.private`

Rules:
- `domain_id` UUID lowercase canonical.
- `selector` lowercase validated slug.
- no symlink traversal; path cleaned and prefix-checked.

## 4.3 File permissions

Private key file requirements:
- mode `0600`
- owner `root`
- group `openarc` (or deployment-specific openarc group)

Directory requirements:
- `/etc/openarc/keys/{domain_id}` mode `0750` owner `root:openarc`.

## 4.4 Renderer targets

Deterministic rendering targets:
1. `/etc/openarc.conf`
2. `/etc/openarc/KeyTable`
3. `/etc/openarc/SigningTable`
4. `/etc/openarc/TrustedHosts`

Sorting rules:
- domains by FQDN ascending bytewise.
- selectors by lexical ascending.
- stable line endings (`\n`) and single EOF newline.

## 4.5 Validation & reload

Flow:
1. Render to staged directory.
2. Run config validation command (fixed args), e.g.:
   - `exec.CommandContext(ctx, "/usr/sbin/openarc", "-n", "-c", "/etc/openarc.conf")`
3. On success, atomic swap staged -> active.
4. Reload service:
   - `exec.CommandContext(ctx, "/bin/systemctl", "reload", "openarc")`
5. Health probe milter socket.

Failure handling:
- Validation fail: abort activate.
- Reload fail: rollback to previous config and retry reload once.

---

## 5) Rollback Strategy

Two-layer rollback:
1. **Config rollback**
   - keep `active` and `previous` rendered snapshots.
   - atomic revert symlink and reload.

2. **Key rollback**
   - if new selector fails after promotion, domain `arc_mode` auto-falls back to `canary` with old `active` selector.
   - mark incident in `arc_last_error_code` and audit event.

TTL guard:
- Emergency disable can set `arc_enabled=false` with optional TTL auto-reenable policy (audited).

---

## 6) Acceptance Criteria

1. Panel stores only metadata/public key; private key never persisted in DB.
2. Deterministic key path always resolves to identical location for same `(domain_id, selector)`.
3. Enabling ARC in canary mode applies deterministic sampling by message bucket.
4. Rendering same snapshot twice yields byte-identical OpenARC config files.
5. Key file permission and ownership checks enforce `0600 root:openarc`.
6. Config validation gate blocks invalid activation.
7. Rotation supports overlap and deterministic promotion from `rotating` to `active`.
8. Rollback restores previous config and service state on reload failure.

---

## 7) Edge Cases

1. Selector already exists for domain:
   - return conflict `409 ARC_SELECTOR_EXISTS`.

2. Domain moved to another node during rotation:
   - regenerate node-local key on destination node; update key_path metadata deterministically.

3. Missing OpenARC binary/service:
   - apply result `ARC_RUNTIME_UNAVAILABLE`; no partial state activation.

4. Canary percent set to 0 with `mode=canary`:
   - valid for dry run; no traffic signed.

5. DNS not updated yet during rotation overlap:
   - keep old selector active; new selector remains `rotating`.

6. Corrupted private key file:
   - validation fails; rollback and emit audited incident.

---

## 8) Determinism Guarantees

Formal function:
- `A(arc_snapshot, selector_state, domain_set) -> openarc_files + key_paths`

Determinism controls:
1. Canonical ordering of domains/selectors.
2. Deterministic path derivation from `(domain_id, selector)`.
3. No environment-dependent rendering fields.
4. Canonical newline normalization and UTF-8 output.
5. Fixed command invocation and deterministic validation gate.
6. Canary sampling from stable hash bucket modulo 10000.

Required verification tests:
- Golden render test for OpenARC file outputs.
- Replay test: same enable request + idempotency key yields identical state.
- Rotation test: deterministic status transitions (`pending -> rotating -> active -> retired`).
- Rollback test: forced reload failure restores previous config bytes and status.

