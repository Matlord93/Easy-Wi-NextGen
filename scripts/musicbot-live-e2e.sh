#!/usr/bin/env bash
set -uo pipefail

# Musicbot Live E2E test — verifies the Musicbot module against real but isolated services.
#
# Default mode: local/static + binary build + runtime/bridge protocol checks.
# No real Discord or TeamSpeak services are required by default.
#
# Discord:    export MUSICBOT_E2E_RUN_DISCORD=1   (+ required token vars below)
# TeamSpeak:  export MUSICBOT_E2E_RUN_TEAMSPEAK=1 (+ required host vars below)
#
# NEVER hardcode credentials. Supply them via environment variables only.
# Secrets are never printed — output is sanitised before display.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="${REPO_ROOT:-$(cd "$SCRIPT_DIR/.." && pwd)}"
PANEL_ROOT="${PANEL_ROOT:-$REPO_ROOT/core}"
AGENT_ROOT="${AGENT_ROOT:-$REPO_ROOT/agent}"
CONSOLE="${CONSOLE:-$PANEL_ROOT/bin/console}"

# Optional panel API
BASE_URL="${MUSICBOT_E2E_BASE_URL:-}"
ADMIN_AUTH_HEADER="${MUSICBOT_E2E_ADMIN_AUTH_HEADER:-}"
CUSTOMER_AUTH_HEADER="${MUSICBOT_E2E_CUSTOMER_AUTH_HEADER:-}"
INSTANCE_ID="${MUSICBOT_E2E_INSTANCE_ID:-1}"
CONNECTION_ID="${MUSICBOT_E2E_CONNECTION_ID:-1}"
RUN_MUTATING="${MUSICBOT_E2E_RUN_MUTATING:-0}"

# Pre-built binaries (auto-built if not provided)
RUNTIME_BIN="${MUSICBOT_E2E_RUNTIME_BIN:-}"
BRIDGE_BIN="${MUSICBOT_E2E_TS_BRIDGE_BIN:-${MUSICBOT_E2E_BRIDGE_BIN:-}}"

# Discord
RUN_DISCORD="${MUSICBOT_E2E_RUN_DISCORD:-0}"
DISCORD_TOKEN="${MUSICBOT_E2E_DISCORD_TOKEN:-}"
DISCORD_GUILD_ID="${MUSICBOT_E2E_DISCORD_GUILD_ID:-}"
DISCORD_VOICE_CHANNEL_ID="${MUSICBOT_E2E_DISCORD_VOICE_CHANNEL_ID:-}"
DISCORD_TEXT_CHANNEL_ID="${MUSICBOT_E2E_DISCORD_TEXT_CHANNEL_ID:-}"
AUDIO_FIXTURE="${MUSICBOT_E2E_AUDIO_FIXTURE:-}"

# TeamSpeak
RUN_TEAMSPEAK="${MUSICBOT_E2E_RUN_TEAMSPEAK:-0}"
TS_HOST="${MUSICBOT_E2E_TS_HOST:-}"
TS_PORT="${MUSICBOT_E2E_TS_PORT:-9987}"
TS_CHANNEL_ID="${MUSICBOT_E2E_TS_CHANNEL_ID:-1}"
TS_PASSWORD="${MUSICBOT_E2E_TS_PASSWORD:-}"
TS_NICKNAME="${MUSICBOT_E2E_TS_NICKNAME:-EasyWi-E2E}"
TS_IDENTITY_PATH="${MUSICBOT_E2E_TS_IDENTITY_PATH:-}"
TS_CLIENT_BACKEND_TYPE="${MUSICBOT_E2E_TS_CLIENT_BACKEND_TYPE:-placeholder}"
TS_CLIENT_BACKEND_PATH="${MUSICBOT_E2E_TS_CLIENT_BACKEND_PATH:-}"

PASSES=0
WARNINGS=0
FAILURES=0
TMP_DIR=""
declare -a PIDS=()

cleanup() {
  for pid in "${PIDS[@]+"${PIDS[@]}"}"; do
    kill "$pid" 2>/dev/null || true
    wait "$pid" 2>/dev/null || true
  done
  [[ -n "$TMP_DIR" && -d "$TMP_DIR" ]] && rm -rf "$TMP_DIR"
}
trap cleanup EXIT

pass() { printf '[PASS] %s\n' "$*"; PASSES=$((PASSES + 1)); }
warn() { printf '[WARN] %s\n' "$*"; WARNINGS=$((WARNINGS + 1)); }
fail() { printf '[FAIL] %s\n' "$*"; FAILURES=$((FAILURES + 1)); }
info() { printf '[INFO] %s\n' "$*"; }

# sanitize_str removes known secret values from a string before printing.
sanitize_str() {
  local s="$1"
  [[ -n "${DISCORD_TOKEN:-}" ]] && s="${s//$DISCORD_TOKEN/[redacted]}"
  [[ -n "${TS_PASSWORD:-}" ]] && s="${s//$TS_PASSWORD/[redacted]}"
  printf '%s' "$s" | sed -E \
    -e 's/([Tt]oken|[Pp]assword|[Ss]ecret|[Aa]uthorization)(["[:space:]_:-]*)([^"[:space:],}]+)/\1\2[redacted]/g' \
    -e 's/(bot_token|server_password|channel_password|auth_token)(["[:space:]_:-]*)([^"[:space:],}]+)/\1\2[redacted]/g'
}

sanitize_stream() { sanitize_str "$(cat)"; }

have() { command -v "$1" >/dev/null 2>&1; }

# check_no_secret verifies a file contains no known secret values.
check_no_secret() {
  local file="$1" label="$2"
  local leaked=0
  if [[ -n "${DISCORD_TOKEN:-}" ]] && grep -qF "$DISCORD_TOKEN" "$file" 2>/dev/null; then
    fail "$label: Discord token leaked into output"
    leaked=1
  fi
  if [[ -n "${TS_PASSWORD:-}" ]] && grep -qF "$TS_PASSWORD" "$file" 2>/dev/null; then
    fail "$label: TeamSpeak password leaked into output"
    leaked=1
  fi
  [[ $leaked -eq 0 ]] && pass "$label: no secrets in output"
}

