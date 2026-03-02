#!/usr/bin/env bash
set -euo pipefail

# Production/staging smoke preflight for gameserver module.
# Safe defaults; override with env vars as needed.

PANEL_BASE_URL="${PANEL_BASE_URL:-http://127.0.0.1:8000}"
PANEL_HEALTH_PATH="${PANEL_HEALTH_PATH:-/}"
AGENT_HEALTH_URL="${AGENT_HEALTH_URL:-http://127.0.0.1:8087/healthz}"
INSTANCE_UNIT_GLOB="${INSTANCE_UNIT_GLOB:-gs-*}"
HEARTBEAT_MAX_AGE_SECONDS="${HEARTBEAT_MAX_AGE_SECONDS:-120}"
PANEL_ROOT="${PANEL_ROOT:-$(pwd)/core}"
BACKUP_TEST_LOCAL_URL="${BACKUP_TEST_LOCAL_URL:-}"
BACKUP_TEST_WEBDAV_URL="${BACKUP_TEST_WEBDAV_URL:-}"
BACKUP_TEST_AUTH_HEADER="${BACKUP_TEST_AUTH_HEADER:-}"

SMOKE_DEPLOY="${SMOKE_DEPLOY:-0}"
DEPLOY_ENV="${DEPLOY_ENV:-dev}"
DEPLOY_COMPOSE_FILE="${DEPLOY_COMPOSE_FILE:-deploy/compose/${DEPLOY_ENV}/docker-compose.yml}"
DEPLOY_WAIT_SECONDS="${DEPLOY_WAIT_SECONDS:-60}"
SMOKE_SKIP_RUNTIME_CHECKS="${SMOKE_SKIP_RUNTIME_CHECKS:-0}"
CONSOLE_STREAM_URL="${CONSOLE_STREAM_URL:-}"
CONSOLE_STREAM_HEADER="${CONSOLE_STREAM_HEADER:-}"
CONSOLE_STREAM_EXPECT="${CONSOLE_STREAM_EXPECT:-event:}"

WARNINGS=0

ok() { printf '[OK] %s\n' "$*"; }
warn() { printf '[WARN] %s\n' "$*"; WARNINGS=$((WARNINGS + 1)); }
fail() { printf '[FAIL] %s\n' "$*"; exit 1; }

require_cmd() {
    command -v "$1" >/dev/null 2>&1 || fail "Required command missing: $1"
}

http_check() {
    local url="$1"
    local name="$2"
    local code
    code="$(curl -sS -o /dev/null -w '%{http_code}' "$url" || true)"
    if [[ "$code" =~ ^2|3|401|403$ ]]; then
        ok "$name reachable ($code): $url"
    else
        fail "$name unreachable ($code): $url"
    fi
}

check_panel() {
    http_check "${PANEL_BASE_URL}${PANEL_HEALTH_PATH}" "Panel"
}

check_agent_health() {
    http_check "$AGENT_HEALTH_URL" "Agent health"
}

check_systemd_units() {
    require_cmd systemctl

    local units
    units="$(systemctl list-units --type=service --state=running "$INSTANCE_UNIT_GLOB" --no-legend --no-pager | awk '{print $1}' || true)"

    if [[ -z "$units" ]]; then
        warn "No running units matched pattern: $INSTANCE_UNIT_GLOB"
        return
    fi

    ok "Detected running instance units"
    while IFS= read -r unit; do
        [[ -z "$unit" ]] && continue
        local pid
        pid="$(systemctl show -p MainPID --value "$unit" 2>/dev/null || true)"
        if [[ -n "$pid" && "$pid" != "0" ]]; then
            ok "Unit $unit MainPID=$pid"
        else
            warn "Unit $unit has no active MainPID"
        fi
    done <<< "$units"
}

check_agent_heartbeat() {
    if [[ ! -x "$PANEL_ROOT/bin/console" ]]; then
        warn "Symfony console not found at $PANEL_ROOT/bin/console; skipping heartbeat DB freshness"
        return
    fi

    if ! command -v php >/dev/null 2>&1; then
        warn "php binary missing; skipping heartbeat DB freshness"
        return
    fi

    local sql out
    sql="SELECT id,last_heartbeat_at FROM agents ORDER BY last_heartbeat_at DESC LIMIT 1;"

    if ! out="$(cd "$PANEL_ROOT" && php bin/console doctrine:query:sql "$sql" --no-interaction 2>/dev/null || true)"; then
        warn "Could not query heartbeat freshness"
        return
    fi

    if [[ -z "$out" ]]; then
        warn "No heartbeat rows returned from agents table"
        return
    fi

    local ts
    ts="$(printf '%s\n' "$out" | grep -Eo '[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}' | head -n1 || true)"

    if [[ -z "$ts" ]]; then
        warn "Could not parse last_heartbeat_at from doctrine output"
        return
    fi

    local now epoch_ts age
    now="$(date -u +%s)"
    epoch_ts="$(date -u -d "$ts" +%s 2>/dev/null || true)"
    if [[ -z "$epoch_ts" ]]; then
        warn "Could not parse heartbeat timestamp: $ts"
        return
    fi

    age=$((now - epoch_ts))
    if (( age <= HEARTBEAT_MAX_AGE_SECONDS )); then
        ok "Agent heartbeat fresh (${age}s <= ${HEARTBEAT_MAX_AGE_SECONDS}s)"
    else
        warn "Agent heartbeat stale (${age}s > ${HEARTBEAT_MAX_AGE_SECONDS}s)"
    fi
}


