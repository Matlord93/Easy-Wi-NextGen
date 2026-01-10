#!/usr/bin/env bash
set -euo pipefail

VERSION="0.1.0"

REPO_OWNER="${EASYWI_REPO_OWNER:-Matlord93}"
REPO_NAME="${EASYWI_REPO_NAME:-Easy-Wi-NextGen}"
REPO_URL="${EASYWI_REPO_URL:-https://github.com/${REPO_OWNER}/${REPO_NAME}.git}"
REPO_REF="${EASYWI_REPO_REF:-Beta}"
INSTALL_MODE="${EASYWI_INSTALL_MODE:-}"
INSTALL_DIR="${EASYWI_INSTALL_DIR:-/var/www/easywi}"
APP_ENV="${EASYWI_APP_ENV:-prod}"
APP_SECRET="${EASYWI_APP_SECRET:-}"
DB_DRIVER="${EASYWI_DB_DRIVER:-mysql}"
DB_HOST="${EASYWI_DB_HOST:-127.0.0.1}"
DB_PORT="${EASYWI_DB_PORT:-}"
DB_NAME="${EASYWI_DB_NAME:-easywi}"
DB_USER="${EASYWI_DB_USER:-easywi}"
DB_PASSWORD="${EASYWI_DB_PASSWORD:-}"
RUN_MIGRATIONS="${EASYWI_RUN_MIGRATIONS:-true}"
FORCE_OVERWRITE="${EASYWI_FORCE_OVERWRITE:-false}"
NON_INTERACTIVE="${EASYWI_NON_INTERACTIVE:-}"
INTERACTIVE="${EASYWI_INTERACTIVE:-}"
WEB_HOSTNAME="${EASYWI_WEB_HOSTNAME:-_}"
WEB_USER="${EASYWI_WEB_USER:-}"

LOG_PREFIX="[easywi-panel-installer]"
STEP_COUNTER=0

usage() {
  cat <<USAGE
EasyWI Panel Installer (Linux) v${VERSION}

Usage:
  easywi-installer-panel-linux.sh [options]

Options:
  --mode <type>          Install mode: standalone, plesk, aapanel
  --install-dir <path>   Target directory (default: /var/www/easywi)
  --repo-url <url>       Git repository URL
  --repo-ref <ref>       Git branch or tag (default: main)
  --db-driver <name>     Database driver: mysql or pgsql
  --db-host <host>       Database host
  --db-port <port>       Database port (optional)
  --db-name <name>       Database name
  --db-user <user>       Database user
  --db-password <pass>   Database password
  --web-hostname <name>  Server name for standalone nginx config
  --web-user <user>      Web server user for permissions (standalone)
  --run-migrations <y/n> Run doctrine migrations (default: true)
  --force               Overwrite existing install dir
  --interactive          Prompt for missing values
  --non-interactive      Disable prompts
  -h, --help             Show help

Environment variables:
  EASYWI_INSTALL_MODE, EASYWI_INSTALL_DIR, EASYWI_REPO_URL, EASYWI_REPO_REF,
  EASYWI_DB_DRIVER, EASYWI_DB_HOST, EASYWI_DB_PORT, EASYWI_DB_NAME,
  EASYWI_DB_USER, EASYWI_DB_PASSWORD, EASYWI_RUN_MIGRATIONS,
  EASYWI_WEB_HOSTNAME, EASYWI_WEB_USER, EASYWI_APP_ENV, EASYWI_APP_SECRET,
  EASYWI_FORCE_OVERWRITE, EASYWI_INTERACTIVE, EASYWI_NON_INTERACTIVE
USAGE
}

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

