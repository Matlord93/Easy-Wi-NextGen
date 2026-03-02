# Core test stability notes (TASK-002)

## Reproducing the historical fatal/abort

The unstable behavior was reproducible with repeated full-suite runs:

```bash
cd core
for i in 1 2 3; do echo "run #$i"; composer run test; done
```

A typical crash signature was:

- `Fatal error: Premature end of PHP process when running App\Tests\...`
- The affected class was often `AccountSecurityFlowTest`, but the abort surfaced in other classes depending on execution order.

## Root cause

`AccountSecurityFlowTest` was mutating shared state in ways that leaked into the rest of the suite:

1. **Global sqlite file reuse (`var/test.db`) + destructive table cleanup**
   - The test deleted rows directly from shared tables (`users`, `sites`, `user_sessions`) while other tests relied on predictable baseline data.
2. **Kernel/container lifecycle side effects**
   - Frequent `ensureKernelShutdown()`/`bootKernel()` cycles happened inside test methods and setup helpers, which made state transitions order-dependent.
3. **Time-dependent session timestamps**
   - Session timestamps used `new DateTimeImmutable()` in-line; this made assertions sensitive to runtime timing and order.

Combined, this produced non-deterministic state and occasional child-process aborts in PHPUnit's runner.

## Fix implemented

`AccountSecurityFlowTest` now uses deterministic setup/teardown:

- Schema + install-lock bootstrap in a deterministic seed helper (once-per-class schema bootstrap still preserved).
- Centralized DB reset before each test using explicit table list and FK toggle.
- Container/EntityManager cleanup in `tearDown()`.
- Fixed clock value for session timestamps (`2035-01-01T00:00:00+00:00`) to remove time variance without expiring seeded sessions.

## Verification

Run this from `core/`:

```bash
php bin/phpunit --filter AccountSecurityFlowTest
for i in 1 2 3; do composer run test -- --filter AccountSecurityFlowTest; done
```

If you need to inspect process crashes in CI, re-run with:

```bash
XDEBUG_MODE=off php -d memory_limit=768M bin/phpunit --debug --filter AccountSecurityFlowTest
```
