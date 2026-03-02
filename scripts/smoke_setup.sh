#!/usr/bin/env bash
set -euo pipefail

PANEL_ROOT="${PANEL_ROOT:-$(pwd)/core}"
PANEL_BASE_URL="${PANEL_BASE_URL:-http://127.0.0.1:8000}"
HEALTH_PATH="${HEALTH_PATH:-/health}"
WARNINGS=0

ok(){ printf '[PASS] %s\n' "$*"; }
warn(){ printf '[WARN] %s\n' "$*"; WARNINGS=$((WARNINGS+1)); }
fail(){ printf '[FAIL] %s\n' "$*"; exit 1; }

[[ -d "$PANEL_ROOT" ]] || fail "PANEL_ROOT not found: $PANEL_ROOT"
command -v php >/dev/null 2>&1 || fail "php binary missing"
command -v curl >/dev/null 2>&1 || fail "curl binary missing"

ENV_LOCAL="$PANEL_ROOT/.env.local"
if [[ -f "$ENV_LOCAL" ]]; then
  [[ -w "$ENV_LOCAL" ]] || fail "$ENV_LOCAL is not writable"
  ok "$ENV_LOCAL writable"
else
  [[ -w "$PANEL_ROOT" ]] || fail "$PANEL_ROOT is not writable for creating .env.local"
  ok "$ENV_LOCAL missing but panel root writable"
fi

if (cd "$PANEL_ROOT" && php bin/console app:setup:env-bootstrap --check-only --no-interaction >/dev/null); then
  ok "env bootstrap keys present"
else
  fail "env bootstrap check failed; run php bin/console app:setup:env-bootstrap"
fi

if (cd "$PANEL_ROOT" && php bin/console doctrine:query:sql "SELECT setting_value FROM app_settings WHERE setting_key='agent_registration_token' LIMIT 1" --no-interaction 2>/dev/null | grep -Eq '[[:alnum:]]{8,}'); then
  ok "agent_registration_token exists in DB"
else
  fail "agent_registration_token missing in DB; run php bin/console app:settings:ensure-defaults"
fi

code="$(curl -sS -o /dev/null -w '%{http_code}' "${PANEL_BASE_URL}${HEALTH_PATH}" || true)"
if [[ "$code" =~ ^2|3|401|403$ ]]; then
  ok "health endpoint reachable (${code})"
else
  warn "health endpoint check returned ${code} (${PANEL_BASE_URL}${HEALTH_PATH})"
fi

if (cd "$PANEL_ROOT" && php bin/console doctrine:migrations:status --no-interaction 2>/dev/null | grep -q 'New Migrations:.*0'); then
  ok "no pending migrations"
else
  warn "could not confirm pending migration status"
fi

if (( WARNINGS > 0 )); then
  printf '[DONE] smoke_setup completed with %d warning(s).\n' "$WARNINGS"
  exit 2
fi

printf '[DONE] smoke_setup completed successfully.\n'