command_exists() {
  command -v "$1" >/dev/null 2>&1
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

print_summary() {
  cat <<SUMMARY

================= EasyWI Panel Install =================
Mode:               ${INSTALL_MODE}
Install dir:        ${INSTALL_DIR}
Repo:               ${REPO_URL} (${REPO_REF})
DB:                 ${DB_DRIVER} @ ${DB_HOST}:${DB_PORT:-default} (${DB_NAME})
Web hostname:       ${WEB_HOSTNAME}
Run migrations:     ${RUN_MIGRATIONS}
========================================================
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
    if [[ -z "${INSTALL_MODE}" || -z "${DB_PASSWORD}" ]]; then
      INTERACTIVE="true"
    fi
  fi

  if [[ "$(normalize_bool "${INTERACTIVE}")" == "true" ]]; then
    prompt_value INSTALL_MODE "Install mode (standalone, plesk, aapanel)" "${INSTALL_MODE:-standalone}"
    prompt_value INSTALL_DIR "Install directory" "${INSTALL_DIR}"
    prompt_value REPO_URL "Repository URL" "${REPO_URL}"
    prompt_value REPO_REF "Repository branch/tag" "${REPO_REF}"
    prompt_value DB_DRIVER "Database driver (mysql/pgsql)" "${DB_DRIVER}"
    prompt_value DB_HOST "Database host" "${DB_HOST}"
    prompt_value DB_PORT "Database port (optional)" "${DB_PORT}"
    prompt_value DB_NAME "Database name" "${DB_NAME}"
    prompt_value DB_USER "Database user" "${DB_USER}"
    prompt_value DB_PASSWORD "Database password" "" "true"
    if [[ "${INSTALL_MODE}" == "standalone" ]]; then
      prompt_value WEB_HOSTNAME "Web hostname (server_name)" "${WEB_HOSTNAME}"
      prompt_value WEB_USER "Web server user" "${WEB_USER:-www-data}"
    fi
    prompt_value RUN_MIGRATIONS "Run migrations? (true/false)" "${RUN_MIGRATIONS}"
    print_summary
    confirm_continue
  fi
}

validate_inputs() {
  case "${INSTALL_MODE}" in
    standalone|plesk|aapanel)
      ;;
    *)
      fatal "Invalid mode: ${INSTALL_MODE}. Use standalone, plesk, or aapanel."
      ;;
  esac

  case "${DB_DRIVER}" in
    mysql|pgsql)
      ;;
    *)
      fatal "Invalid DB driver: ${DB_DRIVER}. Use mysql or pgsql."
      ;;
  esac

  if [[ -z "${DB_PASSWORD}" ]]; then
    fatal "Database password missing. Provide --db-password or EASYWI_DB_PASSWORD."
  fi

  if [[ -z "${INSTALL_DIR}" ]]; then
    fatal "Install directory missing."
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
    return 0
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

install_php_stack() {
  local service=""
  local socket=""
  case "${OS_FAMILY}" in
    debian)
      if pkg_install nginx php8.5-fpm php8.5-cli php8.5-mbstring php8.5-xml php8.5-curl php8.5-zip php8.5-intl php8.5-gd php8.5-mysql php8.5-pgsql git unzip composer; then
        service="php8.5-fpm"
        socket="/run/php/php8.5-fpm.sock"
      elif pkg_install nginx php8.4-fpm php8.4-cli php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-intl php8.4-gd php8.4-mysql php8.4-pgsql git unzip composer; then
        service="php8.4-fpm"
        socket="/run/php/php8.4-fpm.sock"
      else
        fatal "Unable to install PHP 8.4+ and nginx for standalone mode."
      fi
      ;;
    rhel)
      if pkg_install nginx php php-cli php-fpm php-mbstring php-xml php-json php-curl php-zip php-intl php-gd php-mysqlnd php-pgsql git unzip composer; then
        service="php-fpm"
        socket="/run/php-fpm/www.sock"
      else
        fatal "Unable to install PHP and nginx for standalone mode."
      fi
      ;;
    arch)
      if pkg_install nginx php php-fpm php-intl php-gd php-pgsql php-sqlite php-zip git unzip composer; then
        service="php-fpm"
        socket="/run/php-fpm/php-fpm.sock"
      else
        fatal "Unable to install PHP and nginx for standalone mode."
      fi
      ;;
  esac

  echo "${service}|${socket}"
}

ensure_web_user() {
  if [[ -n "${WEB_USER}" ]]; then
    return
  fi
  if [[ "${INSTALL_MODE}" == "standalone" ]]; then
    WEB_USER="www-data"
  fi
}

download_source() {
  if [[ -d "${INSTALL_DIR}" && -n "$(ls -A "${INSTALL_DIR}" 2>/dev/null)" ]]; then
    if [[ "$(normalize_bool "${FORCE_OVERWRITE}")" == "true" ]]; then
      rm -rf "${INSTALL_DIR}"
    else
      fatal "Install directory is not empty. Use --force to overwrite."
    fi
  fi

  mkdir -p "${INSTALL_DIR}"

  if ! command_exists git; then
    fatal "git is required to download the web interface."
  fi

  log "Cloning ${REPO_URL} (${REPO_REF}) to ${INSTALL_DIR}"
  git clone --depth 1 --branch "${REPO_REF}" "${REPO_URL}" "${INSTALL_DIR}"
}

generate_app_secret() {
  if [[ -n "${APP_SECRET}" ]]; then
    return
  fi

  if command_exists openssl; then
    APP_SECRET="$(openssl rand -hex 16)"
  else
    APP_SECRET="$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 32)"
  fi
}

urlencode() {
  local raw="$1"
  if command_exists python3; then
    printf '%s' "${raw}" | python3 - <<'PY'
import sys
import urllib.parse
value = sys.stdin.read()
print(urllib.parse.quote(value))
PY
  else
    echo "${raw}" | sed -e 's/%/%25/g' -e 's/ /%20/g' -e 's/:/%3A/g' -e 's/@/%40/g' -e 's/?/%3F/g' -e 's/#/%23/g' -e 's/&/%26/g' -e 's/=/%3D/g'
  fi
}

