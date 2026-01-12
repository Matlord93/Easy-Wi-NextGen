#!/usr/bin/env bash
set -euo pipefail

VERSION="0.1.0"

REPO_OWNER="${EASYWI_REPO_OWNER:-Matlord93}"
REPO_NAME="${EASYWI_REPO_NAME:-Easy-Wi-NextGen}"
GITHUB_BASE_URL="https://github.com/${REPO_OWNER}/${REPO_NAME}/releases/download"
CORE_URL="${EASYWI_CORE_URL:-${EASYWI_API_URL:-https://api.easywi.example}}"
API_URL="${CORE_URL}"
AGENT_VERSION="${EASYWI_AGENT_VERSION:-latest}"
RUNNER_VERSION="${EASYWI_RUNNER_VERSION:-latest}"
AGENT_VERSION_RESOLVED=""
BOOTSTRAP_TOKEN="${EASYWI_BOOTSTRAP_TOKEN:-}"
ROLE_LIST="${EASYWI_ROLES:-}"
CHANNEL="${EASYWI_CHANNEL:-}"
MAIL_HOSTNAME="${EASYWI_MAIL_HOSTNAME:-}"
DB_BIND_ADDRESS="${EASYWI_DB_BIND_ADDRESS:-127.0.0.1}"
DB_ALLOWED_SUBNET="${EASYWI_DB_SUBNET:-}"
INTERACTIVE="${EASYWI_INTERACTIVE:-}"
NON_INTERACTIVE="${EASYWI_NON_INTERACTIVE:-}"
DIAGNOSTICS_MODE="${EASYWI_DIAGNOSTICS:-auto}"
DRY_RUN="${EASYWI_DRY_RUN:-}"
LOG_FILE="/var/log/easywi/installer.log"

LOG_PREFIX="[easywi-installer]"
STEP_COUNTER=0

usage() {
  cat <<USAGE
EasyWI Installer (Linux) v${VERSION}

Usage:
  easywi-installer-linux.sh [options]

Options:
  --roles <list>         Comma-separated roles: game,web,dns,mail,db
  --bootstrap-token <t>  Bootstrap token for registration
  --core-url <url>       EasyWI Core API base URL
  --agent-version <v>    Agent release version (default: latest)
  --runner-version <v>   Runner release version (default: latest)
  --dry-run              Print planned API calls without applying changes
  --channel <name>       Release channel/tag (overrides latest)
  --mail-hostname <name> Mail hostname for the mail role
  --db-bind-address <ip> Bind address for database services (default: 127.0.0.1)
  --db-subnet <cidr>     Allowed subnet CIDR for database firewall/pg_hba
  --repo-owner <owner>   GitHub repo owner
  --repo-name <name>     GitHub repo name
  --interactive          Prompt for missing values
  --non-interactive      Disable prompts, fail if required values missing
  --diagnostics <mode>   Diagnostics bundle: auto, always, never
  -h, --help             Show help

Environment variables:
  EASYWI_REPO_OWNER, EASYWI_REPO_NAME, EASYWI_CORE_URL, EASYWI_API_URL,
  EASYWI_AGENT_VERSION, EASYWI_CHANNEL, EASYWI_MAIL_HOSTNAME,
  EASYWI_RUNNER_VERSION,
  EASYWI_BOOTSTRAP_TOKEN, EASYWI_ROLES, EASYWI_DB_BIND_ADDRESS,
  EASYWI_DB_SUBNET, EASYWI_INTERACTIVE, EASYWI_NON_INTERACTIVE,
  EASYWI_DIAGNOSTICS
USAGE
}

log() {
  if [[ -d /var/log/easywi ]]; then
    echo "${LOG_PREFIX} $*" | tee -a "${LOG_FILE}" >&2
  else
    echo "${LOG_PREFIX} $*" >&2
  fi
}

step() {
  STEP_COUNTER=$((STEP_COUNTER + 1))
  log "Step ${STEP_COUNTER}: $*"
}

fatal() {
  echo "${LOG_PREFIX} ERROR: $*" >&2
  exit 1
}

require_root() {
  if [[ "${EUID}" -ne 0 ]]; then
    fatal "Must be run as root"
  fi
}

command_exists() {
  command -v "$1" >/dev/null 2>&1
}

preflight_tools() {
  local required=(uname awk sed grep cut openssl)
  for tool in "${required[@]}"; do
    command_exists "${tool}" || fatal "Missing required tool: ${tool}"
  done

  if ! command_exists curl && ! command_exists wget; then
    fatal "Missing download tool: curl or wget required"
  fi

  command_exists sha256sum || fatal "Missing sha256sum"
  command_exists systemctl || fatal "systemd is required"
}

is_tty() {
  [[ -t 0 ]]
}

normalize_bool() {
  local value="${1:-}"
  case "${value}" in
    1|true|TRUE|yes|YES|y|Y)
      echo "true"
      ;;
    *)
      echo "false"
      ;;
  esac
}

print_summary() {
  cat <<SUMMARY

================== EasyWI Install Summary ==================
Core API URL:        ${CORE_URL}
Roles:               ${ROLE_LIST:-none}
Agent version:       ${AGENT_VERSION}
Runner version:      ${RUNNER_VERSION}
Channel override:    ${CHANNEL:-none}
Mail hostname:       ${MAIL_HOSTNAME:-auto}
DB bind address:     ${DB_BIND_ADDRESS}
DB allowed subnet:   ${DB_ALLOWED_SUBNET:-none}
Dry run:             ${DRY_RUN:-false}
============================================================
SUMMARY
}

