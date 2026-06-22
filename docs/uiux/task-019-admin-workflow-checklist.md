# TASK-019 — Admin Workflow Checklist & Success Criteria

This checklist defines measurable acceptance criteria for high-frequency admin tasks.

## Measurement model
- **Clicks**: number of intentional UI interactions from admin dashboard to completion.
- **Time-to-task**: median time for trained internal operator in a staging environment.
- **Success rate**: completion without rollback or validation error.

## Core workflows (8)

### WF-01: Provision game instance from template
- Path: Instances → Game Instances → Create
- Success criteria:
  - <= 6 clicks to submit
  - <= 90 seconds median
  - >= 95% success rate first attempt

### WF-02: Assign node capacity and validate ports
- Path: Nodes → Nodes / Port Pools
- Success criteria:
  - <= 7 clicks for capacity + port update
  - <= 120 seconds median
  - zero overlapping-port validation escapes

### WF-03: Create webspace and map DNS
- Path: Webspace → Webspaces + DNS Records
- Success criteria:
  - <= 8 clicks end-to-end
  - <= 150 seconds median
  - >= 95% success with valid DNS propagation target

### WF-04: Create mailbox and alias
- Path: Mail → Mailboxes / Mail Aliases
- Success criteria:
  - <= 7 clicks for mailbox + alias
  - <= 120 seconds median
  - >= 98% save success

### WF-05: Provision voice server
- Path: Voice → TS3/TS6 Servers
- Success criteria:
  - <= 6 clicks to server creation
  - <= 90 seconds median
  - >= 95% provisioning success

### WF-06: Investigate security event
- Path: Security → Audit Logs / Firewall
- Success criteria:
  - first relevant event visible <= 45 seconds
  - filter-to-result <= 3 interactions
  - >= 90% correct event identification in review sample

### WF-07: Process billing catalog update
- Path: Billing → Shop Categories / Products
- Success criteria:
  - <= 8 clicks for category + product update
  - <= 180 seconds median
  - >= 95% successful publish

### WF-08: Run incident communication loop
- Path: Ops → Status Incidents + Tickets
- Success criteria:
  - incident creation <= 60 seconds
  - update publish <= 30 seconds per message
  - >= 95% on-time status update SLA compliance

## UI pattern library (baseline)

### Tables
- Sticky header, sortable columns, row-level primary action.
- Mandatory empty state with next-step CTA.
- Batch selection only when >= 1 bulk action exists.

### Filters
- Default quick filters: status, owner/customer, date range.
- Advanced filters in collapsible section.
- Reset returns to deterministic default query.

### Action bars
- Primary action right-aligned, contextual bulk actions left-aligned.
- Destructive actions separated and confirmation-gated.
- Busy state must disable duplicate submissions.

### Status system
- Use canonical status chips: `active`, `pending`, `failed`, `disabled`, `archived`.
- Status colors must be consistent across modules.
- Error state must always include remediation hint.

## Regression guardrails
- Keep current route names intact while IA labels/groups evolve.
- Validate active navigation highlighting for each top-level module key.
- Validate that each top-level IA bucket has at least one actionable landing entry.

## Definition of done support for TASK-020
- Workflow metrics are measurable and testable in staging.
- Pattern rules can be converted into component-level acceptance tests.
- IA structure is ready for incremental refactor packages.
