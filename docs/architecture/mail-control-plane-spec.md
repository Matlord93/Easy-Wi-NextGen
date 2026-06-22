# Mail Control Plane – technische Spezifikation (Panel + Agent)

## 1. Zielbild
- **Zero-SSH Betrieb**: Alle Änderungen laufen über `Symfony API -> Job Queue -> Go-Agent -> MTA/IMAP Services`.
- **Mandantenfähig**: Domain/Customer-Isolation auf Datenbank- und API-Ebene.
- **Scale Target**: 10k Domains / 100k Mailboxen.
- **Security-by-default**: Argon2id, mTLS/JWT, keine Shell-Injection, auditiert, idempotent.

## 2. Data Model (Phase 1 Foundation)
DBMS: PostgreSQL (Schema-/Index-Design auf hohe Tenant-Kardinalität ausgelegt).
Neu eingeführte Tabellen:
- `mail_users`: Mailbox-Projektion für agentseitiges Provisioning und Limits.
- `mail_forwardings`: explizite Forwarding-Regeln (zusätzlich zu Aliasen).
- `mail_logs`: normalisierte Telemetrie-Events (delivery/auth/tls/spam/bounce).
- `mail_rate_limits`: mailbox-spezifische Versand-/Policy-Grenzen.
- `mail_dkim_keys`: domainbezogene DKIM-Key-Historie inkl. aktivem Selector; **ohne private Keys in DB** (nur Pfad/Fingerprint/Metadaten).

Bereits vorhanden und weiterverwendet:
- `mail_domains`, `mailboxes`, `mail_aliases`, `jobs`, `job_results`.

## 3. Kontrollfluss
1. UI/API validiert Request und persistiert State + Job (`mail.*`).
2. Agent pollt Job, validiert Input gegen typed DTOs.
3. Agent baut Config **vollständig deterministisch** aus DB-Snapshot.
4. Agent schreibt in staging-Dateien, führt Linter/Checks aus.
5. Bei Erfolg: atomarer Replace + `systemctl reload`.
6. Bei Fehler: Rollback + strukturierter Fehler zurück ans Panel.

## 4. Sicherheitskonzept
- **AuthN/Z Agent API**: JWT mit kurzer TTL + mTLS Pinning pro Node.
- **Keine Shell Injection**: Kein `sh -c`; nur `exec.CommandContext` mit festen Argumenten.
- **Secrets**: Private DKIM Keys liegen ausschließlich auf dem Mail-Node; in der DB nur Public Key + Key-Metadaten/Fingerprints.
- **Passwörter**: Argon2id only, keine reversible Speicherung.
- **Audit Trail**: Jede sensible Aktion (Password Reset, DKIM rotate, Queue flush, Service restart).

## 5. Observability & Monitoring
Admin-Dashboards (Phase 2):
- Queue Depth/States
- Auth Failures (rolling windows)
- Bounce/Reject Gründe
- DKIM/SPF/DMARC/TLS Compliance
- Fail2ban Counter
- Service Health (Postfix/Dovecot/OpenDKIM)

Datenquellen:
- Agent-parste Logs -> `mail_logs`
- Queue Snapshot Polls
- DNS Validation Engine (SPF/DKIM/DMARC/MX/rDNS/TLS)

## 6. DNS + DKIM
- Automatische Selector-Strategie: `mailYYYYMM`.
- 2048-bit RSA Keypair pro Rotation.
- DNS Ausgabeformat: `v=DKIM1; k=rsa; p=<public-key>`.
- SPF Generator Baseline: `v=spf1 mx a ip4:<SERVER_IP> -all`.
- DMARC Baseline: `v=DMARC1; p=quarantine; rua=mailto:postmaster@<domain>`.

## 7. API-Design (Roadmap)
Admin:
- `GET /api/v1/admin/mail/overview`
- `GET /api/v1/admin/mail/queue`
- `POST /api/v1/admin/mail/queue/flush`
- `POST /api/v1/admin/mail/services/{service}/restart`

Customer:
- `POST /api/v1/customer/mail/domains`
- `POST /api/v1/customer/mail/users`
- `POST /api/v1/customer/mail/forwardings`
- `POST /api/v1/customer/mail/domains/{id}/dkim/rotate`

Agent:
- `POST /v1/agent/mail/apply`
- `POST /v1/agent/mail/check-dns`
- `GET /v1/agent/mail/health`

## 8. Go-Agent Struktur (Roadmap)
Packages:
- `internal/mail/configgen` (postfix/dovecot/opendkim templates)
- `internal/mail/dkim` (keygen + file writer)
- `internal/mail/queue` (postqueue parser)
- `internal/mail/logs` (journal/file parsers)
- `internal/mail/validator` (DNS/TLS/rDNS checks)

Reliability:
- idempotente apply-Operationen
- config backups (`.bak` timestamped)
- dry-run validation vor reload
- rollback on error

## 9. Implementierungsstatus in diesem Schritt
- Foundation-Schema und Domain-Entities für Mail-Control-Plane ergänzt.
- DKIM-Keygenerator-Service in Symfony ergänzt.
- Bestehende Mail-Objekte bleiben kompatibel; Migration auf neue Endpunkte folgt iterativ.

## Job/Queue Orchestration Reference

Für Control-Plane → Execution-Plane Jobtypen (`mail.*`), Idempotency- und Result-Mapping siehe:
- `docs/architecture/mail-job-queue-control-plane-design.md`