confirm_continue() {
  local answer
  if [[ "$(normalize_bool "${NON_INTERACTIVE}")" == "true" ]]; then
    return
  fi
  if ! is_tty; then
    return
  fi
  read -r -p "Continue with these settings? [y/N]: " answer
  case "${answer}" in
    y|Y|yes|YES)
      ;;
    *)
      fatal "Installation cancelled by user"
      ;;
  esac
}

prompt_value() {
  local var_name="$1"
  local prompt="$2"
  local default="${3:-}"
  local secret="${4:-false}"
  local current="${!var_name:-}"
  local value

  if [[ -n "${current}" ]]; then
    return
  fi

  if [[ "$(normalize_bool "${NON_INTERACTIVE}")" == "true" ]]; then
    return
  fi

  if ! is_tty; then
    return
  fi

  if [[ -n "${default}" ]]; then
    prompt="${prompt} [${default}]"
  fi

  if [[ "${secret}" == "true" ]]; then
    read -r -s -p "${prompt}: " value
    echo
  else
    read -r -p "${prompt}: " value
  fi

  if [[ -z "${value}" ]]; then
    value="${default}"
  fi

  printf -v "${var_name}" '%s' "${value}"
}

validate_role_list() {
  local allowed_roles=("game" "web" "dns" "mail" "db")
  local role
  local ok
  if [[ -z "${ROLE_LIST}" ]]; then
    return 0
  fi
  IFS=',' read -r -a roles <<<"${ROLE_LIST}"
  for role in "${roles[@]}"; do
    role="$(echo "${role}" | xargs)"
    if [[ -z "${role}" ]]; then
      continue
    fi
    ok="false"
    for allowed in "${allowed_roles[@]}"; do
      if [[ "${role}" == "${allowed}" ]]; then
        ok="true"
        break
      fi
    done
    if [[ "${ok}" != "true" ]]; then
      fatal "Unknown role '${role}'. Allowed roles: game, web, dns, mail, db"
    fi
  done
}

validate_required_inputs() {
  if [[ -z "${CORE_URL}" ]]; then
    fatal "Core API URL missing. Provide --core-url or EASYWI_CORE_URL."
  fi
  if [[ -z "${BOOTSTRAP_TOKEN}" ]]; then
    fatal "Bootstrap token missing. Provide --bootstrap-token or EASYWI_BOOTSTRAP_TOKEN."
  fi
  if [[ -n "${MAIL_HOSTNAME}" && "${ROLE_LIST}" != *"mail"* ]]; then
    log "Mail hostname provided without mail role; ignoring."
  fi
  validate_role_list
}

collect_inputs() {
  local tty_active
  tty_active="$(is_tty && echo true || echo false)"
  if [[ "$(normalize_bool "${INTERACTIVE}")" == "false" && -n "${INTERACTIVE}" ]]; then
    return
  fi

  if [[ "$(normalize_bool "${INTERACTIVE}")" == "true" ]]; then
    :
  elif [[ -z "${INTERACTIVE}" && -n "${NON_INTERACTIVE}" ]]; then
    return
  elif [[ -z "${INTERACTIVE}" && -z "${NON_INTERACTIVE}" && "$(normalize_bool "${tty_active}")" == "true" ]]; then
    if [[ -z "${ROLE_LIST}" || -z "${BOOTSTRAP_TOKEN}" || -z "${CORE_URL}" ]]; then
      INTERACTIVE="true"
    fi
  fi

  if [[ "$(normalize_bool "${INTERACTIVE}")" == "true" ]]; then
    prompt_value CORE_URL "Core API URL" "${CORE_URL}"
    API_URL="${CORE_URL}"
    prompt_value BOOTSTRAP_TOKEN "Bootstrap token" "" "true"
    prompt_value ROLE_LIST "Roles (game,web,dns,mail,db)" "${ROLE_LIST}"
    prompt_value CHANNEL "Update channel (stable/beta or tag)" "${CHANNEL}"
    prompt_value MAIL_HOSTNAME "Mail hostname (optional)" "${MAIL_HOSTNAME}"
    prompt_value DB_BIND_ADDRESS "DB bind address" "${DB_BIND_ADDRESS}"
    prompt_value DB_ALLOWED_SUBNET "DB allowed subnet CIDR (optional)" "${DB_ALLOWED_SUBNET}"
    print_summary
    confirm_continue
  fi
}

read_os_release() {
  if [[ -f /etc/os-release ]]; then
    # shellcheck disable=SC1091
    . /etc/os-release
  else
    fatal "/etc/os-release not found"
  fi
}

normalize_arch() {
  local arch
  arch="$(uname -m)"
  case "${arch}" in
    x86_64|amd64)
      echo "amd64"
      ;;
    aarch64|arm64)
      echo "arm64"
      ;;
    *)
      fatal "Unsupported architecture: ${arch}"
      ;;
  esac
}

normalize_os() {
  local id_like="${ID_LIKE:-}"
  case "${ID}" in
    ubuntu|debian)
      echo "debian"
      ;;
    rhel|centos|fedora|almalinux|rocky)
      echo "rhel"
      ;;
    arch)
      echo "arch"
      ;;
    *)
      if [[ "${id_like}" == *"debian"* ]]; then
        echo "debian"
      elif [[ "${id_like}" == *"rhel"* ]] || [[ "${id_like}" == *"fedora"* ]]; then
        echo "rhel"
      else
        fatal "Unsupported distribution: ${ID}"
      fi
      ;;
  esac
}

