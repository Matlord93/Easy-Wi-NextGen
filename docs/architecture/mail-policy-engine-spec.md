# Mail Policy Engine Spec (MUST HAVE #6)

## 1) Design / Spec

### Scope-Entscheidung
Wir wählen **domain-scoped policies** (`mail_policies` pro Domain, 1:1), weil:
- Tenant-Isolation und Ownership klar über `domain -> customer` abbildbar ist.
- Operative Durchsetzung in Postfix/Dovecot überwiegend domainnah ist.
- Bei 10k Domains / 100k Mailboxen ist Domain-Default + optionales späteres mailbox-override skalierbarer als sofortige mailbox-policy-table mit 100k Rows.

Spätere Erweiterung: mailbox overrides als additive Tabelle (`mail_policy_overrides`) möglich.

Policy-Felder:
- `require_tls` (bool)
- `max_recipients` (int)
- `max_hourly_emails` (int)
- `allow_external_forwarding` (bool)
- `spam_protection_level` (`low|med|high`)
- `greylisting_enabled` (bool)

## 2) Datenmodell / Migration

### Tabelle
`mail_policies`
- `id`
- `owner_id` (FK users)
- `domain_id` (FK domains, unique)
- Policy-Felder
- `created_at`, `updated_at`

### Constraints/Indices
- Unique: `domain_id`
- Index: `(owner_id, domain_id)`
- Check: spam level enum
- Check: recipient/hourly bounds

Siehe Migration:
- `core/migrations/Version20261015192000.php`

## 3) API / DTO + Validation Rules

### DTO
- `MailPolicyUpsertDto::fromPayload()` validiert:
  - `max_recipients` ∈ [1..1000]
  - `max_hourly_emails` ∈ [1..100000]
  - `spam_protection_level` ∈ `low|med|high`

### Admin API
- `GET /api/v1/admin/mail/policies/domains/{id}`
- `PUT /api/v1/admin/mail/policies/domains/{id}`

Auditing:
- Jede Änderung erzeugt `mail.policy_updated` Audit-Event mit Domain + Policy-Snapshot.

## 4) Enforcement Points (Agent/Data Plane)

### Agent Config Rendering
Policy wird in deterministic snapshot aufgenommen und in Postfix/Dovecot/rspamd-bezogene Konfigs gerendert.

### Postfix Durchsetzung

#### `require_tls`
Per sender-dependent TLS policy map (vom Agent gerendert):

```cf
# main.cf
smtpd_tls_security_level = may
smtp_tls_security_level = may
smtp_tls_policy_maps = hash:/etc/postfix/tls_policy
```

```text
# /etc/postfix/tls_policy (generated)
example.com encrypt
```

Für Submission/Inbound zusätzlich:

```cf
smtpd_tls_auth_only = yes
smtpd_tls_received_header = yes
```

#### `max_recipients`
Globales Hard Limit + policy delegation für domain-spezifische Limits:

```cf
# global safety cap
smtpd_recipient_limit = 1000

# policy engine hook (domain aware)
smtpd_recipient_restrictions =
    permit_mynetworks,
    permit_sasl_authenticated,
    check_policy_service unix:private/panel_policy,
    reject_unauth_destination
```

Policy daemon (Agent-side) prüft domain-policy `max_recipients` pro SMTP transaction.

#### `max_hourly_emails`
Durchsetzung in policy-service (counter store/redis/postgres), keyed by sender/mailbox+hour.

### Dovecot
- Primär auth/userdb, keine harte Sender-Rate-Limit-Logik.
- Kann Auth-/Session-Policy ergänzen; Hauptenforcement für outbound bei Postfix policy service.

### Rspamd (later)
- `spam_protection_level` und `greylisting_enabled` werden in Rspamd profile gemappt.

## 5) Tests / Edgecases
1. Invalid `spam_protection_level` -> 400.
2. `max_recipients=0` -> 400.
3. `max_hourly_emails > 100000` -> 400.
4. Non-admin update -> 403.
5. Unknown domain -> 404.
6. PUT idempotent update (same payload) -> no drift in policy.
7. Audit event emitted on each change.
8. require_tls=true with missing tls map entry should fail validation in agent apply stage.
