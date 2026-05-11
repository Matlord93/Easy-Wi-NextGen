# TASK-020 – Admin Technical Refactor Packages (Feature-Flagged)

## 1) Feature-Flag Mechanismus (Symfony env-first)

Die UI-Refactor-Rolloutsteuerung erfolgt über Environment-Variablen und ist **pro Domäne** aktivierbar.

- Globaler CSV-Mechanismus: `APP_UI_REFACTOR_FLAGS=instances,nodes`
- Domänenspezifische Overrides:
  - `APP_UI_REFACTOR_NODES=1`
  - `APP_UI_REFACTOR_INSTANCES=1`
  - `APP_UI_REFACTOR_WEBSPACE=1`
  - `APP_UI_REFACTOR_MAIL=1`
  - `APP_UI_REFACTOR_VOICE=1`
  - `APP_UI_REFACTOR_OPS=1`

Implementierung: `App\Module\Core\Application\UiRefactorFlagService`.

**Priorität:** Domain-ENV (`APP_UI_REFACTOR_<DOMAIN>`) ODER Eintrag in CSV aktiviert die Domäne.

## 2) Refactor-Pakete nach Domänen

1. **Nodes**
   - Node-Liste, Health/Version-Status, Registrierungsflow, Rollen/Portbereiche.
2. **Instances** (Pilot in TASK-020)
   - Listen-/Filter-/Aktionen-UX, Provisionierungszugang, SFTP/Delete Kernaktionen.
3. **Webspace**
   - Host-/Quota-/Suspend-Operationen und Tabellenkonsistenz.
4. **Mail**
   - Mailbox-/Alias-Management inkl. Status-/Passwort-/Quota-Interaktionen.
5. **Voice**
   - TS3/TS6/Sinusbot Node-/Server-Abläufe mit einheitlicher Action-Bar.
6. **Ops**
   - Jobs, Audit-Logs, Metrics, DDoS/Policy-Views, diagnostische Tabellen.

## 3) Komponentenstrategie (wiederverwendbar)

Neue Twig-Komponenten in `core/templates/components/ui/`:

- `_action_bar.html.twig` – einheitliche Header-/Action-Struktur
- `_filter_bar.html.twig` – standardisierter Filter-Container
- `_status_badge.html.twig` – semantische UI-Badges
- `_data_table.html.twig` – Basishülle für tabellarische Layouts

Diese Bausteine werden domänenweise übernommen, um Big-Bang-Rewrites zu vermeiden.

## 4) Pilot-Domäne: Instances (live hinter Flag)

- Flag OFF: bestehende Views bleiben aktiv (`admin/instances/index.html.twig`, `_table.html.twig`).
- Flag ON: Refactor-Views aktiv (`admin/instances/refactor/index.html.twig`, `_table.html.twig`).
- Routen und Controller-Endpunkte bleiben unverändert, damit bestehende Links/Bookmarks/ACL-Regeln stabil bleiben.

## 5) Migrationspfad (Links/ACLs)

- **Links:** Keine Route-Änderungen; bestehende URLs (`/admin/instances`, `/admin/instances/table`, `/admin/instances/provision`) bleiben gleich.
- **ACLs:** Keine Anpassung der Rollen-/Auth-Checks erforderlich; bestehende `isAdmin`-Schutzlogik bleibt aktiv.
- **Rollback:** Flag zurücksetzen (`APP_UI_REFACTOR_INSTANCES=0` bzw. Domain aus CSV entfernen), danach sofortiger Fallback auf Legacy-Templates.