pkg_update() {
  case "${OS_FAMILY}" in
    debian)
      apt-get update -y
      ;;
    rhel)
      if command_exists dnf; then
        dnf makecache
      else
        yum makecache
      fi
      ;;
    arch)
      pacman -Sy --noconfirm
      ;;
  esac
}

pkg_install() {
  local packages=("$@")
  if [[ ${#packages[@]} -eq 0 ]]; then
    return
  fi
  case "${OS_FAMILY}" in
    debian)
      apt-get install -y "${packages[@]}" >&2
      ;;
    rhel)
      if command_exists dnf; then
        dnf install -y "${packages[@]}" >&2
      else
        yum install -y "${packages[@]}" >&2
      fi
      ;;
    arch)
      pacman -S --noconfirm "${packages[@]}" >&2
      ;;
  esac
}

ensure_dirs() {
  mkdir -p /etc/easywi /var/lib/easywi /var/log/easywi
  chmod 700 /etc/easywi
}

download_file() {
  local url="$1"
  local dest="$2"

  if command_exists curl; then
    if ! curl -fsSL "${url}" -o "${dest}"; then
      fatal "Failed to download ${url}"
    fi
  else
    if ! wget -qO "${dest}" "${url}"; then
      fatal "Failed to download ${url}"
    fi
  fi
}

resolve_latest_release() {
  local repository="$1"
  local version=""
  local payload=""

  if command_exists curl; then
    payload="$(curl -fsSL "https://api.github.com/repos/${repository}/releases/latest" || true)"
  else
    payload="$(wget -qO- "https://api.github.com/repos/${repository}/releases/latest" || true)"
  fi

  if command_exists jq; then
    version="$(echo "${payload}" | jq -r '.tag_name // empty')"
  else
    version="$(echo "${payload}" | sed -n 's/.*"tag_name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p')"
  fi

  if [[ -z "${version}" ]]; then
    fatal "Unable to resolve latest release tag from ${repository}"
  fi

  echo "${version}"
}

download_agent() {
  local arch="$1"
  local version="$2"
  local release_url
  local tmp_dir
  local asset_name
  local checksum_name
  local checksum_line
  local resolved_version="$version"
  tmp_dir="$(mktemp -d)"
  asset_name="easywi-agent-linux-${arch}"
  checksum_name="checksums-agent.txt"

  if [[ "${version}" == "latest" ]]; then
    resolved_version="$(resolve_latest_release "${REPO_OWNER}/${REPO_NAME}")"
    release_url="${GITHUB_BASE_URL}/${resolved_version}"
  else
    release_url="${GITHUB_BASE_URL}/${version}"
  fi

  log "Downloading agent ${resolved_version} (${asset_name})"
  download_file "${release_url}/${asset_name}" "${tmp_dir}/${asset_name}"
  download_file "${release_url}/${checksum_name}" "${tmp_dir}/${checksum_name}"

  checksum_line=$(awk -v asset="${asset_name}" '$2==asset {print}' "${tmp_dir}/${checksum_name}")
  if [[ -z "${checksum_line}" ]]; then
    fatal "Checksum entry not found for ${asset_name}"
  fi

  log "Verifying checksum"
  (cd "${tmp_dir}" && printf '%s\n' "${checksum_line}" | sha256sum -c - >&2)

  echo "${tmp_dir}/${asset_name}|${resolved_version}"
}

download_runner() {
  local arch="$1"
  local version="$2"
  local release_url
  local tmp_dir
  local asset_name
  local checksum_name
  local checksum_line
  tmp_dir="$(mktemp -d)"
  asset_name="easywi-agent-linux-${arch}"
  checksum_name="checksums-agent.txt"

  if [[ "${version}" == "latest" ]]; then
    release_url="https://github.com/${REPO_OWNER}/${REPO_NAME}/releases/latest/download"
  else
    release_url="${GITHUB_BASE_URL}/${version}"
  fi

  log "Downloading runner ${version} (${asset_name})"
  download_file "${release_url}/${asset_name}" "${tmp_dir}/${asset_name}"
  download_file "${release_url}/${checksum_name}" "${tmp_dir}/${checksum_name}"

  checksum_line=$(awk -v asset="${asset_name}" '$2==asset {print}' "${tmp_dir}/${checksum_name}")
  if [[ -z "${checksum_line}" ]]; then
    fatal "Checksum entry not found for ${asset_name}"
  fi

  log "Verifying runner checksum"
  (cd "${tmp_dir}" && printf '%s\n' "${checksum_line}" | sha256sum -c - >&2)

  echo "${tmp_dir}/${asset_name}"
}

install_agent() {
  local agent_path="$1"
  install -m 0755 "${agent_path}" /usr/local/bin/easywi-agent

  cat <<SERVICE >/etc/systemd/system/easywi-agent.service
[Unit]
Description=EasyWI Agent
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart=/usr/local/bin/easywi-agent --config /etc/easywi/agent.conf
Restart=on-failure

[Install]
WantedBy=multi-user.target
SERVICE

  systemctl daemon-reload
  systemctl enable easywi-agent.service
}

install_runner() {
  local runner_path="$1"
  install -m 0755 "${runner_path}" /usr/local/bin/easywi-runner
}

json_value() {
  local json="$1"
  local key="$2"
  if command_exists jq; then
    echo "${json}" | jq -r --arg key "${key}" '.[$key] // empty'
  else
    echo "${json}" | sed -n "s/.*\"${key}\"[[:space:]]*:[[:space:]]*\"\\([^\"]*\\)\".*/\\1/p"
  fi
}

