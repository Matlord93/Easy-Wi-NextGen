# Database Self-Service (Finalisierung)

## Umgesetzte Hardening-Punkte

### 1) Eindeutigkeit pro Kunde + Engine
Die Persistenz erzwingt jetzt eindeutige Kombinationen:

- `(customer_id, engine, name)`
- `(customer_id, engine, username)`

Damit sind gleiche Namen/Benutzer über unterschiedliche Engines zulässig, innerhalb derselben Engine aber eindeutig.

### 2) Reserved words + quoted identifiers
Identifier werden vor Reserved-Word-Prüfung normalisiert (trim + lower-case; äußere Quote-Wrapper werden für die Normalisierung entfernt).
Zusätzlich sind quoted identifiers (`"name"`, `` `name` ``, `[name]`) in der API/Policy generell nicht erlaubt.

### 3) PostgreSQL Identifier-Sicherheit
Für PostgreSQL-DDL werden ausschließlich sicher gequotete Identifier verwendet. Passwort-Setzen wird, wo möglich, über SQL-Parameter gebunden (`$1`) statt Literal-Konkatenation.

### 4) Schema-Rechte für produktive Nutzbarkeit
Nach Provisionierung erhält der DB-User im Ziel-DB-Kontext zusätzlich:

- `GRANT USAGE, CREATE ON SCHEMA public`
- `ALTER DEFAULT PRIVILEGES ... ON TABLES`
- `ALTER DEFAULT PRIVILEGES ... ON SEQUENCES`

Damit kann der User im Standard-Schema unmittelbar arbeiten (Objekte anlegen + Standardrechte auf Folgetabellen/-sequenzen).

### 5) ConnectionPolicy-Entscheidung
**Entscheidung: metadata-only.**

Die `connection_policy` wird im Job-Payload mitgeführt (Default: `private`), aber **nicht** automatisch durch DB Self-Service mittels Firewall-/`pg_hba`-Jobs erzwungen.

Begründung:
- Trennung von Zuständigkeiten: Datenbankobjekt-Provisionierung vs. Netz-/Host-Policy-Orchestrierung.
- Vermeidet implizite Seiteneffekte bei DB-Operationen.
- Policy-Enforcement bleibt in dedizierten Security-/Network-Flows.

### 6) Cleanup Altlogik
- Engine-Validierung in Admin-DB-Controller vereinheitlicht auf `EngineType`.
- Health-Check-DSN-Auswahl via `match` auf unterstützte Engine-Werte.