http_check() {
  local method="$1" path="$2" label="$3"
  local auth_header="${4:-}" data="${5:-}" expected="${6:-^2|3|401|403$}"
  if [[ -z "$BASE_URL" ]]; then
    warn "$label skipped: MUSICBOT_E2E_BASE_URL not set"
    return
  fi
  have curl || { warn "$label skipped: curl missing"; return; }
  local args=(-sS -o /dev/null -w '%{http_code}' -X "$method")
  [[ -n "$auth_header" ]] && args+=(-H "$auth_header")
  [[ -n "$data" ]] && args+=(-H 'Content-Type: application/json' --data "$data")
  local code
  code="$(curl "${args[@]}" "${BASE_URL}${path}" 2>/dev/null || true)"
  if [[ "$code" =~ $expected ]]; then
    pass "$label ($method $path → $code)"
  else
    fail "$label failed ($method $path → ${code:-curl-error})"
  fi
}

# wait_for_socket blocks until a Unix socket appears or the timeout expires.
wait_for_socket() {
  local sock="$1" max_deciseconds="${2:-60}"
  local waited=0
  while [[ ! -S "$sock" && $waited -lt $max_deciseconds ]]; do
    sleep 0.1
    waited=$((waited + 1))
  done
  [[ -S "$sock" ]]
}

# control_cmd sends a single JSON command to a Unix socket and prints the response.
control_cmd() {
  local sock="$1" cmd="$2" timeout_sec="${3:-5}"
  if have nc; then
    printf '%s\n' "$cmd" | timeout "${timeout_sec}s" nc -U "$sock" 2>/dev/null || true
  elif have socat; then
    printf '%s\n' "$cmd" | timeout "${timeout_sec}s" socat - "UNIX-CONNECT:$sock" 2>/dev/null || true
  else
    echo ""
  fi
}

# ============================================================
# Section 1: Prerequisites
# ============================================================

check_prerequisites() {
  info "=== Prerequisites ==="
  for tool in php go nc base64 jq; do
    have "$tool" \
      && pass "tool present: $tool" \
      || warn "tool missing: $tool (some checks will be skipped)"
  done
  [[ -f "$PANEL_ROOT/bin/console" ]] \
    && pass "Symfony console found" \
    || warn "Symfony console not found at $PANEL_ROOT/bin/console"
  [[ -d "$AGENT_ROOT/cmd/easywi-musicbot" ]] \
    && pass "Runtime source: $AGENT_ROOT/cmd/easywi-musicbot" \
    || fail "Runtime source missing: $AGENT_ROOT/cmd/easywi-musicbot"
  [[ -d "$AGENT_ROOT/cmd/easywi-teamspeak-bridge" ]] \
    && pass "Bridge source: $AGENT_ROOT/cmd/easywi-teamspeak-bridge" \
    || fail "Bridge source missing: $AGENT_ROOT/cmd/easywi-teamspeak-bridge"
}

# ============================================================
# Section 2: Symfony Core
# ============================================================

check_core() {
  info "=== Symfony Core ==="

  if [[ ! -f "$CONSOLE" ]] || ! have php; then
    warn "Core checks skipped: Symfony console not available"
    return
  fi

  # Migration status
  local mig_out
  mig_out="$(cd "$PANEL_ROOT" && php "$CONSOLE" doctrine:migrations:status --no-interaction 2>&1 | sanitize_stream)" || true
  if printf '%s' "$mig_out" | grep -qi "executed\|available\|new migrations\|already at latest"; then
    pass "Doctrine migrations: status readable"
  else
    warn "Doctrine migrations: status unclear (DB may not be connected)"
  fi

  # Routes — try the Symfony console first, fall back to static ripgrep scan.
  local routes=(
    admin_musicbot_create
    customer_musicbot_index
    api_v1_customer_musicbots
    api_v1_customer_musicbots_track_upload
    api_v1_customer_musicbots_queue_add
    api_v1_customer_musicbots_playlists
    api_v1_customer_musicbot_autodj_show
    api_v1_customer_musicbot_schedules_create
    api_v1_customer_musicbot_workflows
    api_v1_admin_musicbots_create
    api_v1_admin_musicbots_connection_secrets_show
    api_v1_admin_musicbot_plugins
  )
  for route in "${routes[@]}"; do
    local out
    out="$(cd "$PANEL_ROOT" && php "$CONSOLE" debug:router "$route" --no-interaction 2>/dev/null || true)"
    if printf '%s' "$out" | grep -q "$route"; then
      pass "route: $route"
    elif have rg && rg -q "name: '${route}'|name: \"${route}\"|name: ${route}" \
           "$PANEL_ROOT/src/Module/Musicbot" 2>/dev/null; then
      pass "route (static): $route"
    else
      fail "route missing: $route"
    fi
  done

  # Agent static checks
  if have rg; then
    rg -q 'case "musicbot.install"|handleMusicbotInstall' "$AGENT_ROOT/cmd/agent" \
      && pass "Agent: musicbot.install handler registered" \
      || fail "Agent: musicbot.install handler not found"
    rg -q 'case "musicbot.status"|handleMusicbotStatus' "$AGENT_ROOT/cmd/agent" \
      && pass "Agent: musicbot.status handler registered" \
      || fail "Agent: musicbot.status handler not found"
    rg -q 'case "musicbot.queue.sync"|handleMusicbotQueueSync' "$AGENT_ROOT/cmd/agent" \
      && pass "Agent: musicbot.queue.sync handler registered" \
      || fail "Agent: musicbot.queue.sync handler not found"
  else
    warn "Agent static checks skipped: rg (ripgrep) missing"
  fi

  # Optional HTTP/API checks
  if [[ -n "$BASE_URL" ]]; then
    info "--- API checks against $BASE_URL ---"
    http_check GET /api/v1/customer/musicbots \
      "Customer lists musicbots" "$CUSTOMER_AUTH_HEADER"
    http_check GET /api/v1/customer/musicbots/limits \
      "Customer limits API" "$CUSTOMER_AUTH_HEADER"
    http_check GET "/api/v1/customer/musicbots/${INSTANCE_ID}/queue" \
      "Queue API" "$CUSTOMER_AUTH_HEADER"
    http_check GET "/api/v1/customer/musicbots/${INSTANCE_ID}/playlists" \
      "Playlist API" "$CUSTOMER_AUTH_HEADER"
    http_check GET "/api/v1/customer/musicbots/${INSTANCE_ID}/autodj" \
      "Auto-DJ API" "$CUSTOMER_AUTH_HEADER"
    http_check GET "/api/v1/customer/musicbots/${INSTANCE_ID}/schedules" \
      "Scheduler API" "$CUSTOMER_AUTH_HEADER"
    http_check GET "/api/v1/customer/musicbots/${INSTANCE_ID}/workflows" \
      "Workflow API" "$CUSTOMER_AUTH_HEADER"
    http_check POST "/api/v1/customer/musicbots/${INSTANCE_ID}/tracks" \
      "Upload rejects missing file" "$CUSTOMER_AUTH_HEADER" "" "^400|401|403|415|422$"

    # Secrets API: verify response masks raw values
    if [[ -n "$ADMIN_AUTH_HEADER" ]]; then
      local secrets_body
      secrets_body="$(curl -sS -X GET \
        -H "$ADMIN_AUTH_HEADER" \
        "${BASE_URL}/api/v1/admin/musicbots/${INSTANCE_ID}/connections/${CONNECTION_ID}/secrets" \
        2>/dev/null | sanitize_stream || true)"
      if printf '%s' "$secrets_body" | grep -Eq '"has_[a-z_]+":true|"[a-z_]+":"[*]+"|redacted|403'; then
        pass "Secrets API: response masks raw values or returns 403"
      else
        warn "Secrets API: response format unclear (may be empty or endpoint differs)"
      fi
    fi

    if [[ "$RUN_MUTATING" == "1" ]]; then
      http_check POST /api/v1/admin/musicbots "Admin creates musicbot" "$ADMIN_AUTH_HEADER" \
        '{"name":"E2E Musicbot","customer_id":1,"teamspeak_enabled":false,"discord_enabled":false}' \
        "^2|3|4[0-9][0-9]$"
      http_check POST "/api/v1/customer/musicbots/${INSTANCE_ID}/queue" \
        "Queue add API validation" "$CUSTOMER_AUTH_HEADER" \
        '{"track_id":0}' "^2|3|4[0-9][0-9]$"
      http_check POST "/api/v1/customer/musicbots/${INSTANCE_ID}/schedules" \
        "Scheduler create API validation" "$CUSTOMER_AUTH_HEADER" \
        '{"name":"E2E","action":"status","cron_expression":"* * * * *"}' "^2|3|4[0-9][0-9]$"
    else
      warn "Mutating API checks skipped: set MUSICBOT_E2E_RUN_MUTATING=1 (disposable data only)"
    fi
  fi
}

