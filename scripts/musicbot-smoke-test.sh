#!/usr/bin/env bash
set -uo pipefail

# Fast Musicbot smoke test for a freshly merged checkout/deployment.
# The default mode performs local/static checks and optional console/runtime checks.
# HTTP/API checks run only when MUSICBOT_SMOKE_BASE_URL is set.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="${REPO_ROOT:-$(cd "$SCRIPT_DIR/.." && pwd)}"
PANEL_ROOT="${PANEL_ROOT:-$REPO_ROOT/core}"
AGENT_ROOT="${AGENT_ROOT:-$REPO_ROOT/agent}"
CONSOLE="${CONSOLE:-$PANEL_ROOT/bin/console}"
BASE_URL="${MUSICBOT_SMOKE_BASE_URL:-}"
ADMIN_AUTH_HEADER="${MUSICBOT_SMOKE_ADMIN_AUTH_HEADER:-${MUSICBOT_SMOKE_AUTH_HEADER:-}}"
CUSTOMER_AUTH_HEADER="${MUSICBOT_SMOKE_CUSTOMER_AUTH_HEADER:-${MUSICBOT_SMOKE_AUTH_HEADER:-}}"
INSTANCE_ID="${MUSICBOT_SMOKE_INSTANCE_ID:-1}"
CONNECTION_ID="${MUSICBOT_SMOKE_CONNECTION_ID:-1}"
PLUGIN_ID="${MUSICBOT_SMOKE_PLUGIN_ID:-1}"
RUNTIME_BIN="${MUSICBOT_RUNTIME_BIN:-}"
STRICT="${MUSICBOT_SMOKE_STRICT:-0}"
RUN_MUTATING_API="${MUSICBOT_SMOKE_RUN_MUTATING_API:-0}"
TMP_DIR=""
PASSES=0
WARNINGS=0
FAILURES=0

cleanup() {
  if [[ -n "$TMP_DIR" && -d "$TMP_DIR" ]]; then
    rm -rf "$TMP_DIR"
  fi
}
trap cleanup EXIT

pass() { printf '[PASS] %s\n' "$*"; PASSES=$((PASSES + 1)); }
warn() { printf '[WARN] %s\n' "$*"; WARNINGS=$((WARNINGS + 1)); }
fail() { printf '[FAIL] %s\n' "$*"; FAILURES=$((FAILURES + 1)); }

sanitize() {
  sed -E \
    -e 's/([Tt]oken|[Pp]assword|[Ss]ecret|[Aa]uthorization)(["[:space:]_:-]*)([^"[:space:],}]+)/\1\2[redacted]/g' \
    -e 's/(bot_token|server_password|channel_password|auth_token)(["[:space:]_:-]*)([^"[:space:],}]+)/\1\2[redacted]/g'
}

have() { command -v "$1" >/dev/null 2>&1; }

check_file() {
  local path="$1" label="$2"
  if [[ -f "$path" ]]; then
    pass "$label exists: $path"
  else
    fail "$label missing: $path"
  fi
}

rg_check() {
  local label="$1" pattern="$2" path="$3"
  if have rg; then
    if rg -q "$pattern" "$path"; then
      pass "$label"
    else
      fail "$label (pattern not found: $pattern in $path)"
    fi
  else
    warn "ripgrep missing; skipped $label"
  fi
}

console_check() {
  local label="$1"
  shift
  if [[ ! -x "$CONSOLE" ]]; then
    warn "$label skipped: Symfony console not executable at $CONSOLE"
    return
  fi
  if ! have php; then
    warn "$label skipped: php binary missing"
    return
  fi
  local out rc
  out="$(cd "$PANEL_ROOT" && php "$CONSOLE" "$@" --no-interaction 2>&1 | sanitize)"
  rc=$?
  if [[ $rc -eq 0 ]]; then
    pass "$label"
  elif [[ "$STRICT" == "1" ]]; then
    fail "$label failed: $(printf '%s' "$out" | tail -n 3 | tr '\n' ' ')"
  else
    warn "$label unavailable: $(printf '%s' "$out" | tail -n 3 | tr '\n' ' ')"
  fi
}

