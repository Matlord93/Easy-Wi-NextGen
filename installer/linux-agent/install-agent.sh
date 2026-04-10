#!/usr/bin/env bash
set -euo pipefail

VERSION="${1:-latest}"
INSTALL_DIR="${EASYWI_AGENT_INSTALL_DIR:-/usr/local/bin}"
CONFIG_PATH="${EASYWI_AGENT_CONFIG:-/etc/easywi/agent.conf}"
UNIT_PATH="/etc/systemd/system/easywi-agent.service"
LOG_PATH="${EASYWI_AGENT_INSTALL_LOG:-/var/log/easywi-agent-installer.log}"
PROXY_URL="${HTTPS_PROXY:-${https_proxy:-${HTTP_PROXY:-${http_proxy:-}}}}"

log() {
  local message="[easywi-linux-agent] $*"
  printf '%s\n' "$message"
  mkdir -p "$(dirname "$LOG_PATH")" 2>/dev/null || true
  printf '%s %s\n' "$(date -u +'%Y-%m-%dT%H:%M:%SZ')" "$message" >>"$LOG_PATH" 2>/dev/null || true
}

fail() {
  log "ERROR: $*"
  exit 1
}

curl_cmd() {
  local args=()
  if [[ -n "$PROXY_URL" ]]; then
    args+=(--proxy "$PROXY_URL")
  fi
  curl -fsSL "${args[@]}" "$@"
}

resolve_tag() {
  local requested="$1"
  if [[ -n "$requested" && "$requested" != "latest" ]]; then
    echo "$requested"
    return
  fi

  curl_cmd 'https://api.github.com/repos/Matlord93/Easy-Wi-NextGen/releases/latest' \
    -H 'Accept: application/vnd.github+json' \
    -H 'User-Agent: easywi-linux-agent-installer' | jq -r '.tag_name'
}

verify_checksum() {
  local checksums="$1"
  local file="$2"
  local assetName="$3"
  local expected

  expected="$(awk -v n="$assetName" '$2==n {print $1}' "$checksums" | head -n1)"
  [[ -n "$expected" ]] || fail "Keine Prüfsumme für $assetName gefunden"

  local actual
  actual="$(sha256sum "$file" | awk '{print $1}')"
  [[ "$expected" == "$actual" ]] || fail "Checksum mismatch for $assetName"
}

resolve_arch_suffix() {
  local machine
  machine="$(uname -m)"

  case "$machine" in
    x86_64|amd64)
      echo "amd64"
      ;;
    aarch64|arm64)
      echo "arm64"
      ;;
    *)
      fail "Nicht unterstützte Architektur: ${machine}"
      ;;
  esac
}

resolve_agent_asset() {
  local releaseBase="$1"
  local targetDir="$2"
  local checksumsFile="$3"
  local archSuffix="$4"
  local baseName="easywi-agent-linux-${archSuffix}"
  local suffixes=('.tar.gz' '.zip' '')
  local suffix
  local assetName
  local assetPath

  for suffix in "${suffixes[@]}"; do
    assetName="${baseName}${suffix}"
    assetPath="${targetDir}/${assetName}"

    if ! curl_cmd "${releaseBase}/${assetName}" -o "${assetPath}"; then
      continue
    fi

    verify_checksum "$checksumsFile" "${assetPath}" "${assetName}"
    case "${suffix}" in
      '')
        echo "${assetPath}"
        return
        ;;
      '.tar.gz')
        tar -xzf "${assetPath}" -C "${targetDir}"
        ;;
      '.zip')
        command -v unzip >/dev/null || fail 'unzip fehlt'
        unzip -oq "${assetPath}" -d "${targetDir}"
        ;;
    esac

    if [[ -f "${targetDir}/${baseName}" ]]; then
      echo "${targetDir}/${baseName}"
      return
    fi
  done

  fail "Kein passendes Agent-Asset gefunden (geprüft: ${baseName} mit '', .tar.gz, .zip)"
}

write_systemd_unit() {
  local binaryPath="$1"
  local tmpUnit
  tmpUnit="$(mktemp)"

  cat >"$tmpUnit" <<UNIT
[Unit]
Description=EasyWI Agent
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart=${binaryPath} --config ${CONFIG_PATH}
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
UNIT

  if [[ ! -f "$UNIT_PATH" ]] || ! cmp -s "$tmpUnit" "$UNIT_PATH"; then
    install -m 0644 "$tmpUnit" "$UNIT_PATH"
    systemctl daemon-reload
  fi

  rm -f "$tmpUnit"
}

main() {
  command -v curl >/dev/null || fail 'curl fehlt'
  command -v jq >/dev/null || fail 'jq fehlt'
  command -v sha256sum >/dev/null || fail 'sha256sum fehlt'
  command -v systemctl >/dev/null || fail 'systemd fehlt'

  local tag
  tag="$(resolve_tag "$VERSION")"
  [[ -n "$tag" && "$tag" != "null" ]] || fail 'keine Release-Version gefunden'

  local releaseBase="https://github.com/Matlord93/Easy-Wi-NextGen/releases/download/${tag}"
  local targetBinary="${INSTALL_DIR}/easywi-agent"
  local archSuffix
  archSuffix="$(resolve_arch_suffix)"

  mkdir -p "$INSTALL_DIR" "$(dirname "$CONFIG_PATH")"
  local tempDir
  tempDir="$(mktemp -d)"
  trap 'rm -rf "$tempDir"' EXIT

  local checksumsFile="$tempDir/checksums.sha256"

  log "Lade Agent ${tag} herunter"
  curl_cmd "${releaseBase}/checksums.sha256" -o "$checksumsFile"
  local downloadedBinary
  downloadedBinary="$(resolve_agent_asset "$releaseBase" "$tempDir" "$checksumsFile" "$archSuffix")"

  if [[ -f "$targetBinary" ]] && cmp -s "$downloadedBinary" "$targetBinary"; then
    log "Binary bereits aktuell (${tag}), überspringe Austausch"
  else
    install -m 0755 "$downloadedBinary" "$targetBinary"
    log "Binary aktualisiert: $targetBinary"
  fi

  if [[ ! -f "$CONFIG_PATH" ]]; then
    cat >"$CONFIG_PATH" <<'CONF'
# agent_id=node-xxxx
# shared_secret=<secret>
control_listen=0.0.0.0:7443
service_listen=0.0.0.0:7456
CONF
    chmod 600 "$CONFIG_PATH"
    log "Neue Konfiguration angelegt: $CONFIG_PATH"
  else
    log "Bestehende Konfiguration beibehalten: $CONFIG_PATH"
  fi

  write_systemd_unit "$targetBinary"

  if systemctl is-enabled --quiet easywi-agent.service 2>/dev/null; then
    systemctl restart easywi-agent.service
    log 'Service neu gestartet.'
  else
    systemctl enable --now easywi-agent.service
    log 'Service aktiviert und gestartet.'
  fi

  log "Agent ${tag} installiert"
}

main "$@"
