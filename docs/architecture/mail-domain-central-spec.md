# Central `MailDomain` Spec (Multi-Tenant Control Plane)

## 1) Design / Spec
- `mail_domains` ist die zentrale Read/Write-Aggregation für domainbezogene Mail-Policy und DNS-Health.
- DB bleibt Source of Truth; Agent rendert deterministisch aus Snapshot + Revision.
- **ID-Strategie**: bestehende numerische ID bleibt aus Kompatibilitätsgründen bestehen (kein Breaking-Change in bestehenden FKs/API), kann später online auf `BIGINT` migriert werden.

Tenant Isolation:
- Primär über `owner_id` (FK auf `users`) + Repository-Scoping in allen Anwendungsqueries.
- Optional zusätzlich als Doctrine SQL-Filter (`owner_id = :current_owner`) im HTTP-Kontext.

## 2) Datenmodell / Migration
Neue/erweiterte Felder in `mail_domains`:
- `owner_id` (FK users)
- `domain` (varchar(253), lowercase, unique pro owner)
- `dkim_selector`
- `dkim_status`, `spf_status`, `dmarc_status`, `mx_status`, `tls_status`
- `dns_last_checked_at`
- `mail_enabled`
- `created_at`, `updated_at`

Indizes:
- `(owner_id, domain)`
- zusammengesetzter Statusindex
- `dns_last_checked_at`

## 3) API / DTO
- DTO `DkimProvisionRequestDto` validiert strict Payload-Felder für Agent-Provisioning.
- Domain-Werte werden im Domain-Aggregat über `normalizeDomainName()` (IDN -> ASCII/Punycode + FQDN Regex) konsolidiert.

## 4) Agent Contract
- Agent bekommt nur Public-Key-Material + Selector; private DKIM Keys verbleiben auf Node.
- Replay-Schutz + mTLS/JWT sind Pflicht.
- Idempotente Apply-Jobs via `config_revision`.

## 5) Tests / Edge Cases (5 Szenarien)
1. IDN-Normalisierung (`BÜCHER.de` -> `xn--bcher-kva.de`).
2. Invalid Domain wird abgelehnt.
3. Owner-Scoping + Domain-Kanonisierung im Constructor.
4. DKIM-Rotation setzt Selector und Status deterministisch.
5. Status-Normalisierung fällt auf `unknown`, DMARC Policy auf `quarantine` zurück.