route_check() {
  local route="$1"
  if [[ -x "$CONSOLE" ]] && have php; then
    local out
    out="$(cd "$PANEL_ROOT" && php "$CONSOLE" debug:router "$route" --no-interaction 2>/dev/null || true)"
    if printf '%s' "$out" | grep -q "$route"; then
      pass "route exists: $route"
      return
    fi
  fi
  if have rg && rg -q "name: '$route'|name: \"$route\"|name: $route" "$PANEL_ROOT/src/Module/Musicbot"; then
    pass "route exists statically: $route"
  else
    fail "route missing: $route"
  fi
}

http_check() {
  local method="$1" path="$2" label="$3" auth_header="$4" data="${5:-}" expected="${6:-^2|3|401|403$}"
  if [[ -z "$BASE_URL" ]]; then
    warn "$label skipped: MUSICBOT_SMOKE_BASE_URL is not set"
    return
  fi
  if ! have curl; then
    warn "$label skipped: curl missing"
    return
  fi
  local args=(-sS -o /dev/null -w '%{http_code}' -X "$method")
  [[ -n "$auth_header" ]] && args+=(-H "$auth_header")
  [[ -n "$data" ]] && args+=(-H 'Content-Type: application/json' --data "$data")
  local code
  code="$(curl "${args[@]}" "${BASE_URL}${path}" 2>/dev/null || true)"
  if [[ "$code" =~ $expected ]]; then
    pass "$label ($method $path -> $code)"
  else
    fail "$label failed ($method $path -> ${code:-curl-error})"
  fi
}

json_no_secret_file() {
  local file="$1" label="$2"
  if grep -Eqi 'smoke-(token|secret|password)|super-secret|bot-token' "$file"; then
    fail "$label leaked a smoke secret marker"
  else
    pass "$label contains no smoke secret marker"
  fi
}

check_plugin_manifest() {
  local manifest="$REPO_ROOT/core/musicbot/plugins/easywi-teamspeak-integration/manifest.json"
  check_file "$manifest" "TeamSpeak integration plugin manifest"
  if have php && [[ -f "$manifest" ]]; then
    if php -r '$j=json_decode(file_get_contents($argv[1]), true); exit((isset($j["identifier"], $j["first_party"], $j["removable"]) && $j["first_party"] === true && $j["removable"] === false) ? 0 : 1);' "$manifest"; then
      pass "plugin manifest marks first-party non-removable integration"
    else
      fail "plugin manifest is invalid or does not mark first-party/non-removable"
    fi
  fi
}

