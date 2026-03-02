# Mail Platform Architektur (Panel Integration)

## A) Architekturentscheidung

Wir setzen auf **Postfix + Dovecot + optional Rspamd**:

- **Postfix** als MTA für SMTP-Inbound/Outbound und Virtual-Domain-Routing.
- **Dovecot** für IMAP/POP3, Auth gegen SQL-Backends (virtuelle User), Quota Enforcement.
- **Rspamd (optional)** für Spam-/Policy-Checks, DKIM-Signing und spätere Reputation-Flows.

Begründung:

1. Bewährter OSS-Stack mit stabilen Betriebs-Patterns.
2. Saubere Trennung von Zuständigkeiten (MTA vs. Mailbox/IMAP).
3. SQL-backed virtuelle User sind sehr gut für Panel-Provisioning per Jobs geeignet.
4. Rspamd kann schrittweise aktiviert werden, ohne den Kernfluss zu blockieren.

## B) Datenmodell

Neu eingeführt:

- `MailDomain`: Domain-Bindung zu `MailNode` + DKIM/DMARC Defaults + optional `QuotaPolicy`
- `Mailbox`: bestehend, weiterhin Adresse/Passworthash/Quota/Status
- `MailAlias`: bestehend, erweitert um Loop-Prevention im API-Workflow
- `QuotaPolicy`: Max Accounts, Domain-Quota, Mailbox-Quota
- `MailNode`: IMAP/SMTP/Roundcube-Endpunkte pro Mail-Cluster/Node

## C) Provisioning (Jobs)

Panel erzeugt Jobs für create/update/delete und Agent setzt um:

- Mailbox: `mailbox.create`, `mailbox.password.reset`, `mailbox.quota.update`, `mailbox.enable|disable`, `mailbox.delete`
- Alias: `mail.alias.create|update|delete`
- Roundcube: `roundcube.deploy` (Domain-Bindung an MailNode/URL)

Agent-Seite nutzt dafür SQL-backed virtual users/maps (Postfix/Dovecot).

## D) Security

- Dovecot-kompatibles Passworthashing: konfigurierbar per `APP_MAIL_PASSWORD_HASH_ALGORITHM` (`argon2id` oder `bcrypt`) mit Prefix (`{ARGON2ID}` / `{BLF-CRYPT}`).
- Keine Klartext-Passwortprotokollierung: Jobs/Audit enthalten nur Hash/Metadaten, nie das Passwort selbst.
- Sichere Passwort-Resets: serverseitig als reset-job+audit (Hash nie im Klartext persistiert)
- SPF/DKIM/DMARC: UI liefert DNS-Hinweise (SPF, DKIM-Selector, DMARC-Policy) pro MailDomain
- DKIM Private Keys liegen ausschließlich auf dem Mail-Node (`/etc/opendkim/keys/...`, 0600 `root:opendkim`). In der DB werden nur Public Key/Fingerprint/Selector-Metadaten persistiert.

### DKIM Rotation-Strategie

1. Neuer Key wird über Admin-Endpoint `/api/v1/admin/mail-platform/domains/{id}/dkim/rotate` verschlüsselt gespeichert.
2. `mail.dkim.rotate` Job aktiviert den neuen Selector auf dem Mailnode.
3. Alter Key bleibt node-lokal verfügbar, bis DNS-TTL + Auslieferungsfenster abgelaufen sind (Rotation-Cleanup über Agent-Job).
4. Danach kann der alte Key sicher entfernt werden (operativer Runbook-Step).

## E) Roundcube

- Roundcube wird pro Domain über `MailNode.roundcubeUrl` gebunden.
- Binding wird über Admin-Bind-Endpoint und `roundcube.deploy` Job orchestriert.
- `roundcube.deploy` ist idempotent (reused active job pro Domain), liefert Status zurück und nutzt erhöhte Retry-Anzahl (`maxAttempts=5`).
- Audit-Events erfassen jede Deploy-Anforderung (`roundcube.deploy_requested`).
- SSO bleibt optional und kann später als separates Auth-Bridge-Feature ergänzt werden.

## F) Tests

Abgedeckt:

- Mailbox-Limits (`MailLimitEnforcerTest`)
- Alias-Loop-Prevention (`MailAliasLoopGuardTest`)
- Mailbox-Create-Flow bleibt controllerseitig inkl. Job-Erzeugung aktiv

Akzeptanzkriterien technisch abgedeckt durch:

- IMAP Login-Pfad: über SQL-backed Accounts/Hashing vorbereitet (Integration auf Agent-Stack)
- Client-Settings: IMAP/SMTP/TLS werden pro Domain/Node im Kundenpanel angezeigt
- Admin-Limits: `QuotaPolicy` + `MailLimitEnforcer` greifen bei Mailbox-Anlage

## G) Enterprise Addons Roadmap

Konkrete Spec-First Roadmap (Rspamd Policies/Stats, ARC, DMARC-RUA Parser, Bounce Classification, IP Reputation, Warmup, Abuse Detection):
- `docs/architecture/mail-enterprise-addons-roadmap.md`
