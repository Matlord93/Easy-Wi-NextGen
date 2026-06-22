# TASK-019 — Admin IA/UX Plan (No-Breaking-Change Phase)

## Scope and intent
This document defines the information architecture (IA), UX standards, and rollout constraints for admin navigation. It is a planning baseline for TASK-020 refactor packages and does **not** change public route names.

## 1) Navigation audit
Audit source: `core/templates/layouts/admin.html.twig` route bindings and nav keys.

### Current admin modules/pages (grouped by current sidebar)
- Overview: Dashboard, Activity, Audit Logs
- Accounts: Users, Billing
- Games: Game Instances, Templates, Plugins
- Voice: TS Nodes, TS3 Servers, TS6 Servers, Sinusbot
- System: Security (firewall), Port Pools, Metrics, Nodes, Bootstrap Tokens, DDoS, UniFi, Backup System
- Platform: Settings, Modules, Updates
- Services: Webspaces, Databases
- Shop: Shop Categories, Shop Products
- CMS: Pages, Blog, Events, Team, Media, Public Servers, Forum, CMS Settings
- Domains & Mail: DNS Records, Mail System, Mailboxes, Mail Aliases
- Support: Tickets
- GDPR/Extensions: dynamic extension groups

### Redundancies and structural friction
1. **Settings spread**: operational settings are split across `System`, `Platform`, and `Services`, forcing context switches.
2. **Webspace ownership unclear**: `Webspaces` appears in `Services`, while DNS and public content sit elsewhere.
3. **Voice overlap**: TS nodes/servers and Sinusbot are adjacent but mixed with game-module dependency semantics.
4. **Billing split**: account billing and shop catalog are separate mental models but live in disconnected groups.
5. **Status/ops discoverability**: incidents, maintenance, jobs, metrics are distributed instead of one operations surface.

### Dead links / navigation integrity
- Static audit of all admin sidebar route names found no unresolved route references.
- UX bug fixed: `admin_security` item now uses nav key `security` (was `firewall`), matching controller `activeNav` for correct active-state highlighting.

## 2) Proposed IA (target taxonomy)
Top-level domains for TASK-020:

1. **Nodes**
   - Nodes
   - Bootstrap Tokens
   - Port Pools
   - UniFi
2. **Instances**
   - Game Instances
   - Templates
   - Plugins
   - Databases
3. **Webspace**
   - Webspaces
   - DNS Records
   - Public Servers / CMS surface
4. **Mail**
   - Mail System
   - Mailboxes
   - Mail Aliases
5. **Voice**
   - Voice Nodes
   - TS3/TS6 Servers
   - Sinusbot
6. **Security**
   - Security/Firewall
   - Audit Logs
   - GDPR controls
7. **Billing**
   - Billing
   - Shop Categories
   - Shop Products
8. **Ops**
   - Dashboard
   - Activity
   - Tickets
   - Jobs
   - Metrics
   - Status Incidents/Maintenance/Components
   - Updates / global platform operations

## 3) Redirect strategy (plan only)
No route removals in TASK-019. Strategy for TASK-020+:

1. Keep all existing route names and URLs as canonical during first refactor slice.
2. Introduce IA-alias entries in sidebar that point to existing routes.
3. Add telemetry tags per nav group (`id`) before URL-level redirects.
4. If URL cleanup is needed later:
   - Add 301 redirects from old URLs to new IA URLs.
   - Keep route-name aliases for at least 2 releases.
   - Publish migration notes in release docs.

## 4) Acceptance readiness for TASK-020
- IA buckets finalized and mapped to existing nav keys.
- No-breaking-change rule respected.
- Route-level integrity validated in static audit.
- Sidebar grouping IDs added to support instrumentation and phased migration.
