#!/usr/bin/env bash
set -euo pipefail

VERSION="0.1.0"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

LOG_PREFIX="[easywi-installer-menu]"
STEP_COUNTER=0
DEFAULT_PHP_VERSION="8.4"

log() {
  echo "${LOG_PREFIX} $*" >&2
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

is_tty() {
  [[ -t 0 ]]
}

require_command() {
  local cmd="$1"
  if ! command -v "${cmd}" >/dev/null 2>&1; then
    fatal "Benötigtes Kommando fehlt: ${cmd}"
  fi
}

detect_package_manager() {
  if command -v apt-get >/dev/null 2>&1; then
    echo "apt"
    return
  fi
  fatal "Dieses Skript unterstützt derzeit nur apt-basierte Distributionen (Debian/Ubuntu)."
}

install_packages() {
  local manager="$1"
  shift
  local packages=("$@")

  step "Installiere Systempakete."
  log "Folgende Pakete werden installiert:"
  printf '%s\n' "${packages[@]}" | sed 's/^/  - /'

  case "${manager}" in
    apt)
      DEBIAN_FRONTEND=noninteractive apt-get update -y
      DEBIAN_FRONTEND=noninteractive apt-get install -y "${packages[@]}"
      ;;
    *)
      fatal "Unbekannter Paketmanager: ${manager}"
      ;;
  esac
}

ensure_service_enabled() {
  local service="$1"
  if systemctl list-unit-files "${service}" >/dev/null 2>&1; then
    systemctl enable --now "${service}"
  fi
}

prompt_value() {
  local var_name="$1"
  local prompt="$2"
  local default="${3:-}"
  local current="${!var_name:-}"
  local value

  if [[ -n "${current}" ]]; then
    return
  fi

  if ! is_tty; then
    return
  fi

  if [[ -n "${default}" ]]; then
    prompt="${prompt} [${default}]"
  fi

  read -r -p "${prompt}: " value
  if [[ -z "${value}" ]]; then
    value="${default}"
  fi

  if [[ -n "${value}" ]]; then
    printf -v "${var_name}" '%s' "${value}"
  fi
}

db_system_menu() {
  cat <<'MENU'

Datenbanksystem:
  1) MariaDB
  2) MySQL
  3) PostgreSQL
MENU

  local choice
  read -r -p "Bitte wählen Sie [1-3]: " choice
  case "${choice}" in
    1) echo "mariadb";;
    2) echo "mysql";;
    3) echo "postgresql";;
    *) echo "mariadb";;
  esac
}

ensure_system_user() {
  local username="$1"
  local home_dir="$2"
  if id -u "${username}" >/dev/null 2>&1; then
    log "System-User ${username} existiert bereits."
    return
  fi

  step "Lege den System-User ${username} an."
  log "Der System-User wird als Dienstkonto für EasyWI verwendet."
  useradd --system --create-home --home-dir "${home_dir}" --shell /usr/sbin/nologin "${username}"
}