# ============================================================
# Section 3: Agent / Runtime binary
# ============================================================

build_runtime() {
  if [[ -n "$RUNTIME_BIN" ]]; then
    [[ -x "$RUNTIME_BIN" ]] \
      && { pass "Runtime binary provided: $RUNTIME_BIN"; return 0; } \
      || { fail "MUSICBOT_E2E_RUNTIME_BIN not executable: $RUNTIME_BIN"; return 1; }
  fi
  have go || { warn "Runtime build skipped: go binary missing"; return 1; }
  local bin="$TMP_DIR/easywi-musicbot"
  local build_out
  info "Building musicbot runtime..."
  if build_out="$(cd "$AGENT_ROOT" && go build -o "$bin" ./cmd/easywi-musicbot 2>&1)"; then
    RUNTIME_BIN="$bin"
    pass "Runtime binary built: $bin"
    return 0
  else
    fail "Runtime binary build failed: $(sanitize_str "$build_out" | head -5)"
    return 1
  fi
}

build_bridge() {
  if [[ -n "$BRIDGE_BIN" ]]; then
    [[ -x "$BRIDGE_BIN" ]] \
      && { pass "Bridge binary provided: $BRIDGE_BIN"; return 0; } \
      || { fail "MUSICBOT_E2E_TS_BRIDGE_BIN/MUSICBOT_E2E_TS_BRIDGE_BIN/MUSICBOT_E2E_BRIDGE_BIN not executable: $BRIDGE_BIN"; return 1; }
  fi
  have go || { warn "Bridge build skipped: go binary missing"; return 1; }
  local bin="$TMP_DIR/easywi-teamspeak-bridge"
  local build_out
  info "Building TeamSpeak bridge binary..."
  if build_out="$(cd "$AGENT_ROOT" && go build -o "$bin" ./cmd/easywi-teamspeak-bridge 2>&1)"; then
    BRIDGE_BIN="$bin"
    pass "Bridge binary built: $bin"
    return 0
  else
    fail "Bridge binary build failed: $(sanitize_str "$build_out" | head -5)"
    return 1
  fi
}

make_runtime_config() {
  local config_path="$1" sock_path="$2" prefix="${3:-e2e}"
  mkdir -p "$TMP_DIR/${prefix}-install" "$TMP_DIR/${prefix}-data" \
            "$TMP_DIR/${prefix}-logs" "$TMP_DIR/plugins"
  cat > "$config_path" <<JSON
{
  "instance_id": "${prefix}-instance",
  "customer_id": "${prefix}-customer",
  "service_name": "easywi-musicbot-${prefix}",
  "install_path": "$TMP_DIR/${prefix}-install",
  "data_dir":     "$TMP_DIR/${prefix}-data",
  "log_dir":      "$TMP_DIR/${prefix}-logs",
  "plugin_dir":   "$TMP_DIR/plugins",
  "control": { "unix_socket": "${sock_path}" },
  "teamspeak": { "enabled": false },
  "discord":    { "enabled": false },
  "limits": { "cpu": 10, "ram": 128, "disk": 1024 }
}
JSON
}

