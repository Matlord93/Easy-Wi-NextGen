# Panel ↔ Agent Security Hardening (MUST HAVE #9)

## 1) Design / Spec

### Security Stack (defense in depth)
1. **mTLS pinning per node**
   - Every node gets a dedicated client certificate (`CN=agent_id`, SAN includes agent UUID).
   - Panel ingress trusts only Control-Plane CA.
   - Certificate fingerprint is pinned in panel `agents` metadata (spec target: enforce at edge/reverse proxy).
2. **Short-lived JWT (HS256, 60s TTL)**
   - Claims: `iss`, `aud`, `sub`, `iat`, `exp`, `jti`.
   - `sub` must equal agent id, `aud` + `iss` must match panel config.
3. **Replay protection**
   - Existing nonce cache (`X-Nonce`, TTL window) remains mandatory.
   - JWT `jti` is bound to `X-Nonce` (must match when both present).
4. **Request signing**
   - Canonical HMAC signature (`X-Signature`) over method/path/body hash/timestamp/nonce.
   - Decision for now: **HMAC-SHA256** (already deployed) over Ed25519 to avoid dual key-distribution migration risk in this step.
5. **Least privilege**
   - Agent credentials accepted only on `/agent/*` + `/api/v1/agent/*` endpoints.
   - Admin/customer tokens stay isolated from agent API path.

## 2) Datenmodell / Migration

**No new mandatory schema in this step** (compatible hardening).

Operational recommendation (next step):
- add `agent_cert_fingerprint` + `agent_cert_not_after` in agent metadata for full in-app mTLS pin audit.

## 3) API / DTO

### Required headers (Agent → Panel)
- `Authorization: Bearer <JWT>`
- `X-Agent-ID`
- `X-Timestamp`
- `X-Nonce`
- `X-Signature`

### JWT claims contract
```json
{
  "iss": "easywi-panel",
  "aud": "easywi-agent-api",
  "sub": "<agent-id>",
  "iat": 1760558400,
  "exp": 1760558460,
  "jti": "<nonce>"
}
```

## 4) Agent Contract / Middleware Sketch

### Go side
- `agent/internal/crypto/jwt.go`
  - `BuildAgentJWT(...)`
  - `SignHS256JWT(...)`
- `agent/internal/api/client.go`
  - attaches JWT bearer token (TTL 60s) on all signed API requests.
  - binds `jti` to generated `X-Nonce`.

### Symfony side
- `core/src/Module/Core/Application/AgentJwtVerifier.php`
  - validates bearer presence, JWT structure, `alg=HS256`, signature, `iss`, `aud`, `sub`, time claims (`iat/exp`), max TTL, and nonce binding (`jti == X-Nonce`).
- `AgentApiController::requireAgent()`
  - decrypts node secret, verifies JWT first, then HMAC signature verifier + nonce replay cache.
  - failed auth is audited (`agent.auth_failed`).

## 5) Threat Model / Edgecases

1. **MITM**: mitigated by mTLS + HMAC/JWT integrity.
2. **Replay**: mitigated via nonce cache window and JWT `jti` binding.
3. **Token theft**: reduced blast radius via 60s `exp` and strict `aud`/`iss`.
4. **Compromised node**: rotate node secret + mTLS cert, revoke old cert at ingress; panel marks node suspended.
5. **Clock skew**: verifier allows bounded skew; too old/new tokens rejected.
6. **Path tampering**: HMAC includes canonical request path + body hash.
7. **Cross-endpoint abuse**: agent auth accepted only on agent API namespace.
8. **Nonce cache outage**: fail closed on cache errors in production profile (recommended).

## 6) Key Rotation Plan

1. Generate new agent shared secret + new mTLS cert per node.
2. Panel stores both active and next secret during grace window.
3. Agent rolls to new secret/cert.
4. After grace, revoke old cert and delete old secret.
5. Audit event emitted: `agent.key_rotation.completed`.

## 7) Logging / Audit

Log/Audit must include:
- auth failures (`agent.auth_failed`) with reason, path, method.
- sensitive control actions (mail apply, policy changes, reload, key rotation).
- mTLS cert mismatch/revocation (at ingress + panel correlation id).

## 8) DKIM private key handling

- Private keys remain **node-local only** under `/etc/opendkim/keys/<domain>/`.
- Mandatory perms: `0600`, owner `root:opendkim`.
- Rotation flow:
  1. create new selector + keypair
  2. publish DNS `p=`
  3. activate signing table
  4. drain overlap period
  5. wipe old private key securely and remove stale selector mapping.
- DB stores public metadata only (selector, public key material, status/timestamps).