mask_token() {
  local token="$1"
  if [[ -z "${token}" ]]; then
    echo ""
    return
  fi
  echo "${token:0:4}****"
}

bootstrap_register() {
  if [[ -z "${BOOTSTRAP_TOKEN}" ]]; then
    fatal "Bootstrap token missing. Provide --bootstrap-token or EASYWI_BOOTSTRAP_TOKEN."
  fi

  local hostname
  local payload
  local response
  local http_body
  local http_status
  local register_token
  local register_url
  local core_public_url
  local polling_interval
  local agent_id
  local os_name
  local agent_version

  hostname="$(hostname -f 2>/dev/null || hostname)"
  os_name="${ID:-unknown}"
  agent_version="${AGENT_VERSION_RESOLVED:-${AGENT_VERSION}}"
  payload=$(cat <<JSON
{"bootstrap_token":"${BOOTSTRAP_TOKEN}","hostname":"${hostname}","os":"${os_name}","agent_version":"${agent_version}"}
JSON
)

  if [[ "$(normalize_bool "${DRY_RUN}")" == "true" ]]; then
    log "Dry run enabled. Planned API calls:"
    log "POST ${API_URL}/api/v1/agent/bootstrap (bootstrap_token=$(mask_token "${BOOTSTRAP_TOKEN}"), hostname=${hostname}, os=${os_name}, agent_version=${agent_version})"
    log "POST /api/v1/agent/register (register_token=<from bootstrap>, signed request)"
    return
  fi

  log "Bootstrapping agent with API ${API_URL}"
  response=$(curl -sS -w $'\n%{http_code}' -X POST "${API_URL}/api/v1/agent/bootstrap" \
    -H "Content-Type: application/json" \
    -d "${payload}" || true)

  if [[ -z "${response}" ]]; then
    fatal "Bootstrap request failed. Check network connectivity."
  fi

  http_body="${response%$'\n'*}"
  http_status="${response##*$'\n'}"

  case "${http_status}" in
    404)
      fatal "Bootstrap endpoint not found. Update the Core API to include /api/v1/agent/bootstrap."
      ;;
    401|403)
      fatal "Bootstrap token invalid or expired."
      ;;
  esac

  if [[ "${http_status}" -ge 400 ]]; then
    fatal "Bootstrap request failed with status ${http_status}: ${http_body}"
  fi

  register_token="$(json_value "${http_body}" "register_token")"
  register_url="$(json_value "${http_body}" "register_url")"
  core_public_url="$(json_value "${http_body}" "core_public_url")"
  polling_interval="$(json_value "${http_body}" "polling_interval")"
  agent_id="$(json_value "${http_body}" "agent_id")"

  if [[ -z "${register_token}" ]]; then
    fatal "Failed to parse registration token from bootstrap response."
  fi

  if [[ -z "${agent_id}" ]]; then
    fatal "Failed to parse agent_id from bootstrap response."
  fi

  if [[ -z "${register_url}" ]]; then
    register_url="${API_URL}/api/v1/agent/register"
  fi

  if [[ -n "${core_public_url}" ]]; then
    API_URL="${core_public_url}"
  fi

  local register_payload
  local register_response
  local register_status
  local register_body
  local timestamp
  local nonce
  local body_hash
  local signature_payload
  local signature
  local agent_secret

  register_payload=$(cat <<JSON
{"agent_id":"${agent_id}","name":"${hostname}","register_token":"${register_token}"}
JSON
)

  body_hash="$(printf '%s' "${register_payload}" | sha256sum | awk '{print $1}')"
  timestamp="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
  nonce="$(openssl rand -hex 16)"
  signature_payload="${agent_id}\nPOST\n/api/v1/agent/register\n${body_hash}\n${timestamp}\n${nonce}"
  signature="$(printf '%s' "${signature_payload}" | openssl dgst -sha256 -hmac "${register_token}" | awk '{print $2}')"

  log "Registering agent with API ${register_url}"
  register_response=$(curl -sS -w $'\n%{http_code}' -X POST "${register_url}" \
    -H "Content-Type: application/json" \
    -H "X-Agent-ID: ${agent_id}" \
    -H "X-Timestamp: ${timestamp}" \
    -H "X-Nonce: ${nonce}" \
    -H "X-Signature: ${signature}" \
    -d "${register_payload}" || true)

  if [[ -z "${register_response}" ]]; then
    fatal "Register request failed. Check network connectivity."
  fi

  register_body="${register_response%$'\n'*}"
  register_status="${register_response##*$'\n'}"

  case "${register_status}" in
    404)
      fatal "Register endpoint not found. Update the Core API to include /api/v1/agent/register."
      ;;
    401|403)
      fatal "Registration token invalid or expired."
      ;;
  esac

  if [[ "${register_status}" -ge 400 ]]; then
    fatal "Register request failed with status ${register_status}: ${register_body}"
  fi

  agent_secret="$(json_value "${register_body}" "secret")"

  if [[ -z "${agent_secret}" ]]; then
    fatal "Failed to parse agent secret from register response."
  fi

  cat <<CONF >/etc/easywi/agent.conf
agent_id=${agent_id}
secret=${agent_secret}
api_url=${API_URL}
poll_interval=${polling_interval:-30}s
version=${agent_version}
CONF

  chmod 600 /etc/easywi/agent.conf
}