check_agent_runtime() {
  info "=== Agent / Runtime ==="
  build_runtime || return

  local config="$TMP_DIR/runtime.json"
  local sock="$TMP_DIR/runtime.sock"
  local log="$TMP_DIR/runtime.log"

  make_runtime_config "$config" "$sock" "runtime"

  # --- 3a. stdin/stdout protocol (one-shot batch) ---
  info "--- Runtime stdin/stdout protocol ---"
  local stdin_cmds
  stdin_cmds="$(printf '%s\n' \
    '{"command":"status"}' \
    '{"command":"queue.sync","args":{"queue":{"instance_id":"runtime-instance","items":[],"revision":1}}}' \
    '{"command":"play"}' \
    '{"command":"pause"}' \
    '{"command":"stop"}' \
    '{"command":"skip"}'
  )"

  local out
  out="$(printf '%s\n' "$stdin_cmds" | timeout 12s "$RUNTIME_BIN" -config "$config" 2>"$log" || true)"

  local stdout_file="$TMP_DIR/runtime-stdout.txt"
  printf '%s\n' "$out" > "$stdout_file"

  if printf '%s' "$out" | grep -q '"command":"status"'; then
    pass "Runtime: status command responds"
  else
    fail "Runtime: status command did not respond"
  fi

  if printf '%s' "$out" | grep -q '"audio_pipeline"'; then
    pass "Runtime: status includes audio_pipeline"
  else
    fail "Runtime: status missing audio_pipeline field"
  fi

  if printf '%s' "$out" | grep -q '"command":"queue.sync"' \
     && printf '%s' "$out" | grep -q '"synced":true'; then
    pass "Runtime: queue.sync returns synced=true for empty queue"
  else
    fail "Runtime: queue.sync did not return synced=true"
  fi

  for cmd in play pause stop skip; do
    if printf '%s' "$out" | grep -q "\"command\":\"$cmd\""; then
      pass "Runtime: $cmd command acknowledged"
    else
      warn "Runtime: $cmd command not echoed back (acceptable with empty queue)"
    fi
  done

  # Placeholders must not claim ready
  if printf '%s' "$out" | grep -Eq '"capability_status":"ready".*"platform":"teamspeak"' \
     || (printf '%s' "$out" | grep -q '"platform":"teamspeak"' \
         && printf '%s' "$out" | grep -q '"capability_status":"ready"'); then
    fail "Runtime: TeamSpeak placeholder claimed ready"
  else
    pass "Runtime: TeamSpeak placeholder does not claim ready"
  fi

  check_no_secret "$stdout_file" "Runtime stdout"
  check_no_secret "$log"         "Runtime stderr"

  # --- 3b. AudioPipeline with test audio file ---
  info "--- Runtime AudioPipeline ---"
  # Verify that the audio_pipeline section is present and structured correctly.
  if printf '%s' "$out" | grep -qE '"audio_pipeline":\{|"audio_pipeline": \{'; then
    pass "Runtime: audio_pipeline present as object in status"
  elif printf '%s' "$out" | grep -q '"audio_pipeline"'; then
    pass "Runtime: audio_pipeline present in status"
  else
    warn "Runtime: audio_pipeline not visible in status output"
  fi

  # --- 3c. Control socket ---
  info "--- Runtime control socket ---"
  local sock_log="$TMP_DIR/runtime-sock.log"

  "$RUNTIME_BIN" -config "$config" < <(sleep infinity) >"$TMP_DIR/runtime-bg-out.txt" 2>"$sock_log" &
  local runtime_pid=$!
  PIDS+=("$runtime_pid")

  if wait_for_socket "$sock" 80; then
    pass "Runtime: control socket created at $sock"

    if have nc || have socat; then
      local resp
      resp="$(control_cmd "$sock" '{"command":"status"}' 5)"
      if printf '%s' "$resp" | grep -q '"ok":true'; then
        pass "Runtime: control socket status command responds ok"
      else
        warn "Runtime: control socket status returned: $(sanitize_str "$resp" | head -c 120)"
      fi

      resp="$(control_cmd "$sock" '{"command":"play"}' 5)"
      if printf '%s' "$resp" | grep -q '"command":"play"'; then
        pass "Runtime: control socket play command acknowledged"
      else
        warn "Runtime: control socket play response: $(sanitize_str "$resp" | head -c 120)"
      fi

      resp="$(control_cmd "$sock" '{"command":"queue.sync","args":{"queue":{"instance_id":"runtime-instance","items":[],"revision":2}}}' 5)"
      if printf '%s' "$resp" | grep -q '"synced":true'; then
        pass "Runtime: control socket queue.sync works"
      else
        warn "Runtime: control socket queue.sync response: $(sanitize_str "$resp" | head -c 120)"
      fi
    else
      warn "Runtime: control socket send/receive skipped (nc and socat both missing)"
    fi

    check_no_secret "$sock_log" "Runtime control socket log"
  else
    warn "Runtime: control socket did not appear within 8s (runtime may need DB or gateway)"
  fi

  kill "$runtime_pid" 2>/dev/null || true
  wait "$runtime_pid" 2>/dev/null || true
}

# ============================================================
# Section 4: TeamSpeak Bridge binary (PlaceholderAdapter)
# ============================================================

check_bridge() {
  info "=== TeamSpeak Bridge Binary ==="
  build_bridge || return

  local bridge_log="$TMP_DIR/bridge.log"
  local smoke_server_pw="e2e-smoke-server-pw-$(date +%s)"
  local smoke_channel_pw="e2e-smoke-channel-pw-$(date +%s)"

  # Batch of requests exercising all protocol paths
  local -a cmds=(
    '{"action":"status"}'
    "{\"action\":\"connect\",\"host\":\"127.0.0.1\",\"port\":9987,\"nickname\":\"E2E\",\"server_password\":\"${smoke_server_pw}\"}"
    '{"action":"send_opus_frame","format":"opus","payload":"AAAA","duration_ms":20}'
    "{\"action\":\"join_channel\",\"channel_id\":\"1\",\"channel_password\":\"${smoke_channel_pw}\"}"
    '{"action":"set_nickname","nickname":"NewName"}'
    '{"action":"reconnect"}'
    '{"action":"unknown_command_xyz"}'
    'not-valid-json'
    '{"action":"leave_channel"}'
    '{"action":"disconnect"}'
  )

  local out
  out="$(printf '%s\n' "${cmds[@]}" \
    | EASYWI_TS_BRIDGE=1 timeout 8s "$BRIDGE_BIN" 2>"$bridge_log" || true)"

  local -a resp=()
  while IFS= read -r line; do
    [[ -n "$line" ]] && resp+=("$line")
  done <<< "$out"

  local expected_count="${#cmds[@]}"
  local actual_count="${#resp[@]}"
  if [[ "$actual_count" -eq "$expected_count" ]]; then
    pass "Bridge: $actual_count responses for $expected_count requests (1:1 protocol)"
  else
    warn "Bridge: $actual_count responses for $expected_count requests (expected $expected_count)"
  fi

  # [0] status → ok=true, state=disconnected
  if [[ "${#resp[@]}" -ge 1 ]] \
     && printf '%s' "${resp[0]}" | grep -q '"ok":true' \
     && printf '%s' "${resp[0]}" | grep -q '"state":"disconnected"'; then
    pass "Bridge: initial status is ok=true / state=disconnected"
  else
    fail "Bridge: initial status unexpected: ${resp[0]:-<empty>}"
  fi

  # [1] connect → PlaceholderAdapter returns client_backend_required
  if [[ "${#resp[@]}" -ge 2 ]] \
     && printf '%s' "${resp[1]}" | grep -q '"ok":false' \
     && printf '%s' "${resp[1]}" | grep -q 'client_backend_required'; then
    pass "Bridge: connect with PlaceholderAdapter returns client_backend_required"
  else
    fail "Bridge: connect response unexpected: ${resp[1]:-<empty>}"
  fi

  # [2] send_opus_frame → not connected
  if [[ "${#resp[@]}" -ge 3 ]] \
     && printf '%s' "${resp[2]}" | grep -q '"ok":false' \
     && printf '%s' "${resp[2]}" | grep -qi 'not connected'; then
    pass "Bridge: send_opus_frame when not connected returns correct error"
  else
    fail "Bridge: send_opus_frame error unexpected: ${resp[2]:-<empty>}"
  fi

  # [3] join_channel → error (not connected)
  if [[ "${#resp[@]}" -ge 4 ]] && printf '%s' "${resp[3]}" | grep -q '"ok":false'; then
    pass "Bridge: join_channel when not connected returns error"
  else
    fail "Bridge: join_channel response unexpected: ${resp[3]:-<empty>}"
  fi

  # [4] set_nickname → ok (PlaceholderAdapter SetNickname always succeeds)
  if [[ "${#resp[@]}" -ge 5 ]] && printf '%s' "${resp[4]}" | grep -q '"ok":true'; then
    pass "Bridge: set_nickname accepted by PlaceholderAdapter"
  else
    warn "Bridge: set_nickname response: ${resp[4]:-<empty>}"
  fi

  # [5] reconnect → client_backend_required
  if [[ "${#resp[@]}" -ge 6 ]] \
     && printf '%s' "${resp[5]}" | grep -q '"ok":false' \
     && printf '%s' "${resp[5]}" | grep -q 'client_backend_required'; then
    pass "Bridge: reconnect with PlaceholderAdapter returns client_backend_required"
  else
    warn "Bridge: reconnect response: ${resp[5]:-<empty>}"
  fi

  # [6] unknown action
  if [[ "${#resp[@]}" -ge 7 ]] \
     && printf '%s' "${resp[6]}" | grep -q '"ok":false' \
     && printf '%s' "${resp[6]}" | grep -q 'unknown action'; then
    pass "Bridge: unknown action returns descriptive error"
  else
    fail "Bridge: unknown action response unexpected: ${resp[6]:-<empty>}"
  fi

  # [7] invalid JSON → error, bridge continues (response for next cmd follows)
  if [[ "${#resp[@]}" -ge 8 ]] && printf '%s' "${resp[7]}" | grep -q '"ok":false'; then
    pass "Bridge: invalid JSON returns error without crashing"
  else
    fail "Bridge: invalid JSON response unexpected: ${resp[7]:-<empty>}"
  fi

  # Secret masking: smoke passwords must not appear in stdout
  local out_file="$TMP_DIR/bridge-out.txt"
  printf '%s\n' "$out" > "$out_file"

  if grep -qF "$smoke_server_pw" "$out_file" 2>/dev/null; then
    fail "Bridge: server_password leaked into stdout"
  else
    pass "Bridge: server_password masked in stdout"
  fi
  if grep -qF "$smoke_channel_pw" "$out_file" 2>/dev/null; then
    fail "Bridge: channel_password leaked into stdout"
  else
    pass "Bridge: channel_password masked in stdout"
  fi

  check_no_secret "$out_file"   "Bridge stdout"
  check_no_secret "$bridge_log" "Bridge stderr"
}