setup_database_mysql() {
  local db_name="$1"
  local db_user="$2"
  local db_password="$3"
  local db_root_password="$4"

  step "Richte MySQL/MariaDB-Datenbank ein."
  log "Datenbank: ${db_name}"
  log "DB-User: ${db_user}"
  log "Die folgenden SQL-Kommandos werden ausgeführt:"
  log "  - CREATE DATABASE ${db_name}"
  log "  - CREATE USER ${db_user}"
  log "  - GRANT ALL PRIVILEGES ON ${db_name}.* TO ${db_user}"

  local mysql_cmd=(mysql -u root)
  if [[ -n "${db_root_password}" ]]; then
    mysql_cmd+=(--password="${db_root_password}")
  fi

  "${mysql_cmd[@]}" <<SQL
CREATE DATABASE IF NOT EXISTS \`${db_name}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${db_user}'@'%' IDENTIFIED BY '${db_password}';
GRANT ALL PRIVILEGES ON \`${db_name}\`.* TO '${db_user}'@'%';
FLUSH PRIVILEGES;
SQL
}

setup_database_postgresql() {
  local db_name="$1"
  local db_user="$2"
  local db_password="$3"

  step "Richte PostgreSQL-Datenbank ein."
  log "Datenbank: ${db_name}"
  log "DB-User: ${db_user}"
  log "Die folgenden SQL-Kommandos werden ausgeführt:"
  log "  - CREATE USER ${db_user}"
  log "  - CREATE DATABASE ${db_name} OWNER ${db_user}"

  runuser -u postgres -- psql <<SQL
DO \$\$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = '${db_user}') THEN
    CREATE ROLE ${db_user} LOGIN PASSWORD '${db_password}';
  END IF;
  IF NOT EXISTS (SELECT FROM pg_database WHERE datname = '${db_name}') THEN
    CREATE DATABASE ${db_name} OWNER ${db_user};
  END IF;
END
\$\$;
SQL
}

download_release_asset() {
  local asset="$1"
  local destination="$2"
  local version="$3"
  local base_url="https://github.com/Matlord93/Easy-Wi-NextGen/releases/latest/download"

  if [[ -n "${version}" && "${version}" != "latest" ]]; then
    base_url="https://github.com/Matlord93/Easy-Wi-NextGen/releases/download/${version}"
  fi

  step "Lade ${asset} herunter."
  log "Download-Quelle: ${base_url}/${asset}"
  curl -fsSL "${base_url}/${asset}" -o "${destination}"
  chmod +x "${destination}"
}

install_agent_binaries_only() {
  local agent_version="$1"

  step "Installiere Agent-Binaries (ohne Registrierung)."
  log "Es werden nur die Binaries installiert, kein Token notwendig."

  download_release_asset "easywi-agent-linux-amd64" "/usr/local/bin/easywi-agent" "${agent_version}"
  download_release_asset "easywi-filesvc-linux-amd64" "/usr/local/bin/easywi-filesvc" "${agent_version}"

  mkdir -p /etc/easywi
  if [[ ! -f /etc/easywi/agent.conf ]]; then
    cat <<'CONF' >/etc/easywi/agent.conf
# Beispiel-Konfiguration für die spätere Registrierung
# API_URL=https://panel.example.com
# AGENT_TOKEN=<TOKEN>
CONF
    chmod 600 /etc/easywi/agent.conf
  fi

  if [[ ! -f /etc/easywi/filesvc.conf ]]; then
    cat <<'CONF' >/etc/easywi/filesvc.conf
# Beispiel-Konfiguration für File Service (nach Registrierung ergänzen)
# agent_id=<AGENT_ID>
# secret=<SECRET>
# tls_cert=/etc/ssl/certs/easywi-filesvc.crt
# tls_key=/etc/ssl/private/easywi-filesvc.key
# tls_ca=/etc/ssl/certs/ca-certificates.crt
# listen_addr=:8444
# base_dir=/home
CONF
    chmod 600 /etc/easywi/filesvc.conf
  fi

  cat <<'INFO'
Die Binaries wurden installiert. Die Konfiguration und Dienste können
nach der Registrierung im Webinterface ergänzt werden:
  - /etc/easywi/agent.conf
  - /etc/easywi/filesvc.conf
INFO
}

print_intro() {
  cat <<INTRO
EasyWI Installer Menü (Linux) v${VERSION}

Dieses Skript führt Sie Schritt für Schritt durch die Installation.
Es erklärt vor jeder Aktion, was passiert, und ruft die jeweiligen
Spezial-Installer auf.
INTRO
}

panel_mode_menu() {
  cat <<'MENU'

Panel-Installationsmodus:
  1) Standalone (eigene Webserver-Konfiguration)
  2) Plesk
  3) aaPanel
  4) Abbrechen
MENU

  local choice
  read -r -p "Bitte wählen Sie den Modus [1-4]: " choice
  case "${choice}" in
    1) echo "standalone";;
    2) echo "plesk";;
    3) echo "aapanel";;
    *) echo "";;
  esac
}

run_panel_install() {
  local mode
  mode="$(panel_mode_menu)"
  if [[ -z "${mode}" ]]; then
    fatal "Panel-Installation abgebrochen."
  fi

  local install_dir="${EASYWI_INSTALL_DIR:-/var/www/easywi}"
  local repo_url="${EASYWI_REPO_URL:-}"
  local repo_ref="${EASYWI_REPO_REF:-Beta}"
  local db_driver="${EASYWI_DB_DRIVER:-mysql}"
  local db_system="${EASYWI_DB_SYSTEM:-}"
  local db_root_password="${EASYWI_DB_ROOT_PASSWORD:-}"
  local db_host="${EASYWI_DB_HOST:-127.0.0.1}"
  local db_port="${EASYWI_DB_PORT:-}"
  local db_name="${EASYWI_DB_NAME:-easywi}"
  local db_user="${EASYWI_DB_USER:-easywi}"
  local db_password="${EASYWI_DB_PASSWORD:-}"
  local php_version="${EASYWI_PHP_VERSION:-${DEFAULT_PHP_VERSION}}"
  local web_hostname="${EASYWI_WEB_HOSTNAME:-_}"
  local web_user="${EASYWI_WEB_USER:-}"
  local system_user="${EASYWI_SYSTEM_USER:-easywi}"
  local web_server="nginx"
  local app_secret="${EASYWI_APP_SECRET:-}"
  local app_encryption_keys="${EASYWI_APP_ENCRYPTION_KEYS:-}"
  local agent_registration_token="${EASYWI_AGENT_REGISTRATION_TOKEN:-}"
  local app_github_token="${EASYWI_APP_GITHUB_TOKEN:-}"
  local run_migrations="${EASYWI_RUN_MIGRATIONS:-true}"

  echo
  echo "Panel-Setup: Wir laden den Quellcode, schreiben die .env.local,"
  echo "konfigurieren den Webserver (falls Standalone), und führen optional"
  echo "die Datenbankmigrationen aus."

  prompt_value repo_url "Git-Repository URL" "https://github.com/Matlord93/Easy-Wi-NextGen.git"
  prompt_value install_dir "Installationsverzeichnis" "${install_dir}"
  prompt_value repo_ref "Git-Branch/Tag" "${repo_ref}"
  if [[ -z "${db_system}" ]]; then
    if is_tty; then
      db_system="$(db_system_menu)"
    else
      db_system="mariadb"
    fi
  fi
  case "${db_system}" in
    mariadb|mysql)
      db_driver="mysql"
      ;;
    postgresql)
      db_driver="pgsql"
      ;;
    *)
      fatal "Unbekanntes DB-System: ${db_system}"
      ;;
  esac

  prompt_value system_user "System-User (Linux) für EasyWI" "${system_user}"
  prompt_value db_root_password "DB-Root-Passwort (leer = socket auth)" "${db_root_password}"
  prompt_value db_host "DB-Host" "${db_host}"
  prompt_value db_port "DB-Port (leer = Standard)" "${db_port}"
  prompt_value db_name "DB-Name" "${db_name}"
  prompt_value db_user "DB-User" "${db_user}"
  prompt_value db_password "DB-Passwort" "${db_password}"
  prompt_value app_secret "APP_SECRET (leer = automatisch)" "${app_secret}"
  prompt_value app_encryption_keys "APP_ENCRYPTION_KEYS (optional)" "${app_encryption_keys}"
  prompt_value agent_registration_token "AGENT_REGISTRATION_TOKEN (optional)" "${agent_registration_token}"
  prompt_value app_github_token "GitHub Token (optional)" "${app_github_token}"
  prompt_value run_migrations "Migrationen ausführen? (true/false)" "${run_migrations}"

  if [[ "${mode}" == "standalone" ]]; then
    prompt_value php_version "PHP-Version (8.4)" "${php_version}"
    prompt_value web_hostname "Servername" "${web_hostname}"
    prompt_value web_user "Web-User" "${web_user}"
  fi

  if [[ -z "${web_user}" ]]; then
    web_user="${system_user}"
  fi

  if [[ -z "${db_password}" ]]; then
    fatal "DB-Passwort darf nicht leer sein."
  fi

  step "Abhängigkeiten für das Panel werden installiert."
  log "Webserver: nginx"
  log "PHP-Version: ${php_version}"
  log "Datenbanksystem: ${db_system}"
  log "System-User: ${system_user}"
  log "Web-User: ${web_user}"

  local pkg_manager
  pkg_manager="$(detect_package_manager)"

  local base_packages=(ca-certificates curl git unzip nginx)
  local php_packages=(
    "php${php_version}" "php${php_version}-fpm" "php${php_version}-cli"
    "php${php_version}-mbstring" "php${php_version}-xml" "php${php_version}-curl"
    "php${php_version}-zip" "php${php_version}-gd" "php${php_version}-intl"
    "php${php_version}-bcmath" "php${php_version}-opcache"
  )
  local db_packages=()
  local php_db_packages=()

  case "${db_system}" in
    mariadb)
      db_packages=(mariadb-server mariadb-client)
      php_db_packages=("php${php_version}-mysql")
      ;;
    mysql)
      db_packages=(mysql-server mysql-client)
      php_db_packages=("php${php_version}-mysql")
      ;;
    postgresql)
      db_packages=(postgresql postgresql-contrib)
      php_db_packages=("php${php_version}-pgsql")
      ;;
  esac

  install_packages "${pkg_manager}" "${base_packages[@]}" "${php_packages[@]}" "${php_db_packages[@]}" "${db_packages[@]}"

  ensure_service_enabled nginx.service
  case "${db_system}" in
    mariadb|mysql)
      ensure_service_enabled mariadb.service
      ensure_service_enabled mysql.service
      ;;
    postgresql)
      ensure_service_enabled postgresql.service
      ;;
  esac

  ensure_system_user "${system_user}" "${install_dir}"

  case "${db_system}" in
    mariadb|mysql)
      setup_database_mysql "${db_name}" "${db_user}" "${db_password}" "${db_root_password}"
      ;;
    postgresql)
      setup_database_postgresql "${db_name}" "${db_user}" "${db_password}"
      ;;
  esac

  step "Panel-Installer wird gestartet (${mode})."

  local panel_cmd=("${SCRIPT_DIR}/easywi-installer-panel-linux.sh" "--mode" "${mode}" "--install-dir" "${install_dir}" "--repo-ref" "${repo_ref}" "--db-driver" "${db_driver}" "--db-host" "${db_host}" "--db-name" "${db_name}" "--db-user" "${db_user}" "--run-migrations" "${run_migrations}")

  if [[ -n "${repo_url}" ]]; then
    panel_cmd+=("--repo-url" "${repo_url}")
  fi
  if [[ -n "${db_port}" ]]; then
    panel_cmd+=("--db-port" "${db_port}")
  fi
  if [[ -n "${db_password}" ]]; then
    panel_cmd+=("--db-password" "${db_password}")
  fi
  if [[ -n "${app_secret}" ]]; then
    panel_cmd+=("--app-secret" "${app_secret}")
  fi
  if [[ -n "${app_encryption_keys}" ]]; then
    panel_cmd+=("--app-encryption-keys" "${app_encryption_keys}")
  fi
  if [[ -n "${agent_registration_token}" ]]; then
    panel_cmd+=("--agent-registration-token" "${agent_registration_token}")
  fi
  if [[ -n "${app_github_token}" ]]; then
    panel_cmd+=("--app-github-token" "${app_github_token}")
  fi
  if [[ "${mode}" == "standalone" ]]; then
    panel_cmd+=("--php-version" "${php_version}")
    panel_cmd+=("--web-hostname" "${web_hostname}")
    panel_cmd+=("--web-user" "${web_user}")
    panel_cmd+=("--web-server" "${web_server}")
  fi

  "${panel_cmd[@]}"
}

run_agent_install() {
  local core_url="${EASYWI_CORE_URL:-${EASYWI_API_URL:-}}"
  local bootstrap_token="${EASYWI_BOOTSTRAP_TOKEN:-}"
  local roles="${EASYWI_ROLES:-}"
  local agent_version="${EASYWI_AGENT_VERSION:-latest}"
  local channel="${EASYWI_CHANNEL:-}"
  local mail_hostname="${EASYWI_MAIL_HOSTNAME:-}"
  local db_bind_address="${EASYWI_DB_BIND_ADDRESS:-127.0.0.1}"
  local db_subnet="${EASYWI_DB_SUBNET:-}"

  echo
  echo "Agent-Setup: Wir laden die Agent-Binaries."
  echo "Optional registrieren wir sie am Core und richten Systemd-Services ein."

  prompt_value core_url "Core API URL" "${core_url}"
  prompt_value bootstrap_token "Bootstrap Token" "${bootstrap_token}"
  prompt_value roles "Rollen (comma-separated, optional)" "${roles}"
  prompt_value agent_version "Agent Version (latest oder Tag)" "${agent_version}"
  prompt_value channel "Release-Channel (optional)" "${channel}"
  prompt_value mail_hostname "Mail Hostname (optional)" "${mail_hostname}"
  prompt_value db_bind_address "DB Bind Address" "${db_bind_address}"
  prompt_value db_subnet "DB Allowed Subnet (optional)" "${db_subnet}"

  if [[ -z "${core_url}" || -z "${bootstrap_token}" ]]; then
    log "Kein Core-URL/Token angegeben -> Installiere nur die Binaries."
    install_agent_binaries_only "${agent_version}"
    return
  fi

  step "Agent-Installer wird gestartet."

  local agent_cmd=("${SCRIPT_DIR}/easywi-installer-linux.sh" "--core-url" "${core_url}" "--bootstrap-token" "${bootstrap_token}" "--agent-version" "${agent_version}" "--db-bind-address" "${db_bind_address}")
  if [[ -n "${roles}" ]]; then
    agent_cmd+=("--roles" "${roles}")
  fi
  if [[ -n "${channel}" ]]; then
    agent_cmd+=("--channel" "${channel}")
  fi
  if [[ -n "${mail_hostname}" ]]; then
    agent_cmd+=("--mail-hostname" "${mail_hostname}")
  fi
  if [[ -n "${db_subnet}" ]]; then
    agent_cmd+=("--db-subnet" "${db_subnet}")
  fi

  "${agent_cmd[@]}"
}

main_menu() {
  cat <<'MENU'

Was möchten Sie installieren?
  1) Webinterface (Panel)
  2) Agent
  3) Panel + Agent
  4) Beenden
MENU

  local choice
  read -r -p "Bitte wählen Sie [1-4]: " choice
  case "${choice}" in
    1)
      run_panel_install
      ;;
    2)
      run_agent_install
      ;;
    3)
      run_panel_install
      run_agent_install
      ;;
    *)
      log "Installation beendet."
      ;;
  esac
}

require_root
print_intro
main_menu
