# Admin Flows — Operational Acceptance Runbook

## Purpose
Operational checklist for validating admin IA/UX workflows introduced in TASK-019 and preparing TASK-020 rollout.

## Preconditions
- Staging environment with representative data set (users, nodes, instances, mail domains).
- Admin account with full permissions.
- Browser session with cache disabled for repeatable timings.

## Validation steps
1. Execute each workflow in `docs/uiux/task-019-admin-workflow-checklist.md`.
2. Record:
   - click count,
   - start/end timestamp,
   - completion status,
   - error categories.
3. Compare results against defined thresholds.
4. Log deviations and assign to owning module before refactor package freeze.

## Pass/fail policy
- **Pass**: all workflows meet click/time/success targets or have approved waivers.
- **Conditional pass**: <= 2 non-critical misses with documented follow-up tasks.
- **Fail**: any critical workflow (WF-01, WF-03, WF-06, WF-08) violates threshold without waiver.

## Release gate for TASK-020
- IA navigation mapping exists and no route breaks are introduced.
- Active nav states are correct for all touched modules.
- Rollback plan: retain pre-refactor menu ordering via feature flag or template revert.

## Rollback
If navigation regressions are detected:
1. Revert admin layout grouping changes.
2. Keep route endpoints unchanged.
3. Re-run workflow smoke checks to confirm baseline restoration.