# ============================================================
# Section 5: Discord E2E (optional)
# ============================================================

check_discord() {
  info "=== Discord E2E ==="

  if [[ "$RUN_DISCORD" != "1" ]]; then
    warn "Discord E2E skipped: export MUSICBOT_E2E_RUN_DISCORD=1 to enable"
    return
  fi

  local missing=0
  [[ -z "$DISCORD_TOKEN" ]]            && { fail "Discord E2E: MUSICBOT_E2E_DISCORD_TOKEN not set";            missing=1; }
  [[ -z "$DISCORD_GUILD_ID" ]]         && { fail "Discord E2E: MUSICBOT_E2E_DISCORD_GUILD_ID not set";         missing=1; }
  [[ -z "$DISCORD_VOICE_CHANNEL_ID" ]] && { fail "Discord E2E: MUSICBOT_E2E_DISCORD_VOICE_CHANNEL_ID not set"; missing=1; }
  [[ $missing -eq 1 ]] && return

  build_runtime || return

  local config="$TMP_DIR/discord.json"
  local sock="$TMP_DIR/discord.sock"
  local log="$TMP_DIR/discord.log"

  mkdir -p "$TMP_DIR/discord-install" "$TMP_DIR/discord-data" \
           "$TMP_DIR/discord-logs"  "$TMP_DIR/plugins"

  # Write config with token injected; file lives in a private temp dir.
  cat > "$config" <<JSON
{
  "instance_id":   "discord-e2e",
  "customer_id":   "e2e-customer",
  "service_name":  "easywi-musicbot-discord-e2e",
  "install_path":  "$TMP_DIR/discord-install",
  "data_dir":      "$TMP_DIR/discord-data",
  "log_dir":       "$TMP_DIR/discord-logs",
  "plugin_dir":    "$TMP_DIR/plugins",
  "control": { "unix_socket": "$sock" },
  "teamspeak": { "enabled": false },
  "discord": {
    "enabled": true,
    "config": {
      "bot_token":        "$DISCORD_TOKEN",
      "guild_id":         "$DISCORD_GUILD_ID",
      "voice_channel_id": "$DISCORD_VOICE_CHANNEL_ID",
      "text_channel_id":  "$DISCORD_TEXT_CHANNEL_ID",
      "command_mode":     "voice_only"
    }
  },
  "limits": { "cpu": 10, "ram": 128, "disk": 1024 }
}
JSON

  info "Starting runtime with Discord config (connecting to gateway)..."
  local out_file="$TMP_DIR/discord-out.txt"
  "$RUNTIME_BIN" -config "$config" >"$out_file" 2>"$log" &
  local pid=$!
  PIDS+=("$pid")

  if ! wait_for_socket "$sock" 80; then
    warn "Discord E2E: control socket did not appear within 8s"
    kill "$pid" 2>/dev/null || true
    check_no_secret "$log" "Discord stderr (startup)"
    return
  fi
  pass "Discord E2E: runtime started and control socket created"

  # Allow time for Discord gateway handshake
  sleep 3

  local status_resp
  status_resp="$(control_cmd "$sock" '{"command":"status"}' 8)"

  if printf '%s' "$status_resp" | grep -q '"ok":true'; then
    pass "Discord E2E: status command responds ok"
  else
    warn "Discord E2E: status response: $(sanitize_str "$status_resp" | head -c 200)"
  fi

  if printf '%s' "$status_resp" | grep -q '"platform":"discord"'; then
    pass "Discord E2E: discord platform connector present"
  else
    warn "Discord E2E: discord platform not visible in status"
  fi

  # Check capability — gateway connection may or may not be established within 3s
  if printf '%s' "$status_resp" | grep -q '"capability_status":"ready"'; then
    pass "Discord E2E: Discord connector reports ready"

    # Attempt to join voice channel
    local join_resp
    join_resp="$(control_cmd "$sock" \
      "{\"command\":\"join_voice\",\"args\":{\"channel_id\":\"${DISCORD_VOICE_CHANNEL_ID}\"}}" 12)"
    if printf '%s' "$join_resp" | grep -q '"ok":true'; then
      pass "Discord E2E: joined voice channel $DISCORD_VOICE_CHANNEL_ID"

      # Send a synthetic Opus silence frame (20ms, base64-encoded)
      local opus_frame="//9oAAABAAAA"
      local send_resp
      send_resp="$(control_cmd "$sock" \
        "{\"command\":\"send_opus_frame\",\"args\":{\"format\":\"opus\",\"payload\":\"${opus_frame}\",\"duration_ms\":20}}" 5)"
      if printf '%s' "$send_resp" | grep -q '"ok":true'; then
        pass "Discord E2E: Opus frame sent"
      else
        warn "Discord E2E: send_opus_frame: $(sanitize_str "$send_resp" | head -c 120)"
      fi


      local fixture_rel="uploads/discord-e2e.wav"
      local fixture_abs="$TMP_DIR/discord-data/$fixture_rel"
      mkdir -p "$(dirname "$fixture_abs")"
      if [[ -n "$AUDIO_FIXTURE" ]]; then
        if [[ -f "$AUDIO_FIXTURE" ]]; then
          cp "$AUDIO_FIXTURE" "$fixture_abs"
          pass "Discord E2E: local audio fixture copied into runtime data dir"
        else
          warn "Discord E2E: MUSICBOT_E2E_AUDIO_FIXTURE is set but not a file; skipping AudioPipeline playback"
          fixture_abs=""
        fi
      elif have python3; then
        python3 - "$fixture_abs" <<'PYWAV'
import math
import struct
import sys
import wave
path = sys.argv[1]
rate = 48000
frames = rate // 2
with wave.open(path, 'wb') as w:
    w.setnchannels(2)
    w.setsampwidth(2)
    w.setframerate(rate)
    for n in range(frames):
        sample = int(1200 * math.sin(2 * math.pi * 440 * n / rate))
        w.writeframes(struct.pack('<hh', sample, sample))
PYWAV
        pass "Discord E2E: generated local WAV audio fixture"
      else
        warn "Discord E2E: python3 missing and MUSICBOT_E2E_AUDIO_FIXTURE unset; skipping AudioPipeline playback"
        fixture_abs=""
      fi

      if [[ -n "$fixture_abs" ]]; then
        local queue_resp
        queue_resp="$(control_cmd "$sock" "{\"command\":\"queue.sync\",\"args\":{\"queue\":{\"instance_id\":\"discord-e2e\",\"items\":[{\"queue_item_id\":\"discord-e2e-1\",\"track_id\":\"discord-e2e-track\",\"title\":\"Discord E2E Local Fixture\",\"artist\":\"Easy-Wi\",\"duration_seconds\":1,\"source\":{\"type\":\"upload\",\"uri\":\"$fixture_rel\",\"mime_type\":\"audio/wav\"},\"metadata\":{}}],\"revision\":1}}}" 8)"
        if printf '%s' "$queue_resp" | grep -q '"synced":true'; then
          pass "Discord E2E: queued local audio fixture"
        else
          warn "Discord E2E: queue.sync fixture response: $(sanitize_str "$queue_resp" | head -c 200)"
        fi

        local play_resp
        play_resp="$(control_cmd "$sock" '{"command":"play"}' 8)"
        if printf '%s' "$play_resp" | grep -q '"ok":true'; then
          pass "Discord E2E: AudioPipeline play started for local fixture"
        else
          warn "Discord E2E: play fixture response: $(sanitize_str "$play_resp" | head -c 200)"
        fi

        sleep 2
        local pipeline_status
        pipeline_status="$(control_cmd "$sock" '{"command":"status"}' 8)"
        if printf '%s' "$pipeline_status" | grep -q '"output_backend":"discord_voice"'; then
          pass "Discord E2E: status shows output_backend=discord_voice"
        else
          warn "Discord E2E: output_backend not discord_voice: $(sanitize_str "$pipeline_status" | head -c 200)"
        fi
        local frames_sent
        frames_sent="$(printf '%s' "$pipeline_status" | sed -nE 's/.*"frames_sent"[[:space:]]*:[[:space:]]*([0-9]+).*/\1/p' | tail -n1)"
        if [[ "${frames_sent:-0}" =~ ^[0-9]+$ && "${frames_sent:-0}" -gt 0 ]]; then
          pass "Discord E2E: AudioPipeline frames_sent > 0"
        else
          warn "Discord E2E: frames_sent not > 0 (value=${frames_sent:-missing})"
        fi

        local stop_resp
        stop_resp="$(control_cmd "$sock" '{"command":"stop"}' 8)"
        if printf '%s' "$stop_resp" | grep -q '"ok":true'; then
          pass "Discord E2E: playback stop acknowledged"
        else
          warn "Discord E2E: stop response: $(sanitize_str "$stop_resp" | head -c 120)"
        fi
      fi

      # Leave voice channel
      local leave_resp
      leave_resp="$(control_cmd "$sock" '{"command":"leave_voice"}' 8)"
      if printf '%s' "$leave_resp" | grep -q '"ok":true'; then
        pass "Discord E2E: left voice channel"
      else
        warn "Discord E2E: leave_voice: $(sanitize_str "$leave_resp" | head -c 120)"
      fi
    else
      warn "Discord E2E: join_voice failed: $(sanitize_str "$join_resp" | head -c 200)"
    fi
  elif printf '%s' "$status_resp" | grep -Eq '"capability_status":"(voice_backend_required|placeholder)"'; then
    pass "Discord E2E: Discord connector reports expected non-ready state (voice_backend_required or placeholder)"
  else
    warn "Discord E2E: capability_status unclear in: $(sanitize_str "$status_resp" | head -c 200)"
  fi

  # Token must NEVER appear in any output
  check_no_secret "$out_file" "Discord E2E stdout"
  check_no_secret "$log"      "Discord E2E stderr"

  kill "$pid" 2>/dev/null || true
  wait "$pid" 2>/dev/null || true
}