check_runtime_status() {
  if [[ -z "$RUNTIME_BIN" ]]; then
    warn "runtime status skipped: set MUSICBOT_RUNTIME_BIN to an easywi-musicbot binary"
    return
  fi
  if [[ ! -x "$RUNTIME_BIN" ]]; then
    warn "runtime status skipped: MUSICBOT_RUNTIME_BIN is not executable: $RUNTIME_BIN"
    return
  fi
  TMP_DIR="$(mktemp -d)"
  local config="$TMP_DIR/musicbot-runtime.json"
  cat > "$config" <<JSON
{
  "instance_id": "smoke-instance",
  "customer_id": "smoke-customer",
  "service_name": "easywi-musicbot-smoke",
  "install_path": "$TMP_DIR/install",
  "data_dir": "$TMP_DIR/data",
  "log_dir": "$TMP_DIR/logs",
  "plugin_dir": "$TMP_DIR/plugins",
  "teamspeak": {
    "enabled": true,
    "profile": "ts3",
    "backend": "ts3_client_compatible",
    "backend_type": "placeholder",
    "host": "127.0.0.1",
    "nickname": "Easy-Wi Smoke",
    "channel_id": "1",
    "server_password": "smoke-server-password",
    "channel_password": "smoke-channel-password"
  },
  "discord": {
    "enabled": true,
    "config": {
      "command_mode": "placeholder",
      "bot_token": "smoke-bot-token",
      "voice_channel_id": "1"
    }
  },
  "limits": {"cpu": 10, "ram": 128, "disk": 1024}
}
JSON
  local out="$TMP_DIR/status.json"
  if printf '{"command":"status"}\n{"command":"queue.sync","args":{"queue":{"instance_id":"smoke-instance","items":[]}}}\n' | timeout 8s "$RUNTIME_BIN" -config "$config" 2>/dev/null | sanitize > "$out"; then
    if grep -q '"ok":true' "$out" && grep -q '"audio_pipeline"' "$out"; then
      pass "runtime status exposes audio_pipeline"
    else
      fail "runtime status did not include ok=true and audio_pipeline"
    fi
    if grep -q '"command":"queue.sync"' "$out" && grep -q '"synced":true' "$out"; then
      pass "runtime queue.sync command works with an empty local queue"
    else
      fail "runtime queue.sync command did not report synced=true"
    fi
    if grep -q '"output_backend":"null"' "$out"; then
      pass "runtime output_backend may be null without a real backend"
    else
      warn "runtime output_backend was not null; verify configured backend intentionally reports ready"
    fi
    if grep -q '"capability_status":"ready"' "$out" && grep -q '"platform":"teamspeak"' "$out"; then
      fail "TeamSpeak placeholder reported ready"
    else
      pass "TeamSpeak placeholder does not report ready"
    fi
    if grep -Eq '"capability_status":"(placeholder|voice_backend_required|client_backend_required)"' "$out"; then
      pass "Discord/TeamSpeak placeholder capability status is distinguishable"
    else
      fail "placeholder capability status was not distinguishable"
    fi
    json_no_secret_file "$out" "runtime status"
  else
    warn "runtime status command failed; run with a built MUSICBOT_RUNTIME_BIN for runtime smoke coverage"
  fi
}

check_agent_static() {
  rg_check "agent registers musicbot.status job" 'case "musicbot.status"|handleMusicbotStatus' "$AGENT_ROOT/cmd/agent"
  rg_check "agent registers musicbot.queue.sync job" 'case "musicbot.queue.sync"|handleMusicbotQueueSync' "$AGENT_ROOT/cmd/agent"
  rg_check "agent can create musicbot.install job payload" 'case "musicbot.install"|handleMusicbotInstall' "$AGENT_ROOT/cmd/agent"
}

check_core_static() {
  route_check admin_musicbot_create
  route_check customer_musicbot_index
  route_check api_v1_customer_musicbots
  route_check api_v1_customer_musicbots_limits
  route_check api_v1_customer_musicbots_track_upload
  route_check api_v1_customer_musicbots_queue_add
  route_check api_v1_customer_musicbots_playlists
  route_check api_v1_customer_musicbot_schedules_create
  route_check api_v1_customer_musicbot_autodj_show
  route_check api_v1_admin_musicbot_plugins
  route_check api_v1_admin_musicbots_connection_secrets_show
  route_check api_v1_admin_musicbots_create

  rg_check "admin create queues runtime install job" "musicbot.install" "$PANEL_ROOT/src/Module/Musicbot/UI/Controller/Admin/AdminMusicbotController.php"
  rg_check "customer dashboard scopes musicbots to customer" "findByCustomer|findInstanceForCustomer" "$PANEL_ROOT/src/Module/Musicbot/UI/Controller/Customer/CustomerMusicbotController.php"
  rg_check "upload service validates MIME types" "MIME_TO_EXTENSION|Unsupported audio file type" "$PANEL_ROOT/src/Module/Musicbot/Application/MusicbotTrackService.php"
  rg_check "queue sync job is dispatched" "musicbot.queue.sync" "$PANEL_ROOT/src/Module/Musicbot"
  rg_check "workflow API/routes exist" "workflow|workflows" "$PANEL_ROOT/src/Module/Musicbot/UI/Controller/Api/MusicbotApiController.php"
  rg_check "secret API filters allowed keys" "SECRET_KEYS|redacted|has_" "$PANEL_ROOT/src/Module/Musicbot/UI/Controller/Api/MusicbotApiController.php"
  rg_check "TeamSpeak placeholder/backend statuses are modeled" "client_backend_required|backend_type|teamspeak_voice" "$AGENT_ROOT/internal/musicbot/runtime"
  rg_check "Discord placeholder/real statuses are modeled" "voice_backend_required|discord_gateway|discord_voice" "$AGENT_ROOT/internal/musicbot/runtime"
}