check_console_stream_attach() {
    if [[ -z "$CONSOLE_STREAM_URL" ]]; then
        warn "CONSOLE_STREAM_URL not set; skipping live-console attach smoke"
        return
    fi

    local output
    if [[ -n "$CONSOLE_STREAM_HEADER" ]]; then
        output="$(curl -sS --max-time 8 -N -H "$CONSOLE_STREAM_HEADER" "$CONSOLE_STREAM_URL" 2>/dev/null || true)"
    else
        output="$(curl -sS --max-time 8 -N "$CONSOLE_STREAM_URL" 2>/dev/null || true)"
    fi

    if [[ "$output" == *"$CONSOLE_STREAM_EXPECT"* ]]; then
        ok "Live-console attach smoke succeeded"
    else
        warn "Live-console attach smoke returned no expected marker ($CONSOLE_STREAM_EXPECT)"
    fi
}

check_backup_test_endpoint() {
    local url="$1"
    local name="$2"

    if [[ -z "$url" ]]; then
        warn "$name backup test URL not set; skipping"
        return
    fi

    local code
    if [[ -n "$BACKUP_TEST_AUTH_HEADER" ]]; then
        code="$(curl -sS -o /dev/null -w '%{http_code}' -H "$BACKUP_TEST_AUTH_HEADER" "$url" || true)"
    else
        code="$(curl -sS -o /dev/null -w '%{http_code}' "$url" || true)"
    fi

    if [[ "$code" =~ ^2|3|401|403$ ]]; then
        ok "$name backup test endpoint reachable ($code): $url"
    else
        warn "$name backup test endpoint check failed ($code): $url"
    fi
}

check_deploy_compose() {
    require_cmd docker

    [[ -f "$DEPLOY_COMPOSE_FILE" ]] || fail "Compose file not found: $DEPLOY_COMPOSE_FILE"

    docker compose -f "$DEPLOY_COMPOSE_FILE" config >/dev/null
    ok "Compose config valid: $DEPLOY_COMPOSE_FILE"

    docker compose -f "$DEPLOY_COMPOSE_FILE" up -d db queue core agent >/dev/null
    ok "Compose stack started for env=$DEPLOY_ENV"

    local waited=0
    while (( waited < DEPLOY_WAIT_SECONDS )); do
        local unhealthy
        unhealthy="$(docker compose -f "$DEPLOY_COMPOSE_FILE" ps --format json | jq -r 'select((.Service=="core" or .Service=="agent" or .Service=="db" or .Service=="queue") and (.Health != "healthy" and .Health != "")) | .Service' || true)"

        if [[ -z "$unhealthy" ]]; then
            ok "All required services are healthy or running"
            return
        fi

        sleep 3
        waited=$((waited + 3))
    done

    docker compose -f "$DEPLOY_COMPOSE_FILE" ps || true
    fail "Required services did not become healthy within ${DEPLOY_WAIT_SECONDS}s"
}

main() {
    require_cmd curl
    require_cmd awk
    require_cmd grep

    if [[ "$SMOKE_DEPLOY" == "1" ]]; then
        require_cmd jq
        check_deploy_compose
        return
    fi

    if [[ "$SMOKE_SKIP_RUNTIME_CHECKS" == "1" ]]; then
        ok "Runtime smoke checks skipped (SMOKE_SKIP_RUNTIME_CHECKS=1)"
        printf '[DONE] Smoke preflight completed successfully.\n'
        return
    fi

    check_panel
    check_agent_health
    check_systemd_units
    check_agent_heartbeat
    check_backup_test_endpoint "$BACKUP_TEST_LOCAL_URL" "Local"
    check_console_stream_attach
    check_backup_test_endpoint "$BACKUP_TEST_WEBDAV_URL" "WebDAV"

    if (( WARNINGS > 0 )); then
        printf '[DONE] Smoke preflight completed with %d warning(s).\n' "$WARNINGS"
        exit 2
    fi

    printf '[DONE] Smoke preflight completed successfully.\n'
}

main "$@"