write_env_local() {
  local db_password_encoded
  local db_port="${DB_PORT}"

  if [[ -z "${db_port}" ]]; then
    if [[ "${DB_DRIVER}" == "mysql" ]]; then
      db_port="3306"
    else
      db_port="5432"
    fi
  fi

  db_password_encoded="$(urlencode "${DB_PASSWORD}")"

  generate_app_secret

  cat <<EOF >"${INSTALL_DIR}/core/.env.local"
APP_ENV=${APP_ENV}
APP_SECRET=${APP_SECRET}
DATABASE_URL=${DB_DRIVER}://${DB_USER}:${db_password_encoded}@${DB_HOST}:${db_port}/${DB_NAME}
EOF
}

run_composer() {
  if ! command_exists composer; then
    fatal "composer is required but not installed."
  fi

  log "Installing PHP dependencies"
  (cd "${INSTALL_DIR}/core" && composer install --no-dev --optimize-autoloader --no-interaction)
}

run_migrations() {
  if [[ "$(normalize_bool "${RUN_MIGRATIONS}")" != "true" ]]; then
    log "Skipping migrations"
    return
  fi
  if ! command_exists php; then
    fatal "php is required to run migrations."
  fi
  log "Running database migrations"
  (cd "${INSTALL_DIR}/core" && php bin/console doctrine:migrations:migrate --no-interaction)
}

set_permissions() {
  if [[ -z "${WEB_USER}" ]]; then
    return
  fi
  chown -R "${WEB_USER}:${WEB_USER}" "${INSTALL_DIR}/core/var" || true
  chown "${WEB_USER}:${WEB_USER}" "${INSTALL_DIR}/core/.env.local" || true
}

write_nginx_config() {
  local php_socket="$1"
  local conf_path="/etc/nginx/sites-available/easywi.conf"
  local enable_path="/etc/nginx/sites-enabled/easywi.conf"

  cat <<CONF >"${conf_path}"
server {
    listen 80;
    server_name ${WEB_HOSTNAME};
    root ${INSTALL_DIR}/core/public;

    location / {
        try_files \$uri /index.php\$is_args\$args;
    }

    location ~ \.php\$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_pass unix:${php_socket};
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
CONF

  ln -sf "${conf_path}" "${enable_path}"
  systemctl enable --now nginx
  systemctl reload nginx
}

install_standalone_stack() {
  local php_service
  local php_socket
  pkg_update
  IFS='|' read -r php_service php_socket < <(install_php_stack)
  systemctl enable --now "${php_service}"
  write_nginx_config "${php_socket}"
}

parse_args() {
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --mode)
        INSTALL_MODE="$2"
        shift 2
        ;;
      --install-dir)
        INSTALL_DIR="$2"
        shift 2
        ;;
      --repo-url)
        REPO_URL="$2"
        shift 2
        ;;
      --repo-ref)
        REPO_REF="$2"
        shift 2
        ;;
      --db-driver)
        DB_DRIVER="$2"
        shift 2
        ;;
      --db-host)
        DB_HOST="$2"
        shift 2
        ;;
      --db-port)
        DB_PORT="$2"
        shift 2
        ;;
      --db-name)
        DB_NAME="$2"
        shift 2
        ;;
      --db-user)
        DB_USER="$2"
        shift 2
        ;;
      --db-password)
        DB_PASSWORD="$2"
        shift 2
        ;;
      --web-hostname)
        WEB_HOSTNAME="$2"
        shift 2
        ;;
      --web-user)
        WEB_USER="$2"
        shift 2
        ;;
      --run-migrations)
        RUN_MIGRATIONS="$2"
        shift 2
        ;;
      --force)
        FORCE_OVERWRITE="true"
        shift
        ;;
      --interactive)
        INTERACTIVE="true"
        shift
        ;;
      --non-interactive)
        NON_INTERACTIVE="true"
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
  if [[ -z "${INSTALL_MODE}" ]]; then
    INSTALL_MODE="standalone"
  fi
  step "Collect input"
  collect_inputs
  step "Validate input"
  validate_inputs

  ensure_web_user

  step "Detect OS"
  read_os_release
  OS_FAMILY="$(normalize_os)"

  if [[ "${INSTALL_MODE}" == "standalone" ]]; then
    step "Install standalone dependencies"
    install_standalone_stack
  else
    if ! command_exists php || ! command_exists composer; then
      fatal "php and composer must be available for ${INSTALL_MODE} mode."
    fi
  fi

  step "Download web interface"
  download_source

  step "Write configuration"
  write_env_local

  step "Install PHP dependencies"
  run_composer

  step "Run migrations"
  run_migrations

  step "Set permissions"
  set_permissions

  log "Panel installation complete."
  log "Open /install in the browser to create the first admin account."
}

main "$@"