apply_security_baseline() {
  log "Applying security baseline"
  pkg_update
  case "${OS_FAMILY}" in
    debian)
      pkg_install openssh-server ufw fail2ban
      ;;
    rhel)
      pkg_install openssh-server firewalld fail2ban
      ;;
    arch)
      pkg_install openssh ufw fail2ban
      ;;
  esac

  if [[ -f /etc/ssh/sshd_config ]]; then
    mkdir -p /etc/ssh/sshd_config.d
    cat <<'SSHD' >/etc/ssh/sshd_config.d/99-easywi-security.conf
Port 22
Port 2222
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
Subsystem sftp internal-sftp

Match LocalPort 22
  PasswordAuthentication no
  KbdInteractiveAuthentication no
  PubkeyAuthentication yes

Match LocalPort 2222
  ChrootDirectory /var/lib/easywi/sftp/%u
  ForceCommand internal-sftp
  AllowTcpForwarding no
  X11Forwarding no
  PasswordAuthentication yes
  PubkeyAuthentication yes
SSHD

    groupadd -f easywi-sftp
    mkdir -p /var/lib/easywi/sftp
    chmod 755 /var/lib/easywi/sftp
    chown root:root /var/lib/easywi/sftp

    if systemctl list-unit-files --type=service | awk '{print $1}' | grep -qx "sshd.service"; then
      systemctl restart sshd
    elif systemctl list-unit-files --type=service | awk '{print $1}' | grep -qx "ssh.service"; then
      systemctl restart ssh
    fi
  fi

  if command_exists ufw; then
    ufw default deny incoming
    ufw allow 22/tcp
    ufw allow 2222/tcp
    ufw --force enable
  elif command_exists firewall-cmd; then
    systemctl enable --now firewalld
    firewall-cmd --permanent --zone=public --set-target=DROP
    firewall-cmd --permanent --add-service=ssh
    firewall-cmd --permanent --add-port=2222/tcp
    firewall-cmd --reload
  fi

  mkdir -p /etc/fail2ban/jail.d
  cat <<'F2B' >/etc/fail2ban/jail.d/easywi.conf
[sshd]
enabled = true
port = 22
bantime = 1h
findtime = 10m
maxretry = 5

[sshd-2222]
enabled = true
filter = sshd
port = 2222
bantime = 1h
findtime = 10m
maxretry = 5
F2B

  systemctl enable --now fail2ban
}

role_packages() {
  local role="$1"
  case "${role}" in
    game)
      case "${OS_FAMILY}" in
        debian)
          echo "ca-certificates curl tar xz-utils unzip tmux screen lib32gcc-s1 lib32stdc++6 libc6-i386"
          ;;
        rhel)
          echo "ca-certificates curl tar xz unzip tmux screen glibc.i686 libstdc++.i686"
          ;;
        arch)
          echo "ca-certificates curl tar xz unzip tmux screen lib32-glibc lib32-gcc-libs"
          ;;
      esac
      ;;
    dns)
      case "${OS_FAMILY}" in
        debian)
          echo "pdns-server pdns-backend-bind"
          ;;
        arch)
          echo "pdns"
          ;;
        rhel)
          echo "pdns pdns-backend-bind"
          ;;
      esac
      ;;
    mail)
      case "${OS_FAMILY}" in
        debian)
          echo "postfix $(mail_dovecot_packages)"
          ;;
        rhel)
          echo "postfix dovecot"
          ;;
        arch)
          echo "postfix dovecot"
          ;;
      esac
      ;;
    db)
      case "${OS_FAMILY}" in
        debian)
          echo "mariadb-server postgresql"
          ;;
        rhel)
          echo "mariadb-server postgresql-server"
          ;;
        arch)
          echo "mariadb postgresql"
          ;;
      esac
      ;;
  esac
}

mail_dovecot_packages() {
  if command_exists apt-cache; then
    if apt-cache show dovecot-core >/dev/null 2>&1; then
      echo "dovecot-core dovecot-imapd"
      return
    fi
    if apt-cache show dovecot >/dev/null 2>&1; then
      echo "dovecot"
      return
    fi
  fi

  echo "dovecot-core dovecot-imapd"
}

setup_game_dirs() {
  mkdir -p /etc/easywi/game
  mkdir -p /var/lib/easywi/game/{steamcmd,runner,sniper,servers}
  mkdir -p /var/log/easywi/game
  chmod 750 /var/lib/easywi/game
}

install_steamcmd() {
  local steamcmd_dir="/var/lib/easywi/game/steamcmd"
  local archive="${steamcmd_dir}/steamcmd_linux.tar.gz"
  if command_exists steamcmd; then
    log "steamcmd already installed"
    return
  fi

  log "Downloading steamcmd"
  download_file "https://steamcdn-a.akamaihd.net/client/installer/steamcmd_linux.tar.gz" "${archive}"
  tar -xzf "${archive}" -C "${steamcmd_dir}"
  rm -f "${archive}"

  if [[ -x "${steamcmd_dir}/steamcmd.sh" ]]; then
    ln -sf "${steamcmd_dir}/steamcmd.sh" /usr/local/bin/steamcmd
  fi
}

install_game_role() {
  local runner_path
  setup_game_dirs
  install_steamcmd
  runner_path="$(download_runner "${ARCH}" "${RUNNER_VERSION}")"
  install_runner "${runner_path}"
}

configure_dns_firewall() {
  if command_exists ufw; then
    ufw allow 53/tcp
    ufw allow 53/udp
  elif command_exists firewall-cmd; then
    firewall-cmd --permanent --add-service=dns
    firewall-cmd --reload
  fi
}

