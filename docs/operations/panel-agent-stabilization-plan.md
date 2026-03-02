# Panel/Agent Stabilization – Open Points & Fix Plan

## Open Points / Fix Plan (max 25)

1. Login lockout thresholds/TTL per-IP and per-identifier consistently enforced and audited.
2. Session lifecycle checks: absolute timeout, stale-session cleanup, and post-login cookie hardening validated.
3. 2FA flow audit completeness (challenge start/success/failure/lockout) verified end-to-end.
4. Unified security ruleset apply/rollback flow emits deterministic events and audit entries.
5. Ruleset idempotency keys are enforced to avoid duplicate apply/rollback side effects.
6. Webspace/domain provisioning path traversal protections for docroot path joins and symlink escapes.
7. Nginx directives whitelist maintained and regression-tested for forbidden directives.
8. VHost write/apply flow must be atomic with rollback when `nginx -t`/reload fails.
9. Mail platform: enforce mailbox/domain limits and audit high-impact changes.
10. Mail alias loop protection validated against direct and indirect alias cycles.
11. Mail secret hashing and roundcube credential hook consistency tested.
12. Postgres self-service naming/quoting safety validated for generated SQL payloads.
13. DB uniqueness constraints and conflict handling documented + tested.
14. Agent decommission flow prevents new jobs dispatch and revokes credentials/tokens.
15. Rollout pin/rollback behavior validated for agent/core compatibility windows.
16. Installer scripts (Linux/Windows) parity tested for required config invariants.
17. Backup target validation hardened against SSRF-style remote URL abuse.
18. Backup restore archive validation hardened against path traversal before extraction.
19. Backup lock/retention/atomic upload guarantees tested under concurrent scheduling.
20. Voice job retry/idempotency/circuit-breaker transitions tested with controlled failure injection.
21. PTY console handling for backpressure/signals/scrollback cap and private Mercure auth validated.
22. GDPR export flow pending→ready→download plus TTL cleanup and audit chain validated.
23. GDPR download authz and tenant scoping checks verified across customer boundaries.
24. BBCode maintenance parser safety and URL allowlist policy tested.
25. Dead routes/services/jobs/docs removed only after reference scan + compile/tests green.

## Implemented in this change-set


- Login hardening completed for customer/public + API flows:
  - `framework.trusted_proxies` now gates `X-Forwarded-For` usage via `SYMFONY_TRUSTED_PROXIES`.
  - Unknown-user login path now executes a fake password verification to reduce timing leakage.
  - Identifier audit values now use HMAC-SHA256 with configurable pepper (`AUTH_IDENTIFIER_HASH_PEPPER`).
  - Session authenticator now enforces per-user `credentials_version` to invalidate stale sessions globally after credential changes.
  - Legacy cache-based login lockout service was removed in favor of the rate limiter based flow.
  - CSRF remains enforced for form/session flows and session cookies stay `Secure+HttpOnly+SameSite=Strict`; API token login remains cookie-less.

- Agent webspace apply now captures previous vhost state and restores it on validation/reload failure.
- Backup target validation now enforces absolute `https://` URLs, blocks scheme-relative endpoints, resolves DNS, and rejects private/reserved IP ranges to reduce SSRF/DNS-rebinding risk.
- Backup restore now rejects absolute/traversal archive entries and blocks symlink entries/target symlink segments before extraction.
- Backup plan execution now uses plan-scoped locks by default, optional target-scoped locks, and idempotency TTL cleanup in the plan store.
- WebDAV uploads now use atomic temp-upload + MOVE rename + HEAD verification (size/etag checks where available).
- Retention pruning now includes audit output (`pruned/deleted/skipped`) and safe local-delete guards to avoid deleting unsafe/remote paths.
- BBCode URL handling now supports optional host allowlist enforcement for external links.
- Voice query engine now retries only transient failures, keeps circuit-breaker state isolated per server, and enforces half-open probe semantics.
- Non-idempotent voice operations (`createServer`, user-management mutation, token creation) are executed as non-retryable to avoid blind duplicate side effects.
- Voice action logs now redact token values, persist only token hashes, and include a per-action correlation ID for traceability.
- GDPR/DSAR flow hardening completed:
  - Export processor now claims jobs atomically and transitions `pending -> running -> ready|failed` to prevent duplicate processing under concurrency.
  - New cleanup job deletes expired exports and records `gdpr.export_deleted` audit events (`app:gdpr:exports:cleanup`).
  - Download authorization enforces scoped export lookup and allows one-time token links with TTL for delegated retrieval use-cases.
  - Export payload now redacts secret/token/password/private-key fields recursively before archive creation.

## How to run

1. Install dependencies:
   - `cd core && composer install`
   - `cd ../agent && go mod download`
2. Run tests:
   - `cd agent && go test ./...`
   - `cd ../core && ./bin/phpunit tests/Extension/BbcodeTwigExtensionTest.php`
   - `cd ../core && ./bin/console app:gdpr:exports:process --limit=25`
   - `cd ../core && ./bin/console app:gdpr:exports:cleanup --limit=100`
3. Run frontend smoke checks:
   - `cd core && node --test tests/frontend/*.test.js`

## How to validate

1. Webspace rollback: stage a bad nginx directive payload and ensure job fails with configtest/reload error and previous vhost content remains.
2. Backup traversal defense: feed restore with a crafted tar containing `../` entry and confirm restore is rejected.
3. Backup symlink defense: feed restore with a tar containing symlink entries and confirm restore is rejected before extraction.
4. Backup SSRF/DNS defense: configure WebDAV URL using private IP or scheme-relative URL (`//host`) and confirm validation rejects it.
5. Backup lock/idempotency: dispatch concurrent runs for the same plan and verify second run is skipped until lock release; verify idempotency key expires after configured TTL.
4. BBCode allowlist: configure allowlist and verify blocked external hosts render as plain text while allowed hosts keep links.