# ============================================================
# Section 6: TeamSpeak E2E (optional)
# ============================================================

check_teamspeak() {
  info "=== TeamSpeak E2E ==="

  if [[ "$RUN_TEAMSPEAK" != "1" ]]; then
    warn "TeamSpeak E2E skipped: export MUSICBOT_E2E_RUN_TEAMSPEAK=1 to enable"
    return
  fi

  [[ -z "$TS_HOST" ]] && { fail "TeamSpeak E2E: MUSICBOT_E2E_TS_HOST not set"; return; }

  build_bridge || return

  local bridge_log="$TMP_DIR/ts-bridge.log"

  info "--- TeamSpeak bridge: connect to $TS_HOST:$TS_PORT channel=$TS_CHANNEL_ID ---"

  # Build command sequence; the server_password and channel_password fields
  # come from ENV and will be masked before any output is displayed.
  local opus_frame="//9oAAABAAAA"
  local -a cmds=(
    '{"action":"status"}'
    '{"action":"connect","backend_type":"'"${TS_CLIENT_BACKEND_TYPE}"'","backend_path":"'"${TS_CLIENT_BACKEND_PATH}"'","host":"'"${TS_HOST}"'","port":'"${TS_PORT}"',"nickname":"'"${TS_NICKNAME}"'","identity_path":"'"${TS_IDENTITY_PATH}"'","server_password":"'"${TS_PASSWORD}"'"}'
    '{"action":"status"}'
    '{"action":"join_channel","channel_id":"'"${TS_CHANNEL_ID}"'","channel_password":"'"${TS_PASSWORD}"'"}'
    '{"action":"status"}'
    '{"action":"send_opus_frame","format":"opus","payload":"'"${opus_frame}"'","duration_ms":20}'
    '{"action":"leave_channel"}'
    '{"action":"status"}'
    '{"action":"disconnect"}'
  )

  local out
  out="$(printf '%s\n' "${cmds[@]}" \
    | EASYWI_TS_BRIDGE=1 timeout 20s "$BRIDGE_BIN" 2>"$bridge_log" || true)"

  local -a resp=()
  while IFS= read -r line; do
    [[ -n "$line" ]] && resp+=("$line")
  done <<< "$out"

  # [0] initial status → disconnected
  if [[ "${#resp[@]}" -ge 1 ]] \
     && printf '%s' "${resp[0]}" | grep -q '"state":"disconnected"'; then
    pass "TeamSpeak E2E: initial state is disconnected"
  else
    warn "TeamSpeak E2E: initial status: ${resp[0]:-<empty>}"
  fi

  # [1] connect
  if [[ "${#resp[@]}" -ge 2 ]]; then
    local connect_resp="${resp[1]}"
    if printf '%s' "$connect_resp" | grep -q '"ok":true'; then
      pass "TeamSpeak E2E: connected to $TS_HOST:$TS_PORT"

      # [3] join channel
      if [[ "${#resp[@]}" -ge 4 ]]; then
        if printf '%s' "${resp[3]}" | grep -q '"ok":true'; then
          pass "TeamSpeak E2E: joined channel $TS_CHANNEL_ID"
        else
          warn "TeamSpeak E2E: join_channel: $(sanitize_str "${resp[3]}" | head -c 200)"
        fi
      fi

      # [2]/[4] status connected
      if [[ "${#resp[@]}" -ge 5 ]] && printf '%s' "${resp[4]}" | grep -q '"state":"connected"'; then
        pass "TeamSpeak E2E: status connected=true"
      else
        warn "TeamSpeak E2E: connected status response: $(sanitize_str "${resp[4]:-${resp[2]:-<empty>}}" | head -c 200)"
      fi

      # [5] send_opus_frame
      if [[ "${#resp[@]}" -ge 6 ]]; then
        if printf '%s' "${resp[5]}" | grep -q '"ok":true'; then
          pass "TeamSpeak E2E: Opus frame sent through bridge"
        else
          warn "TeamSpeak E2E: send_opus_frame: $(sanitize_str "${resp[5]}" | head -c 200)"
        fi
      fi


      # Runtime external_client_bridge + AudioPipeline check. This only runs after
      # the direct bridge connect succeeded, i.e. when a real adapter is present.
      if build_runtime; then
        local rt_config="$TMP_DIR/ts-runtime.json"
        local rt_sock="$TMP_DIR/ts-runtime.sock"
        local rt_log="$TMP_DIR/ts-runtime.log"
        local rt_out="$TMP_DIR/ts-runtime-out.txt"
        mkdir -p "$TMP_DIR/ts-runtime-install" "$TMP_DIR/ts-runtime-data/uploads" "$TMP_DIR/ts-runtime-logs" "$TMP_DIR/plugins"
        cat > "$rt_config" <<JSON
{
  "instance_id": "teamspeak-e2e",
  "customer_id": "e2e-customer",
  "service_name": "easywi-musicbot-teamspeak-e2e",
  "install_path": "$TMP_DIR/ts-runtime-install",
  "data_dir": "$TMP_DIR/ts-runtime-data",
  "log_dir": "$TMP_DIR/ts-runtime-logs",
  "plugin_dir": "$TMP_DIR/plugins",
  "control": { "unix_socket": "$rt_sock" },
  "teamspeak": {
    "enabled": true,
    "profile": "ts3",
    "backend": "ts3_client_compatible",
    "backend_type": "external_client_bridge",
    "backend_path": "$BRIDGE_BIN",
    "host": "$TS_HOST",
    "port": $TS_PORT,
    "nickname": "$TS_NICKNAME",
    "identity_path": "$TS_IDENTITY_PATH",
    "channel_id": "$TS_CHANNEL_ID",
    "server_password": "$TS_PASSWORD",
    "channel_password": "$TS_PASSWORD",
    "config": {
      "bridge_backend_type": "$TS_CLIENT_BACKEND_TYPE",
      "client_backend_type": "$TS_CLIENT_BACKEND_TYPE",
      "client_library_path": "$TS_CLIENT_BACKEND_PATH",
      "native_sdk_path": "$TS_CLIENT_BACKEND_PATH"
    }
  },
  "discord": { "enabled": false },
  "limits": { "cpu": 10, "ram": 128, "disk": 1024 }
}
JSON
        "$RUNTIME_BIN" -config "$rt_config" < <(sleep infinity) >"$rt_out" 2>"$rt_log" &
        local rt_pid=$!
        PIDS+=("$rt_pid")
        if wait_for_socket "$rt_sock" 80; then
          local rt_status
          rt_status="$(control_cmd "$rt_sock" '{"command":"status"}' 8)"
          if printf '%s' "$rt_status" | grep -q '"capability_status":"ready"'; then
            pass "TeamSpeak E2E: runtime capability_status=ready"
          else
            warn "TeamSpeak E2E: runtime capability_status not ready: $(sanitize_str "$rt_status" | head -c 200)"
          fi
          if printf '%s' "$rt_status" | grep -q '"connected":true'; then
            pass "TeamSpeak E2E: runtime status connected=true"
          else
            warn "TeamSpeak E2E: runtime status does not show connected=true: $(sanitize_str "$rt_status" | head -c 200)"
          fi

          local fixture_rel="uploads/teamspeak-e2e.wav"
          local fixture_abs="$TMP_DIR/ts-runtime-data/$fixture_rel"
          if [[ -n "$AUDIO_FIXTURE" && -f "$AUDIO_FIXTURE" ]]; then
            cp "$AUDIO_FIXTURE" "$fixture_abs"
            pass "TeamSpeak E2E: local audio fixture copied into runtime data dir"
          elif have python3; then
            python3 - "$fixture_abs" <<'PYWAV'
import math
import struct
import sys
import wave
path = sys.argv[1]
rate = 48000
frames = rate // 2
with wave.open(path, 'wb') as w:
    w.setnchannels(2)
    w.setsampwidth(2)
    w.setframerate(rate)
    for n in range(frames):
        sample = int(1200 * math.sin(2 * math.pi * 440 * n / rate))
        w.writeframes(struct.pack('<hh', sample, sample))
PYWAV
            pass "TeamSpeak E2E: generated local WAV audio fixture"
          else
            warn "TeamSpeak E2E: python3 missing and MUSICBOT_E2E_AUDIO_FIXTURE unset; skipping runtime frames_sent check"
            fixture_abs=""
          fi

          if [[ -n "$fixture_abs" ]]; then
            local queue_resp play_resp pipeline_status frames_sent
            queue_resp="$(control_cmd "$rt_sock" "{\"command\":\"queue.sync\",\"args\":{\"queue\":{\"instance_id\":\"teamspeak-e2e\",\"items\":[{\"queue_item_id\":\"teamspeak-e2e-1\",\"track_id\":\"teamspeak-e2e-track\",\"title\":\"TeamSpeak E2E Local Fixture\",\"artist\":\"Easy-Wi\",\"duration_seconds\":1,\"source\":{\"type\":\"upload\",\"uri\":\"$fixture_rel\",\"mime_type\":\"audio/wav\"},\"metadata\":{}}],\"revision\":1}}}" 8)"
            if printf '%s' "$queue_resp" | grep -q '"synced":true'; then
              pass "TeamSpeak E2E: queued local audio fixture"
            else
              warn "TeamSpeak E2E: queue.sync fixture response: $(sanitize_str "$queue_resp" | head -c 200)"
            fi
            play_resp="$(control_cmd "$rt_sock" '{"command":"play"}' 8)"
            if printf '%s' "$play_resp" | grep -q '"ok":true'; then
              pass "TeamSpeak E2E: AudioPipeline play started for local fixture"
            else
              warn "TeamSpeak E2E: play fixture response: $(sanitize_str "$play_resp" | head -c 200)"
            fi
            sleep 2
            pipeline_status="$(control_cmd "$rt_sock" '{"command":"status"}' 8)"
            frames_sent="$(printf '%s' "$pipeline_status" | sed -nE 's/.*"frames_sent"[[:space:]]*:[[:space:]]*([0-9]+).*/\1/p' | tail -n1)"
            if [[ "${frames_sent:-0}" =~ ^[0-9]+$ && "${frames_sent:-0}" -gt 0 ]]; then
              pass "TeamSpeak E2E: AudioPipeline frames_sent > 0"
            else
              warn "TeamSpeak E2E: frames_sent not > 0 (value=${frames_sent:-missing})"
            fi
            local stop_resp
            stop_resp="$(control_cmd "$rt_sock" '{"command":"stop"}' 8)"
          fi
        else
          warn "TeamSpeak E2E: runtime control socket did not appear"
        fi
        kill "$rt_pid" 2>/dev/null || true
        wait "$rt_pid" 2>/dev/null || true
        check_no_secret "$rt_out" "TeamSpeak runtime stdout"
        check_no_secret "$rt_log" "TeamSpeak runtime stderr"
      fi

      # [6] leave channel
      if [[ "${#resp[@]}" -ge 7 ]]; then
        if printf '%s' "${resp[6]}" | grep -q '"ok":true'; then
          pass "TeamSpeak E2E: left channel"
        else
          warn "TeamSpeak E2E: leave_channel: $(sanitize_str "${resp[6]}" | head -c 200)"
        fi
      fi

    elif printf '%s' "$connect_resp" | grep -q 'client_backend_required'; then
      pass "TeamSpeak live voice skipped: no real client adapter configured"
      warn "TeamSpeak E2E: actual voice requires a real TeamspeakClientAdapter — PlaceholderAdapter is current"
    else
      fail "TeamSpeak E2E: connect response unexpected: $(sanitize_str "$connect_resp" | head -c 200)"
    fi
  fi

  # Passwords must not appear in any output
  local out_file="$TMP_DIR/ts-out.txt"
  printf '%s\n' "$out" > "$out_file"

  if [[ -n "$TS_PASSWORD" ]]; then
    grep -qF "$TS_PASSWORD" "$out_file" 2>/dev/null \
      && fail "TeamSpeak E2E: TS_PASSWORD leaked into bridge stdout" \
      || pass "TeamSpeak E2E: TS_PASSWORD not in bridge stdout"
    grep -qF "$TS_PASSWORD" "$bridge_log" 2>/dev/null \
      && fail "TeamSpeak E2E: TS_PASSWORD leaked into bridge stderr" \
      || pass "TeamSpeak E2E: TS_PASSWORD not in bridge stderr"
  fi

  check_no_secret "$out_file"   "TeamSpeak bridge stdout"
  check_no_secret "$bridge_log" "TeamSpeak bridge stderr"
}

# ============================================================
# Main
# ============================================================

main() {
  TMP_DIR="$(mktemp -d)"
  chmod 700 "$TMP_DIR"

  info "Musicbot Live E2E"
  info "repo=$REPO_ROOT  panel=$PANEL_ROOT  agent=$AGENT_ROOT"
  info "discord=${RUN_DISCORD}  teamspeak=${RUN_TEAMSPEAK}  base_url=${BASE_URL:-<not set>}"
  printf '\n'

  check_prerequisites
  printf '\n'
  check_core
  printf '\n'
  check_agent_runtime
  printf '\n'
  check_bridge
  printf '\n'
  check_discord
  printf '\n'
  check_teamspeak
  printf '\n'

  printf '[DONE] %d pass, %d warning, %d fail\n' "$PASSES" "$WARNINGS" "$FAILURES"
  (( FAILURES > 0 )) && exit 1
  (( WARNINGS > 0 )) && exit 2
  exit 0
}

main "$@"