configure_powerdns() {
  local api_key_file="/etc/easywi/pdns-api-key"
  local api_key
  local conf_dir=""
  local conf_file=""
  local bind_conf="/etc/powerdns/bindbackend.conf"

  if [[ ! -f "${api_key_file}" ]]; then
    api_key="$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 48)"
    printf '%s\n' "${api_key}" >"${api_key_file}"
    chmod 600 "${api_key_file}"
  else
    api_key="$(cat "${api_key_file}")"
  fi

  if [[ -d /etc/powerdns/pdns.conf.d ]]; then
    conf_dir="/etc/powerdns/pdns.conf.d"
  elif [[ -d /etc/pdns/pdns.conf.d ]]; then
    conf_dir="/etc/pdns/pdns.conf.d"
  elif [[ -f /etc/pdns/pdns.conf ]]; then
    conf_file="/etc/pdns/pdns.conf"
  else
    conf_file="/etc/powerdns/pdns.conf"
  fi

  if [[ -n "${conf_dir}" ]]; then
    mkdir -p "${conf_dir}"
    cat <<CONF >"${conf_dir}/easywi.conf"
api=yes
api-key=${api_key}
webserver=yes
webserver-address=127.0.0.1
webserver-port=8081
webserver-allow-from=127.0.0.1,::1
launch=bind
bind-config=${bind_conf}
CONF
  else
    cat <<CONF >>"${conf_file}"

# EasyWI PowerDNS settings
api=yes
api-key=${api_key}
webserver=yes
webserver-address=127.0.0.1
webserver-port=8081
webserver-allow-from=127.0.0.1,::1
launch=bind
bind-config=${bind_conf}
CONF
  fi

  if [[ ! -f "${bind_conf}" ]]; then
    mkdir -p "$(dirname "${bind_conf}")"
    cat <<'CONF' >"${bind_conf}"
// EasyWI PowerDNS bind backend configuration.
// Add named.conf includes for zones managed by EasyWI.
CONF
  fi
}

install_php_fpm() {
  case "${OS_FAMILY}" in
    debian)
      if pkg_install php8.4-fpm; then
        echo "php8.4-fpm"
        return
      fi
      if pkg_install php8.5-fpm; then
        echo "php8.5-fpm"
        return
      fi
      ;;
    rhel)
      if pkg_install php84-php-fpm; then
        echo "php84-php-fpm"
        return
      fi
      if pkg_install php85-php-fpm; then
        echo "php85-php-fpm"
        return
      fi
      ;;
    arch)
      pkg_install php-fpm
      echo "php-fpm"
      return
      ;;
  esac

  fatal "Unable to install PHP-FPM 8.4 or 8.5 for the web role"
}

configure_web_firewall() {
  if command_exists ufw; then
    ufw allow 80/tcp
    ufw allow 443/tcp
  elif command_exists firewall-cmd; then
    firewall-cmd --permanent --add-service=http
    firewall-cmd --permanent --add-service=https
    firewall-cmd --reload
  fi
}

install_web_role() {
  local php_service
  pkg_install nginx certbot
  php_service="$(install_php_fpm)"

  systemctl enable --now nginx
  systemctl enable --now "${php_service}"

  configure_web_firewall
}

configure_mail_firewall() {
  if command_exists ufw; then
    ufw allow 25/tcp
    ufw allow 587/tcp
    ufw allow 993/tcp
  elif command_exists firewall-cmd; then
    firewall-cmd --permanent --add-service=smtp
    firewall-cmd --permanent --add-service=submission
    firewall-cmd --permanent --add-service=imaps
    firewall-cmd --reload
  fi
}

configure_mail_hostname() {
  local hostname
  local domain
  hostname="${MAIL_HOSTNAME:-}"
  if [[ -z "${hostname}" ]]; then
    hostname="$(hostname -f 2>/dev/null || hostname)"
  fi

  if command_exists postconf; then
    postconf -e "myhostname = ${hostname}"
    domain="${hostname#*.}"
    if [[ "${domain}" != "${hostname}" ]]; then
      postconf -e "mydomain = ${domain}"
      postconf -e "mydestination = ${hostname}, localhost.${domain}, localhost"
    else
      postconf -e "mydestination = ${hostname}, localhost"
    fi
  fi

  if [[ -d /etc/dovecot/conf.d ]]; then
    cat <<CONF >/etc/dovecot/conf.d/99-easywi.conf
hostname = ${hostname}
protocols = imap
CONF
  fi
}

configure_mail_fail2ban() {
  local logpath="/var/log/mail.log"
  if [[ "${OS_FAMILY}" == "rhel" ]]; then
    logpath="/var/log/maillog"
  fi
  mkdir -p /etc/fail2ban/jail.d
  cat <<F2B >/etc/fail2ban/jail.d/easywi-mail.conf
[postfix]
enabled = true
port = smtp,submission
filter = postfix
logpath = ${logpath}
bantime = 1h
findtime = 10m
maxretry = 5

[postfix-sasl]
enabled = true
port = smtp,submission
filter = postfix-sasl
logpath = ${logpath}
bantime = 1h
findtime = 10m
maxretry = 5

[dovecot]
enabled = true
port = imap,imaps
logpath = ${logpath}
bantime = 1h
findtime = 10m
maxretry = 5
F2B

  systemctl reload fail2ban || systemctl restart fail2ban
}

install_mail_role() {
  configure_mail_hostname
  configure_mail_firewall
  configure_mail_fail2ban
  systemctl enable --now postfix
  systemctl enable --now dovecot
}

