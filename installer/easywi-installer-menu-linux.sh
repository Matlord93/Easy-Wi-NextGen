#!/usr/bin/env bash
set -euo pipefail

VERSION="0.1.0"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

LOG_PREFIX="[easywi-installer-menu]"
STEP_COUNTER=0

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
  local db_host="${EASYWI_DB_HOST:-127.0.0.1}"
  local db_port="${EASYWI_DB_PORT:-}"
  local db_name="${EASYWI_DB_NAME:-easywi}"
  local db_user="${EASYWI_DB_USER:-easywi}"
  local db_password="${EASYWI_DB_PASSWORD:-}"
  local php_version="${EASYWI_PHP_VERSION:-}"
  local web_hostname="${EASYWI_WEB_HOSTNAME:-_}"
  local web_user="${EASYWI_WEB_USER:-}"
  local web_server="${EASYWI_WEB_SERVER:-nginx}"
  local app_secret="${EASYWI_APP_SECRET:-}"
  local app_encryption_keys="${EASYWI_APP_ENCRYPTION_KEYS:-}"
  local agent_registration_token="${EASYWI_AGENT_REGISTRATION_TOKEN:-}"
  local app_github_token="${EASYWI_APP_GITHUB_TOKEN:-}"
  local run_migrations="${EASYWI_RUN_MIGRATIONS:-true}"

  echo
  echo "Panel-Setup: Wir laden den Quellcode, schreiben die .env.local,"
  echo "konfigurieren den Webserver (falls Standalone), und führen optional"
  echo "die Datenbankmigrationen aus."

  prompt_value repo_url "Git-Repository URL (optional, leer = Standard)" ""
  prompt_value install_dir "Installationsverzeichnis" "${install_dir}"
  prompt_value repo_ref "Git-Branch/Tag" "${repo_ref}"
  prompt_value db_driver "DB-Treiber (mysql/pgsql)" "${db_driver}"
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
    prompt_value php_version "PHP-Version (8.4/8.5)" "${php_version}"
    prompt_value web_hostname "Servername" "${web_hostname}"
    prompt_value web_user "Web-User" "${web_user}"
    prompt_value web_server "Webserver (nginx/apache)" "${web_server}"
  fi

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
    if [[ -n "${php_version}" ]]; then
      panel_cmd+=("--php-version" "${php_version}")
    fi
    panel_cmd+=("--web-hostname" "${web_hostname}")
    if [[ -n "${web_user}" ]]; then
      panel_cmd+=("--web-user" "${web_user}")
    fi
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
  echo "Agent-Setup: Wir laden die Agent-Binary, registrieren sie am Core"
  echo "und richten den Systemd-Service ein."

  prompt_value core_url "Core API URL" "${core_url}"
  prompt_value bootstrap_token "Bootstrap Token" "${bootstrap_token}"
  prompt_value roles "Rollen (comma-separated, optional)" "${roles}"
  prompt_value agent_version "Agent Version (latest oder Tag)" "${agent_version}"
  prompt_value channel "Release-Channel (optional)" "${channel}"
  prompt_value mail_hostname "Mail Hostname (optional)" "${mail_hostname}"
  prompt_value db_bind_address "DB Bind Address" "${db_bind_address}"
  prompt_value db_subnet "DB Allowed Subnet (optional)" "${db_subnet}"

  if [[ -z "${core_url}" || -z "${bootstrap_token}" ]]; then
    fatal "Core API URL und Bootstrap Token sind erforderlich."
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