check_api_optional() {
  http_check GET /api/musicbots/status "Musicbot module status API" "$CUSTOMER_AUTH_HEADER"
  http_check GET /api/v1/customer/musicbots "Customer lists own musicbots API" "$CUSTOMER_AUTH_HEADER"
  http_check GET /api/v1/customer/musicbots/limits "Customer limits API" "$CUSTOMER_AUTH_HEADER"
  http_check GET "/api/v1/customer/musicbots/${INSTANCE_ID}/queue" "Queue API" "$CUSTOMER_AUTH_HEADER"
  http_check GET "/api/v1/customer/musicbots/${INSTANCE_ID}/playlists" "Playlist API" "$CUSTOMER_AUTH_HEADER"
  http_check GET "/api/v1/customer/musicbots/${INSTANCE_ID}/plugins" "Plugin manifest/status API" "$CUSTOMER_AUTH_HEADER"
  http_check GET "/api/v1/customer/musicbots/${INSTANCE_ID}/schedules" "Scheduler API" "$CUSTOMER_AUTH_HEADER"
  http_check GET /api/v1/admin/musicbot-plugins "Admin plugin API" "$ADMIN_AUTH_HEADER"
  http_check GET "/api/v1/admin/musicbots/${INSTANCE_ID}/connections/${CONNECTION_ID}/secrets" "Secret API redacted access" "$ADMIN_AUTH_HEADER"

  if [[ "$RUN_MUTATING_API" == "1" ]]; then
    http_check POST /api/v1/admin/musicbots "Admin create musicbot API" "$ADMIN_AUTH_HEADER" '{"name":"Smoke Musicbot","node_id":"smoke-node","customer_id":1,"teamspeak_enabled":false,"discord_enabled":false}' '^2|3|4[0-9][0-9]$'
    http_check POST "/api/v1/customer/musicbots/${INSTANCE_ID}/queue" "Queue add API validation" "$CUSTOMER_AUTH_HEADER" '{"track_id":0}' '^2|3|4[0-9][0-9]$'
    http_check POST "/api/v1/customer/musicbots/${INSTANCE_ID}/tracks" "Upload API validates invalid file request" "$CUSTOMER_AUTH_HEADER" '' '^400|401|403|404|422$'
    http_check POST "/api/v1/customer/musicbots/${INSTANCE_ID}/schedules" "Scheduler create API validation" "$CUSTOMER_AUTH_HEADER" '{"name":"Smoke","action":"status","cron_expression":"* * * * *"}' '^2|3|4[0-9][0-9]$'
  else
    warn "mutating API checks skipped: set MUSICBOT_SMOKE_RUN_MUTATING_API=1 with disposable test data"
  fi
}

main() {
  printf '[INFO] Musicbot smoke test repo=%s panel=%s agent=%s\n' "$REPO_ROOT" "$PANEL_ROOT" "$AGENT_ROOT"
  check_file "$PANEL_ROOT/bin/console" "Symfony console"
  check_file "$AGENT_ROOT/cmd/agent/musicbot.go" "Agent Musicbot job handlers"
  check_file "$AGENT_ROOT/cmd/easywi-musicbot/main.go" "Musicbot runtime command"

  console_check "Doctrine migrations are executable" doctrine:migrations:status
  check_core_static
  check_plugin_manifest
  check_agent_static
  check_runtime_status
  check_api_optional

  printf '[DONE] %d pass, %d warning, %d fail\n' "$PASSES" "$WARNINGS" "$FAILURES"
  if (( FAILURES > 0 )); then
    exit 1
  fi
  if (( WARNINGS > 0 )); then
    exit 2
  fi
}

main "$@"