write_config_block() {
  local file="$1"
  local marker="$2"
  local content="$3"
  if [[ -f "${file}" ]]; then
    sed -i "/# ${marker} start/,/# ${marker} end/d" "${file}"
  else
    mkdir -p "$(dirname "${file}")"
    touch "${file}"
  fi

  cat <<EOF >>"${file}"
# ${marker} start
${content}
# ${marker} end
EOF
}

configure_db_firewall() {
  if [[ -z "${DB_ALLOWED_SUBNET}" ]]; then
    log "DB subnet not provided; skipping firewall allow rule for databases"
    return
  fi
  if command_exists ufw; then
    ufw allow from "${DB_ALLOWED_SUBNET}" to any port 3306 proto tcp
    ufw allow from "${DB_ALLOWED_SUBNET}" to any port 5432 proto tcp
  elif command_exists firewall-cmd; then
    firewall-cmd --permanent --add-rich-rule="rule family=ipv4 source address=${DB_ALLOWED_SUBNET} port port=3306 protocol=tcp accept"
    firewall-cmd --permanent --add-rich-rule="rule family=ipv4 source address=${DB_ALLOWED_SUBNET} port port=5432 protocol=tcp accept"
    firewall-cmd --reload
  fi
}

configure_mariadb() {
  local conf_file=""
  if [[ "${OS_FAMILY}" == "debian" && -d /etc/mysql/mariadb.conf.d ]]; then
    conf_file="/etc/mysql/mariadb.conf.d/99-easywi.cnf"
  else
    conf_file="/etc/my.cnf.d/easywi.cnf"
  fi

  write_config_block "${conf_file}" "easywi-mariadb" "[mysqld]
bind-address = ${DB_BIND_ADDRESS}
skip-name-resolve = 1"
}

find_postgres_conf() {
  local conf
  for conf in /etc/postgresql/*/main/postgresql.conf /var/lib/pgsql/data/postgresql.conf /var/lib/postgresql/data/postgresql.conf /var/lib/postgres/data/postgresql.conf; do
    if [[ -f "${conf}" ]]; then
      echo "${conf}"
      return 0
    fi
  done
  return 1
}

find_postgres_hba() {
  local conf
  for conf in /etc/postgresql/*/main/pg_hba.conf /var/lib/pgsql/data/pg_hba.conf /var/lib/postgresql/data/pg_hba.conf /var/lib/postgres/data/pg_hba.conf; do
    if [[ -f "${conf}" ]]; then
      echo "${conf}"
      return 0
    fi
  done
  return 1
}

init_postgres_if_needed() {
  if [[ "${OS_FAMILY}" == "rhel" && -x "$(command -v postgresql-setup)" ]]; then
    postgresql-setup --initdb || true
  elif [[ "${OS_FAMILY}" == "arch" && ! -f /var/lib/postgres/data/PG_VERSION && -x "$(command -v initdb)" ]]; then
    su - postgres -c "initdb -D /var/lib/postgres/data"
  fi
}

configure_postgres() {
  local conf_file
  local hba_file
  local hba_subnet

  conf_file="$(find_postgres_conf || true)"
  hba_file="$(find_postgres_hba || true)"

  if [[ -z "${conf_file}" || -z "${hba_file}" ]]; then
    log "PostgreSQL config files not found; skipping postgres configuration"
    return
  fi

  write_config_block "${conf_file}" "easywi-postgres" "listen_addresses = '${DB_BIND_ADDRESS}'"

  if [[ -n "${DB_ALLOWED_SUBNET}" ]]; then
    hba_subnet="${DB_ALLOWED_SUBNET}"
  else
    hba_subnet="127.0.0.1/32"
  fi

  write_config_block "${hba_file}" "easywi-postgres" "host all all ${hba_subnet} md5"
}

install_db_role() {
  init_postgres_if_needed
  configure_mariadb
  configure_postgres
  configure_db_firewall
  systemctl enable --now mariadb || systemctl enable --now mysql || true
  systemctl enable --now postgresql || true
  systemctl restart mariadb || systemctl restart mysql || true
  systemctl restart postgresql || true
}

install_dns_role() {
  configure_powerdns
  configure_dns_firewall
  systemctl enable --now pdns || systemctl enable --now powerdns || true
}

role_enable_service() {
  local role="$1"
  case "${role}" in
    game)
      install_game_role
      ;;
    web)
      install_web_role
      ;;
    dns)
      install_dns_role
      ;;
    mail)
      install_mail_role
      ;;
    db)
      install_db_role
      ;;
  esac
}

apply_roles() {
  local role
  local packages

  if [[ -z "${ROLE_LIST}" ]]; then
    log "No roles provided. Skipping role modules."
    return
  fi

  IFS=',' read -r -a roles <<<"${ROLE_LIST}"
  for role in "${roles[@]}"; do
    role="$(echo "${role}" | xargs)"
    if [[ -z "${role}" ]]; then
      continue
    fi
    log "Configuring role: ${role}"
    if [[ "${role}" != "web" ]]; then
      read -r -a packages <<<"$(role_packages "${role}")"
      pkg_install "${packages[@]}"
    fi
    mkdir -p /etc/easywi/roles.d
    echo "role=${role}" > "/etc/easywi/roles.d/${role}.conf"
    if [[ "${role}" == "mail" && -n "${MAIL_HOSTNAME}" ]]; then
      cat <<CONF >/etc/easywi/mail.conf
MAIL_HOSTNAME=${MAIL_HOSTNAME}
CONF
      chmod 600 /etc/easywi/mail.conf
    fi
    role_enable_service "${role}"
  done
}

