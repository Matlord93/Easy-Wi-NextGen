# Unified Security – Canonical Rulesets, LKG und Event-Hardening

## Vulnerability Management Policy (TASK-012)

### Zielbild und Gate
- Es dürfen **keine offenen kritischen CVEs** in einem Release enthalten sein.
- Der Release-Prozess wird durch ein CI-Gate abgesichert: Bei offenen kritischen Findings wird der Release-Workflow automatisch abgebrochen (sofern GitHub Code Scanning Alerts verfügbar sind).

### SLA und Ownership
| Severity | SLA bis Ticket-Erstellung | SLA bis Fix in Default-Branch | Ownership |
|---|---:|---:|---|
| Kritisch | 24h | 48h | Security Champion + zuständiges Team (Codeowner) |
| Hoch | 48h | 7 Tage | Zuständiges Team (Codeowner), Security Champion reviewed |
| Mittel | 5 Tage | 30 Tage | Zuständiges Team (Codeowner) |

**Ownership-Regeln**
- Der/die Security Champion ist verantwortlich für Triage, Priorisierung und Waiver-Freigaben.
- Das jeweilige Codeowner-Team ist verantwortlich für Umsetzung, Tests, Verifikation und Release-Dokumentation.
- Dependabot-PRs werden standardmäßig dem Security Champion zugewiesen und mit `security` + `dependencies` gelabelt.

### Prozess: Finding -> Ticket -> Fix -> Verify -> Release Notes
1. **Finding**
   - Quelle: GitHub Code Scanning / Dependency Review / Dependabot / Secret Scans.
   - Jede bestätigte Schwachstelle wird einer Severity (kritisch/hoch/mittel) zugeordnet.
2. **Ticket**
   - Verbindliches Ticket mit CVE/GHSA, betroffener Komponente, Zielversion, SLA-Datum, Owner.
   - Kritische Findings erhalten zusätzlich ein Incident-Label (z. B. `security-critical`).
3. **Fix**
   - Behebung via PR inkl. Regression-Schutz (mind. relevante Unit-/Integration-/Smoke-Checks).
   - Bei Library-Fixes: bevorzugt minimal-invasive Version-Bumps.
4. **Verify**
   - Security-Workflows müssen grün sein.
   - Für kritische Findings: kein offener kritischer Code-Scanning-Alert auf dem Release-Commit.
5. **Release Notes**
   - Jeder Security-Fix wird in den Release Notes referenziert (CVE/GHSA, Scope, Impact, Fix-Version).

### Ausnahmen / Waiver-Prozess
- Waiver sind nur für **zeitlich befristete** Ausnahmen erlaubt.
- Erforderliche Angaben:
  - Referenz (CVE/GHSA/Finding-ID)
  - Begründung (inkl. Kompensationsmaßnahmen)
  - Ablaufdatum (`expires_at`)
  - Verantwortliche Person
  - Genehmigung durch Security Champion
- Ohne gültigen Waiver gilt das Gate unverändert; kritische Findings blockieren Releases.
- Nach Ablauf des Waivers wird ein Release wieder blockiert, bis Fix oder neuer genehmigter Waiver vorliegt.

### CI-Gate Implementierungsdetails
- `security-dependency-review` failt bei kritischen Dependency-Findings in PRs.
- `security-release-gate` überwacht Release-Workflow-Starts und bricht den Release-Lauf ab, wenn offene kritische Code-Scanning-Alerts vorhanden sind.
- Falls Code-Scanning-Tooling/API nicht verfügbar ist, failt das Gate „closed“ (Release wird nicht freigegeben), damit keine unsicheren Releases durchlaufen.

## Überblick
Diese Umsetzung vereinheitlicht Security-Policy-Anwendung zwischen Panel (Symfony) und Agent (Go):

- **Canonicalization** der Rulesets vor Hash/Compare.
- **LKG (Last Known Good)** persistiert pro `node + target + backend` inkl. Hash/Version.
- **Atomare Apply-Pipeline** mit Verify und Auto-Rollback.
- **Self-Lockout-Schutz** für essentielle Management-/SSH-Ports.
- **Security Events Collect** mit Schema-Gate, Payload-Limit, Dedup-Key und TTL.
- **Cleanup** alter verteilter Security-Pfade/Legacy-Jobs.

## Agent

### Canonicalization
`security.ruleset.apply` normalisiert Rulesets in kanonischer Form (`normalizeSecurityRuleSet`) und bildet darauf einen SHA-256 Hash (`hashSecurityRuleSet`).
Vergleich (`securityRuleSetsEqual`) erfolgt hash-basiert auf Canonical JSON.

### LKG pro node+target+backend
State-Dateien liegen nun unter:

- `/var/lib/easywi/security/lkg/<node>__<target>__<backend>.active.json`
- `/var/lib/easywi/security/lkg/<node>__<target>__<backend>.lkg.json`

Persistiert werden zusätzlich `node_id`, `target`, `backend`, `hash`.

### Atomar + Verify + Rollback
Apply nutzt `applySecurityRuleSetAtomically(...)`:

1. Apply target ruleset
2. Verify (`verifySecurityRuleSetApplied`)
3. Bei Fehler: Rollback auf vorherigen Stand

### Self-Lockout
Block-Regeln für essentielle Ports werden abgewiesen (u. a. 22/8080/8443/9443).

### Cleanup legacy paths
Beim Apply werden alte Pfade entfernt:

- `/var/lib/easywi/security/ruleset_state.json`
- `/var/lib/easywi/security/ruleset_last_good.json`

## Symfony

### Canonicalisierung vor Revision/Job
Admin-Controller kanonisiert Unified-Regeln vor Revisionserzeugung, berechnet Hash und übergibt Hash+Target an den Agent-Job.

### Cleanup alter verteilter Settings/Paths
Beim Unified-Apply werden legacy queued Jobs (`firewall.open_ports`, `firewall.close_ports`, `fail2ban.policy.apply`) für den Ziel-Agent auf `cancelled` gesetzt.

### Security Events – strict schema, limit, dedup, TTL
`security.events.collect`-Result wird nur akzeptiert bei Schema `security.events.v1`.

- Payload-Limit: 256 KiB
- Dedup-Key: SHA-256 aus node+direction+source+ip+rule+timestamp
- TTL: aus Agent (`retention_ttl`), geclamped/maximal 30 Tage
- Cleanup: abgelaufene Events werden beim Ingest gelöscht

Zusätzlich enthält `security_events` nun:

- `dedup_key` (indexiert)
- `expires_at` (indexiert)

## Migration
Neue Doctrine-Migration: `Version20261015113000`.

## Twig BBCode-Hardening (Maintenance/CMS)

- URL-Sanitizing nutzt jetzt **Scheme-Allowlist** (`http`, `https`, `mailto`, `tel`) mit Entity-Decoding, Control-Char-Strip, Trim und Block von scheme-relativen `//...` URLs.
- `[code]...[/code]` wird als `<pre><code>...</code></pre>` ausgegeben; Inhalt bleibt escaped und wird nicht via `nl2br` transformiert.
- Parser-Sicherheit: BBCode-Verarbeitung nutzt deterministische String-Scans statt backtracking-lastiger Regex und begrenzt Input-Länge.
- Bei `target="_blank"` wird immer `rel="noopener noreferrer"` gesetzt.
- Maintenance-Responses setzen zusätzlich strikte CSP:
  `default-src 'self'; script-src 'self'; object-src 'none'; base-uri 'self'`.
