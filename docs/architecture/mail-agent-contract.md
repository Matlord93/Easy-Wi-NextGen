# Mail Agent Contract (Zero-SSH, deterministic apply)

## 1) Design / Security Contract
- Transport: **mTLS + JWT** (short TTL, audience + node binding).
- Replay protection: mandatory `X-Request-Id` + `X-Request-Timestamp` + signature over body hash.
- Determinism: Agent renders config only from payload + DB snapshot revision (`config_revision`).
- No shell injection: `exec.CommandContext` with static binary + static arg list; never `sh -c`.

## 2) Datenmodellrelevante Felder
- `mail_dkim_keys.private_key_path`: absoluter Pfad auf Node (`/etc/opendkim/keys/<domain>/<selector>.private`).
- `mail_dkim_keys.public_key`: public PEM / DNS source.
- `mail_dkim_keys.fingerprint_sha256`: integrity check for key material.
- **Kein privater Key in DB**.

## 3) API/DTO
### Symfony -> Agent: `POST /v1/mail/dkim/provision`
```json
{
  "request_id": "uuid",
  "config_revision": "mailcfg-2026-10-15T16:10:00Z",
  "domain_id": 123,
  "domain": "example.com",
  "selector": "mail202610",
  "public_key_pem": "-----BEGIN PUBLIC KEY-----...",
  "key_bits": 2048,
  "algorithm": "rsa"
}
```

### Agent -> Symfony (result callback)
```json
{
  "request_id": "uuid",
  "status": "applied",
  "private_key_path": "/etc/opendkim/keys/example.com/mail202610.private",
  "fingerprint_sha256": "<hex>",
  "service_reload": {
    "opendkim": "ok"
  }
}
```

## 4) Agent Execution Contract
1. validate JWT + mTLS + replay headers.
2. validate payload schema.
3. create key on node (0600 root:opendkim).
4. update OpenDKIM files (`KeyTable`, `SigningTable`, `TrustedHosts`) idempotent.
5. run `opendkim-testkey` dry-run.
6. reload service and return structured result.

## 5) Tests / Edge Cases
- Duplicate selector -> 409 conflict.
- Drift: callback for outdated `config_revision` -> reject/stale.
- Permissions wrong on key file -> fail + rollback + alert.
- Replay request id -> reject.
- DNS TXT too long -> split output in UI but preserve exact content.