collect_diagnostics() {
  local timestamp
  local target_dir
  local archive_path

  timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
  target_dir="$(mktemp -d)"
  archive_path="/var/log/easywi/diagnostics-${timestamp}.tar.gz"

  mkdir -p "${target_dir}/logs" "${target_dir}/config"
  cp -a /var/log/easywi "${target_dir}/logs" 2>/dev/null || true
  cp -a /etc/easywi "${target_dir}/config" 2>/dev/null || true

  {
    echo "Timestamp: ${timestamp}"
    uname -a
    echo
    echo "[OS Release]"
    cat /etc/os-release 2>/dev/null || true
    echo
    echo "[Versions]"
    systemctl --version 2>/dev/null || true
    /usr/local/bin/easywi-agent --version 2>/dev/null || true
    /usr/local/bin/easywi-runner --version 2>/dev/null || true
    nginx -v 2>&1 || true
    php -v 2>/dev/null | head -n 2 || true
    pdns_server --version 2>/dev/null || true
    postfix -v 2>/dev/null || true
    dovecot --version 2>/dev/null || true
    mariadbd --version 2>/dev/null || true
    mysqld --version 2>/dev/null || true
    psql --version 2>/dev/null || true
    echo
    echo "[Services]"
    systemctl list-units --type=service --state=running 2>/dev/null || true
  } > "${target_dir}/versions.txt"

  if command_exists ss; then
    ss -tulpn > "${target_dir}/ports.txt" 2>/dev/null || true
  elif command_exists netstat; then
    netstat -tulpn > "${target_dir}/ports.txt" 2>/dev/null || true
  fi

  tar -czf "${archive_path}" -C "${target_dir}" .
  rm -rf "${target_dir}"
  log "Diagnostics bundle written to ${archive_path}"
}

parse_args() {
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --roles)
        ROLE_LIST="$2"
        shift 2
        ;;
      --bootstrap-token)
        BOOTSTRAP_TOKEN="$2"
        shift 2
        ;;
      --core-url)
        CORE_URL="$2"
        API_URL="${CORE_URL}"
        shift 2
        ;;
      --api-url)
        API_URL="$2"
        CORE_URL="$2"
        shift 2
        ;;
      --agent-version)
        AGENT_VERSION="$2"
        shift 2
        ;;
      --runner-version)
        RUNNER_VERSION="$2"
        shift 2
        ;;
      --channel)
        CHANNEL="$2"
        shift 2
        ;;
      --mail-hostname)
        MAIL_HOSTNAME="$2"
        shift 2
        ;;
      --db-bind-address)
        DB_BIND_ADDRESS="$2"
        shift 2
        ;;
      --db-subnet)
        DB_ALLOWED_SUBNET="$2"
        shift 2
        ;;
      --repo-owner)
        REPO_OWNER="$2"
        shift 2
        ;;
      --repo-name)
        REPO_NAME="$2"
        shift 2
        ;;
      --interactive)
        INTERACTIVE="true"
        shift
        ;;
      --non-interactive)
        NON_INTERACTIVE="true"
        shift
        ;;
      --diagnostics)
        DIAGNOSTICS_MODE="$2"
        shift 2
        ;;
      --dry-run)
        DRY_RUN="true"
        shift
        ;;
      -h|--help)
        usage
        exit 0
        ;;
      *)
        fatal "Unknown argument: $1"
        ;;
    esac
  done
}

main() {
  step "Parse arguments"
  parse_args "$@"
  step "Validate permissions"
  require_root
  step "Check system prerequisites"
  preflight_tools
  step "Detect OS"
  read_os_release
  step "Collect input"
  collect_inputs
  step "Validate input"
  validate_required_inputs

  if [[ "$(normalize_bool "${DRY_RUN}")" == "true" ]]; then
    step "Dry run bootstrap"
    bootstrap_register
    log "Dry run complete."
    return
  fi

  if [[ -n "${CHANNEL}" && "${AGENT_VERSION}" == "latest" ]]; then
    AGENT_VERSION="${CHANNEL}"
  fi
  if [[ -n "${CHANNEL}" && "${RUNNER_VERSION}" == "latest" ]]; then
    RUNNER_VERSION="${CHANNEL}"
  fi

  OS_FAMILY="$(normalize_os)"
  ARCH="$(normalize_arch)"

  log "Detected OS: ${ID} (${OS_FAMILY}), Arch: ${ARCH}"

  step "Prepare directories"
  ensure_dirs

  step "Download and install EasyWI agent"
  IFS='|' read -r AGENT_PATH AGENT_VERSION_RESOLVED < <(download_agent "${ARCH}" "${AGENT_VERSION}")
  if [[ -z "${AGENT_VERSION_RESOLVED}" ]]; then
    AGENT_VERSION_RESOLVED="${AGENT_VERSION}"
  fi
  install_agent "${AGENT_PATH}"

  step "Apply security baseline"
  apply_security_baseline
  step "Apply role-specific packages and services"
  apply_roles

  step "Register agent with Core API"
  bootstrap_register

  step "Start EasyWI agent service"
  systemctl start easywi-agent.service

  log "Installation complete."

  local tty_active
  tty_active="$(is_tty && echo true || echo false)"
  if [[ "${DIAGNOSTICS_MODE}" == "always" || ("${DIAGNOSTICS_MODE}" == "auto" && "$(normalize_bool "${tty_active}")" == "true") ]]; then
    step "Collect diagnostics bundle"
    collect_diagnostics
  else
    log "Diagnostics bundle skipped (mode: ${DIAGNOSTICS_MODE})."
  fi
}

main "$@"
