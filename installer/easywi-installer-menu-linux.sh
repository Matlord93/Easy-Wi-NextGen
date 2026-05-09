#!/usr/bin/env bash
# EasyWI Linux Installer – v2.0.0
# Supports: Debian, Ubuntu, RHEL, CentOS, Rocky, AlmaLinux, Fedora,
#           openSUSE, SLES, Arch Linux, Manjaro, Alpine Linux
# Modes: Panel (Core), Agent, Panel+Agent, Agent-Update
set -euo pipefail

VERSION="2.0.0"
INSTALLER_NAME="[easywi-installer]"
STEP_COUNTER=0
DEFAULT_PHP_VERSION="8.4"
APT_UPDATED=0
EASYWI_GITHUB_REPO="Matlord93/Easy-Wi-NextGen"
EASYWI_GITHUB_RELEASE_BASE="https://github.com/${EASYWI_GITHUB_REPO}/releases"

# ---------------------------------------------------------------------------
# Logging helpers
# ---------------------------------------------------------------------------

log()   { printf '%s %s\n'  "${INSTALLER_NAME}" "$*" >&2; }
step()  { STEP_COUNTER=$((STEP_COUNTER + 1)); log "── Schritt ${STEP_COUNTER}: $*"; }
ok()    { log "   ✓ $*"; }
warn()  { log "   ⚠ $*"; }
fatal() { log "FEHLER: $*"; exit 1; }

require_root() {
  [[ "${EUID}" -eq 0 ]] || fatal "Dieses Skript muss als root ausgeführt werden."
}

# ---------------------------------------------------------------------------
# TTY I/O helpers
# ---------------------------------------------------------------------------

is_tty() { [[ -t 0 ]]; }

read_from_tty() {
  local prompt="${1:-}" value=""
  if [[ -e /dev/tty ]]; then
    [[ -n "${prompt}" ]] && printf '%s' "${prompt}" >/dev/tty
    IFS= read -r value </dev/tty || true
  fi
  printf '%s' "${value}"
}

menu_output() {
  if [[ -e /dev/tty ]]; then cat >/dev/tty; else cat; fi
}

menu_prompt() { read_from_tty "${1}"; }

prompt_value() {
  local var_name="$1" prompt="$2" default="${3:-}" current="${!1:-}" value
  [[ -n "${current}" ]] && return
  is_tty || return
  [[ -n "${default}" ]] && prompt="${prompt} [${default}]"
  value="$(read_from_tty "${prompt}: ")"
  [[ -z "${value}" ]] && value="${default}"
  [[ -n "${value}" ]] && printf -v "${var_name}" '%s' "${value}"
}

prompt_yes_no() {
  local prompt="$1" default="${2:-no}" value=""
  is_tty || return 1
  case "${default}" in
    yes) prompt="${prompt} [J/n]" ;;
    no)  prompt="${prompt} [j/N]" ;;
    *)   prompt="${prompt} [j/n]" ;;
  esac
  value="$(read_from_tty "${prompt}: ")"
  [[ -z "${value}" ]] && value="${default}"
  case "${value}" in j|J|ja|JA|Ja|y|Y|yes) return 0 ;; *) return 1 ;; esac
}

# ---------------------------------------------------------------------------
# OS detection
# ---------------------------------------------------------------------------

get_os_field() {
  local key="$1"
  [[ -r /etc/os-release ]] \
    && awk -F= -v k="${key}" '$1==k{gsub(/"/,"",$2);print $2}' /etc/os-release | head -n1
}

detect_os_family() {
  # Returns: debian | rhel | suse | arch | alpine | unknown
  local id id_like
  id="$(get_os_field ID | tr '[:upper:]' '[:lower:]')"
  id_like="$(get_os_field ID_LIKE | tr '[:upper:]' '[:lower:]')"

  case "${id}" in
    ubuntu|debian|linuxmint|pop|elementary|kali|mx|devuan|raspbian)
      echo "debian"; return ;;
    rhel|centos|rocky|almalinux|ol|fedora|amzn)
      echo "rhel"; return ;;
    opensuse*|sles|sled)
      echo "suse"; return ;;
    arch|manjaro|endeavouros|garuda)
      echo "arch"; return ;;
    alpine)
      echo "alpine"; return ;;
  esac

  for token in ${id_like}; do
    case "${token}" in
      debian|ubuntu)    echo "debian"; return ;;
      rhel|fedora|centos) echo "rhel"; return ;;
      suse|opensuse)    echo "suse"; return ;;
      arch)             echo "arch"; return ;;
    esac
  done

  echo "unknown"
}

detect_package_manager() {
  command -v apt-get   >/dev/null 2>&1 && { echo "apt";    return; }
  command -v dnf       >/dev/null 2>&1 && { echo "dnf";    return; }
  command -v yum       >/dev/null 2>&1 && { echo "yum";    return; }
  command -v zypper    >/dev/null 2>&1 && { echo "zypper"; return; }
  command -v pacman    >/dev/null 2>&1 && { echo "pacman"; return; }
  command -v apk       >/dev/null 2>&1 && { echo "apk";    return; }
  fatal "Kein unterstützter Paketmanager gefunden (apt, dnf, yum, zypper, pacman, apk)."
}

detect_arch_suffix() {
  local m; m="$(uname -m)"
  case "${m}" in
    x86_64|amd64)    echo "linux-amd64" ;;
    aarch64|arm64)   echo "linux-arm64" ;;
    armv7l|armhf)    echo "linux-arm"   ;;
    *)  fatal "Nicht unterstützte CPU-Architektur: ${m}" ;;
  esac
}

# ---------------------------------------------------------------------------
# Package management
# ---------------------------------------------------------------------------

apt_update_once() {
  if [[ "${APT_UPDATED}" -eq 0 ]]; then
    DEBIAN_FRONTEND=noninteractive apt-get update -y >/dev/null 2>&1
    APT_UPDATED=1
  fi
}

install_packages() {
  local manager="$1"; shift
  local packages=("$@")
  [[ ${#packages[@]} -eq 0 ]] && return

  log "Installiere Pakete: ${packages[*]}"
  case "${manager}" in
    apt)
      apt_update_once
      DEBIAN_FRONTEND=noninteractive apt-get install -y "${packages[@]}" >/dev/null 2>&1
      ;;
    dnf)    dnf install -y "${packages[@]}" >/dev/null 2>&1 ;;
    yum)    yum install -y "${packages[@]}" >/dev/null 2>&1 ;;
    zypper) zypper --non-interactive install --no-confirm "${packages[@]}" >/dev/null 2>&1 ;;
    pacman) pacman -Sy --noconfirm --needed "${packages[@]}" >/dev/null 2>&1 ;;
    apk)    apk add --no-cache "${packages[@]}" >/dev/null 2>&1 ;;
    *)      fatal "Unbekannter Paketmanager: ${manager}" ;;
  esac
  ok "Pakete installiert."
}

# ---------------------------------------------------------------------------
# Repository setup per distro family
# ---------------------------------------------------------------------------

setup_php_repo() {
  local manager="$1" family="$2" php_version="$3"

  case "${family}" in
    debian)
      step "Richte PHP-Repository ein (Ondrej/PHP)."
      DEBIAN_FRONTEND=noninteractive apt-get install -y software-properties-common >/dev/null 2>&1
      local distro; distro="$(get_os_field ID)"
      if [[ "${distro}" == "ubuntu" ]]; then
        # Ubuntu >= 24.04 ships PHP 8.x in main; still add PPA for latest
        add-apt-repository -y ppa:ondrej/php >/dev/null 2>&1 || warn "PPA konnte nicht hinzugefügt werden – nutze Distro-Pakete."
      else
        # Debian: sury.org
        curl -fsSL https://packages.sury.org/php/apt.gpg \
          | gpg --dearmor -o /etc/apt/trusted.gpg.d/php.gpg 2>/dev/null
        echo "deb https://packages.sury.org/php/ $(get_os_field VERSION_CODENAME) main" \
          > /etc/apt/sources.list.d/php.list
      fi
      APT_UPDATED=0
      apt_update_once
      ;;
    rhel)
      step "Richte EPEL + Remi-Repository ein (PHP ${php_version})."
      local os_ver; os_ver="$(get_os_field VERSION_ID | cut -d. -f1)"
      # EPEL
      dnf install -y epel-release >/dev/null 2>&1 \
        || yum install -y epel-release >/dev/null 2>&1 \
        || warn "EPEL konnte nicht installiert werden."
      # Remi
      if ! rpm -q remi-release >/dev/null 2>&1; then
        local remi_pkg="https://rpms.remirepo.net/enterprise/remi-release-${os_ver}.rpm"
        rpm -Uvh "${remi_pkg}" >/dev/null 2>&1 \
          || warn "Remi-Release-Paket nicht gefunden für EL${os_ver}."
      fi
      dnf module reset   php -y >/dev/null 2>&1 || true
      dnf module enable  "php:remi-${php_version}" -y >/dev/null 2>&1 \
        || warn "Remi PHP-Modul nicht verfügbar, nutze System-PHP."
      ;;
    suse)
      step "Richte PHP-Repository (openSUSE) ein."
      local os_ver; os_ver="$(get_os_field VERSION_ID)"
      zypper --non-interactive addrepo --refresh \
        "https://download.opensuse.org/repositories/devel:languages:php/openSUSE_Leap_${os_ver}/devel:languages:php.repo" \
        2>/dev/null || warn "PHP-Repo für openSUSE ${os_ver} nicht verfügbar."
      zypper --non-interactive --gpg-auto-import-keys refresh >/dev/null 2>&1 || true
      ;;
    arch)
      step "Aktualisiere Pacman-Datenbank."
      pacman -Sy >/dev/null 2>&1
      ;;
    alpine)
      step "Aktiviere Alpine community repository."
      local rel; rel="$(get_os_field VERSION_ID | cut -d. -f1-2)"
      local repo_line="https://dl-cdn.alpinelinux.org/alpine/v${rel}/community"
      grep -qF "${repo_line}" /etc/apk/repositories 2>/dev/null \
        || echo "${repo_line}" >> /etc/apk/repositories
      apk update >/dev/null 2>&1
      ;;
  esac
}

# ---------------------------------------------------------------------------
# PHP package resolution
# ---------------------------------------------------------------------------

resolve_php_version() {
  local requested="$1" manager="$2" family="$3"
  local ver; ver="$(printf '%s' "${requested}" | grep -oE '[0-9]+(\.[0-9]+)?' | head -n1 || true)"
  [[ -z "${ver}" ]] && ver="${DEFAULT_PHP_VERSION}"

  if [[ "${manager}" == "apt" ]]; then
    apt_update_once
    for candidate in "${ver}" 8.4 8.3 8.2 8.1; do
      if apt-cache show "php${candidate}" >/dev/null 2>&1; then
        [[ "${candidate}" != "${ver}" ]] && warn "PHP ${ver} nicht verfügbar – nutze ${candidate}."
        echo "${candidate}"; return
      fi
    done
    fatal "Keine unterstützte PHP-Version in den Paketquellen gefunden."
  fi

  # Non-apt: accept as-is (repo was set up above)
  echo "${ver}"
}

# Build the full list of PHP packages for a given manager/version
resolve_php_packages() {
  local manager="$1" php_version="$2" db_system="$3"
  PHP_BASE_PKGS=()
  PHP_FPM_SERVICE=""
  PHP_FPM_SOCKET=""

  case "${manager}" in
    apt)
      PHP_BASE_PKGS=(
        "php${php_version}"
        "php${php_version}-fpm"
        "php${php_version}-cli"
        "php${php_version}-mbstring"
        "php${php_version}-xml"
        "php${php_version}-curl"
        "php${php_version}-zip"
        "php${php_version}-gd"
        "php${php_version}-intl"
        "php${php_version}-bcmath"
        "php${php_version}-opcache"
        "php${php_version}-redis"
      )
      case "${db_system}" in
        mariadb|mysql) PHP_BASE_PKGS+=("php${php_version}-mysql") ;;
        postgresql)    PHP_BASE_PKGS+=("php${php_version}-pgsql") ;;
      esac
      PHP_FPM_SERVICE="php${php_version}-fpm"
      PHP_FPM_SOCKET="/run/php/php${php_version}-fpm.sock"
      ;;
    dnf|yum)
      PHP_BASE_PKGS=(php php-fpm php-cli php-mbstring php-xml php-curl
                     php-zip php-gd php-intl php-bcmath php-opcache php-redis)
      case "${db_system}" in
        mariadb|mysql) PHP_BASE_PKGS+=(php-mysqlnd) ;;
        postgresql)    PHP_BASE_PKGS+=(php-pgsql)   ;;
      esac
      PHP_FPM_SERVICE="php-fpm"
      PHP_FPM_SOCKET="/run/php-fpm/www.sock"
      ;;
    zypper)
      PHP_BASE_PKGS=(php8 php8-fpm php8-cli php8-mbstring php8-xmlwriter
                     php8-curl php8-zip php8-gd php8-intl php8-bcmath php8-opcache)
      case "${db_system}" in
        mariadb|mysql) PHP_BASE_PKGS+=(php8-mysql)  ;;
        postgresql)    PHP_BASE_PKGS+=(php8-pgsql)   ;;
      esac
      PHP_FPM_SERVICE="php-fpm"
      PHP_FPM_SOCKET="/run/php-fpm/php-fpm.sock"
      ;;
    pacman)
      PHP_BASE_PKGS=(php php-fpm)
      case "${db_system}" in
        mariadb|mysql) PHP_BASE_PKGS+=(php-intl) ;;  # db via mysqli in PKGBUILD
        postgresql)    PHP_BASE_PKGS+=(php-pgsql) ;;
      esac
      PHP_FPM_SERVICE="php-fpm"
      PHP_FPM_SOCKET="/run/php-fpm/php-fpm.sock"
      ;;
    apk)
      PHP_BASE_PKGS=(php83 php83-fpm php83-cli php83-mbstring php83-xml
                     php83-curl php83-zip php83-gd php83-intl php83-bcmath
                     php83-opcache php83-pecl-redis)
      case "${db_system}" in
        mariadb|mysql) PHP_BASE_PKGS+=(php83-pdo_mysql php83-mysqli) ;;
        postgresql)    PHP_BASE_PKGS+=(php83-pdo_pgsql php83-pgsql) ;;
      esac
      PHP_FPM_SERVICE="php-fpm83"
      PHP_FPM_SOCKET="/run/php-fpm/php-fpm.sock"
      ;;
  esac
}

# ---------------------------------------------------------------------------
# Database package resolution
# ---------------------------------------------------------------------------

resolve_db_packages() {
  local manager="$1" db_system="$2"
  DB_PKGS=()
  DB_SERVICES=()

  case "${db_system}" in
    mariadb)
      case "${manager}" in
        apt)           DB_PKGS=(mariadb-server mariadb-client) ;;
        dnf|yum)       DB_PKGS=(mariadb-server mariadb) ;;
        zypper)        DB_PKGS=(mariadb mariadb-client) ;;
        pacman)        DB_PKGS=(mariadb) ;;
        apk)           DB_PKGS=(mariadb mariadb-client) ;;
      esac
      DB_SERVICES=(mariadb mysql)
      ;;
    mysql)
      case "${manager}" in
        apt)           DB_PKGS=(mysql-server mysql-client) ;;
        dnf|yum)       DB_PKGS=(mysql-community-server mysql-community-client) ;;
        zypper)        DB_PKGS=(mariadb mariadb-client) ;; # MySQL often unavailable on openSUSE
        pacman)        DB_PKGS=(mariadb) ;;
        apk)           DB_PKGS=(mariadb mariadb-client) ;;
      esac
      DB_SERVICES=(mysql mysqld mariadb)
      ;;
    postgresql)
      case "${manager}" in
        apt)           DB_PKGS=(postgresql postgresql-contrib) ;;
        dnf|yum)       DB_PKGS=(postgresql-server postgresql-contrib) ;;
        zypper)        DB_PKGS=(postgresql postgresql-server postgresql-contrib) ;;
        pacman)        DB_PKGS=(postgresql) ;;
        apk)           DB_PKGS=(postgresql postgresql-contrib) ;;
      esac
      DB_SERVICES=(postgresql)
      ;;
  esac
}

# ---------------------------------------------------------------------------
# nginx config path per distro
# ---------------------------------------------------------------------------

detect_nginx_conf_dir() {
  local family="$1"
  case "${family}" in
    debian) echo "/etc/nginx/sites-available" ;;
    *)      echo "/etc/nginx/conf.d" ;;
  esac
}

detect_nginx_enabled_dir() {
  local family="$1"
  case "${family}" in
    debian) echo "/etc/nginx/sites-enabled" ;;
    *)      echo "/etc/nginx/conf.d" ;;
  esac
}

nginx_packages() {
  local manager="$1"
  case "${manager}" in
    apt|dnf|yum|zypper|pacman) echo "nginx" ;;
    apk)                        echo "nginx" ;;
  esac
}

# ---------------------------------------------------------------------------
# Crypto helpers
# ---------------------------------------------------------------------------

random_hex()    { openssl rand -hex "${1:-32}" 2>/dev/null || head -c "${1:-32}" /dev/urandom | od -An -tx1 | tr -d ' \n'; }
random_base64() { openssl rand -base64 "${1:-32}" 2>/dev/null || head -c "${1:-32}" /dev/urandom | base64; }
sha256_hex()    { printf '%s' "$1" | openssl dgst -sha256 -hex | awk '{print $2}'; }
hmac_sha256_hex() {
  local secret="$1" payload="$2"
  printf '%s' "${payload}" | openssl dgst -sha256 -hmac "${secret}" -hex | awk '{print $2}'
}

# ---------------------------------------------------------------------------
# Service helpers
# ---------------------------------------------------------------------------

has_systemctl() { command -v systemctl >/dev/null 2>&1; }
has_openrc()    { command -v rc-service >/dev/null 2>&1; }

service_enable_start() {
  local svc="$1"
  if has_systemctl; then
    systemctl enable --now "${svc}" 2>/dev/null || systemctl start "${svc}" 2>/dev/null || warn "Konnte ${svc} nicht starten."
  elif has_openrc; then
    rc-update add "${svc}" default 2>/dev/null || true
    rc-service "${svc}" start 2>/dev/null || warn "Konnte ${svc} nicht starten (OpenRC)."
  else
    warn "Weder systemd noch OpenRC gefunden – ${svc} muss manuell gestartet werden."
  fi
}

service_reload() {
  local svc="$1"
  if has_systemctl; then
    systemctl reload "${svc}" 2>/dev/null || systemctl restart "${svc}" 2>/dev/null || warn "Konnte ${svc} nicht neuladen."
  elif has_openrc; then
    rc-service "${svc}" restart 2>/dev/null || warn "Konnte ${svc} nicht neustarten."
  fi
}

service_restart() {
  local svc="$1"
  if has_systemctl; then
    systemctl restart "${svc}" 2>/dev/null || warn "Konnte ${svc} nicht neustarten."
  elif has_openrc; then
    rc-service "${svc}" restart 2>/dev/null || warn "Konnte ${svc} nicht neustarten."
  fi
}

service_is_active() {
  local svc="$1"
  if has_systemctl; then
    systemctl is-active --quiet "${svc}" 2>/dev/null
  elif has_openrc; then
    rc-service "${svc}" status >/dev/null 2>&1
  else
    return 1
  fi
}

start_first_available_service() {
  # Start the first service name that systemd/openrc knows about
  local svc
  for svc in "$@"; do
    if has_systemctl && systemctl list-unit-files "${svc}.service" >/dev/null 2>&1; then
      service_enable_start "${svc}.service"; return
    elif has_openrc && rc-service "${svc}" status >/dev/null 2>&1; then
      service_enable_start "${svc}"; return
    fi
  done
  warn "Keiner der Dienste ${*} gefunden."
}

# ---------------------------------------------------------------------------
# System user
# ---------------------------------------------------------------------------

ensure_system_user() {
  local username="$1" home_dir="$2"
  if id -u "${username}" >/dev/null 2>&1; then
    ok "System-User ${username} existiert bereits."; return
  fi
  step "Lege System-User ${username} an."
  useradd --system --create-home --home-dir "${home_dir}" --shell /usr/sbin/nologin "${username}" \
    || adduser -S -D -H -h "${home_dir}" -s /sbin/nologin "${username}" 2>/dev/null \
    || fatal "Konnte System-User ${username} nicht anlegen."
}

# ---------------------------------------------------------------------------
# Git helpers
# ---------------------------------------------------------------------------

ensure_git_safe_dir() {
  local repo_dir="$1"
  git config --global --get-all safe.directory 2>/dev/null | grep -Fxq "${repo_dir}" \
    || git config --global --add safe.directory "${repo_dir}"
}

# ---------------------------------------------------------------------------
# Composer
# ---------------------------------------------------------------------------

install_composer() {
  local php_bin="$1"
  if command -v composer >/dev/null 2>&1; then
    ok "Composer bereits installiert – aktualisiere."
    composer self-update --quiet 2>/dev/null || true
    return
  fi
  step "Installiere Composer."
  local tmp; tmp="$(mktemp)"
  "${php_bin}" -r "copy('https://getcomposer.org/installer','${tmp}');"
  "${php_bin}" "${tmp}" --install-dir=/usr/local/bin --filename=composer --quiet
  rm -f "${tmp}"
  ok "Composer installiert."
}

# ---------------------------------------------------------------------------
# Database setup
# ---------------------------------------------------------------------------

setup_database_mysql() {
  local db_name="$1" db_user="$2" db_password="$3" db_root_password="$4"
  step "Richte MySQL/MariaDB-Datenbank ein: ${db_name}"
  local mysql_cmd=(mysql -u root)
  [[ -n "${db_root_password}" ]] && mysql_cmd+=(--password="${db_root_password}")
  "${mysql_cmd[@]}" <<SQL
CREATE DATABASE IF NOT EXISTS \`${db_name}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${db_user}'@'%' IDENTIFIED BY '${db_password}';
GRANT ALL PRIVILEGES ON \`${db_name}\`.* TO '${db_user}'@'%';
FLUSH PRIVILEGES;
SQL
  ok "Datenbank ${db_name} eingerichtet."
}

setup_database_postgresql() {
  local db_name="$1" db_user="$2" db_password="$3"
  step "Richte PostgreSQL-Datenbank ein: ${db_name}"
  runuser -u postgres -- psql -v ON_ERROR_STOP=1 <<SQL 2>/dev/null
DO \$\$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname='${db_user}') THEN
    CREATE ROLE ${db_user} LOGIN PASSWORD '${db_password}';
  END IF;
  IF NOT EXISTS (SELECT FROM pg_database WHERE datname='${db_name}') THEN
    CREATE DATABASE ${db_name} OWNER ${db_user};
  END IF;
END \$\$;
SQL
  ok "PostgreSQL-Datenbank ${db_name} eingerichtet."
}

# ---------------------------------------------------------------------------
# nginx configuration
# ---------------------------------------------------------------------------

configure_nginx() {
  local family="$1" server_name="$2" web_root="$3" php_fpm_socket="$4"
  local conf_dir; conf_dir="$(detect_nginx_conf_dir "${family}")"
  local enabled_dir; enabled_dir="$(detect_nginx_enabled_dir "${family}")"
  local config_file="${conf_dir}/easywi.conf"

  step "Konfiguriere Nginx (${conf_dir})."
  mkdir -p "${conf_dir}" "${enabled_dir}"

  cat > "${config_file}" <<NGINX
## EasyWI Panel – managed by easywi-installer
server {
    listen 80;
    server_name ${server_name};

    root ${web_root};
    index index.php index.html;

    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    location / {
        try_files \$uri /index.php\$is_args\$args;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:${php_fpm_socket};
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param HTTPS off;
    }

    location ~ /\.(ht|git) {
        deny all;
    }
}
NGINX

  # Debian/Ubuntu use symlinks
  if [[ "${family}" == "debian" && "${conf_dir}" != "${enabled_dir}" ]]; then
    ln -sf "${config_file}" "${enabled_dir}/easywi.conf"
    rm -f /etc/nginx/sites-enabled/default 2>/dev/null || true
  fi

  if nginx -t 2>/dev/null; then
    service_reload nginx
  else
    warn "nginx -t schlug fehl – Konfiguration prüfen."
  fi
  ok "Nginx konfiguriert: ${config_file}"
}

# ---------------------------------------------------------------------------
# .env.local + secret key
# ---------------------------------------------------------------------------

write_env_local() {
  local env_path="$1" db_driver="$2" db_host="$3" db_port="$4"
  local db_name="$5" db_user="$6" db_password="$7"
  local app_secret="$8" encryption_keys="$9"
  local registration_token="${10}" default_uri="${11}" install_dir="${12}"

  local key_id="v1"
  local key_material="${encryption_keys}"
  if [[ "${encryption_keys}" == *:* ]]; then
    key_id="${encryption_keys%%:*}"
    key_material="${encryption_keys#*:}"
  fi

  step "Schreibe Encryption Key nach /etc/easywi/secret.key."
  mkdir -p /etc/easywi
  printf '{"active_key_id":"%s","keys":{"%s":"%s"}}\n' \
    "${key_id}" "${key_id}" "${key_material}" > /etc/easywi/secret.key
  chmod 600 /etc/easywi/secret.key

  local db_url
  case "${db_driver}" in
    pgsql)  db_url="postgresql://${db_user}:${db_password}@${db_host}${db_port:+:${db_port}}/${db_name}" ;;
    *)      db_url="mysql://${db_user}:${db_password}@${db_host}${db_port:+:${db_port}}/${db_name}" ;;
  esac

  step "Schreibe ${env_path}."
  cat > "${env_path}" <<ENV
APP_ENV=prod
APP_SECRET="${app_secret}"
DATABASE_URL="${db_url}"
DEFAULT_URI=${default_uri}
MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0
TRUSTED_PROXIES=127.0.0.1
APP_CORE_UPDATE_INSTALL_DIR=${install_dir}
ENV
  [[ -n "${registration_token}" ]] && printf 'AGENT_REGISTRATION_TOKEN="%s"\n' "${registration_token}" >> "${env_path}"
  chmod 600 "${env_path}"
  ok ".env.local geschrieben."
}

# ---------------------------------------------------------------------------
# Installation summary file
# ---------------------------------------------------------------------------

write_install_info() {
  local info_path="$1"; shift
  step "Schreibe Installationsübersicht nach ${info_path}."
  cat > "${info_path}" <<INFO
EasyWI Installationsübersicht – $(date -u +'%Y-%m-%d %H:%M UTC')
================================================================

URL:          ${1}
DB-Treiber:   ${2}
DB-Host:      ${3}:${4:-Standard}
DB-Name:      ${5}
DB-User:      ${6}
DB-Passwort:  ${7}
Nginx-Config: ${8}
INFO
  chmod 600 "${info_path}"
}

# ---------------------------------------------------------------------------
# Systemd / OpenRC unit writers
# ---------------------------------------------------------------------------

write_panel_messenger_service() {
  local php_bin="$1" console="$2"
  if has_systemctl; then
    cat > /etc/systemd/system/easywi-messenger.service <<UNIT
[Unit]
Description=EasyWI Symfony Messenger Worker
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart=${php_bin} ${console} messenger:consume async --time-limit=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT
    systemctl daemon-reload
    service_enable_start easywi-messenger.service
  fi
}

write_agent_service() {
  local instance_base_dir="$1" sftp_base_dir="$2"

  if has_systemctl; then
    cat > /etc/systemd/system/easywi-agent.service <<UNIT
[Unit]
Description=EasyWI Agent
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
Environment=EASYWI_INSTANCE_BASE_DIR=${instance_base_dir}
Environment=EASYWI_SFTP_BASE_DIR=${sftp_base_dir}
ExecStart=/usr/local/bin/easywi-agent --config /etc/easywi/agent.conf
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT
    systemctl daemon-reload

  elif has_openrc; then
    cat > /etc/init.d/easywi-agent <<INIT
#!/sbin/openrc-run
description="EasyWI Agent"
command="/usr/local/bin/easywi-agent"
command_args="--config /etc/easywi/agent.conf"
command_background=true
pidfile="/run/easywi-agent.pid"
export EASYWI_INSTANCE_BASE_DIR="${instance_base_dir}"
export EASYWI_SFTP_BASE_DIR="${sftp_base_dir}"
INIT
    chmod +x /etc/init.d/easywi-agent
    rc-update add easywi-agent default 2>/dev/null || true
  fi
}

# ---------------------------------------------------------------------------
# Release / binary download
# ---------------------------------------------------------------------------

download_release_asset() {
  local asset="$1" dest="$2" version="${3:-latest}"
  local base
  if [[ -n "${version}" && "${version}" != "latest" ]]; then
    base="${EASYWI_GITHUB_RELEASE_BASE}/download/${version}"
  else
    base="${EASYWI_GITHUB_RELEASE_BASE}/latest/download"
  fi
  log "Download: ${base}/${asset}"
  curl -fsSL "${base}/${asset}" -o "${dest}" \
    || fatal "Asset nicht verfügbar: ${asset}"
}

download_optional_asset() {
  local asset="$1" dest="$2" version="${3:-latest}"
  local base
  if [[ -n "${version}" && "${version}" != "latest" ]]; then
    base="${EASYWI_GITHUB_RELEASE_BASE}/download/${version}"
  else
    base="${EASYWI_GITHUB_RELEASE_BASE}/latest/download"
  fi
  curl -fsSL "${base}/${asset}" -o "${dest}" 2>/dev/null && return 0
  rm -f "${dest}"; return 1
}

install_agent_binaries() {
  local arch="$1" version="${2:-latest}"
  local tmp; tmp="$(mktemp -d)"
  trap 'rm -rf "${tmp}"' RETURN

  step "Lade Agent-Binaries (${arch}, ${version})."

  # Agent binary
  local agent_dest="${tmp}/agent-raw"
  local agent_resolved=""
  for suffix in ".tar.gz" ".zip" ""; do
    local asset_name="easywi-agent-${arch}${suffix}"
    if download_optional_asset "${asset_name}" "${agent_dest}" "${version}"; then
      case "${suffix}" in
        .tar.gz) tar -xzf "${agent_dest}" -C "${tmp}" ;;
        .zip)    command -v unzip >/dev/null || fatal "unzip fehlt"; unzip -oq "${agent_dest}" -d "${tmp}" ;;
        *)       cp "${agent_dest}" "${tmp}/easywi-agent-${arch}" ;;
      esac
      agent_resolved="${tmp}/easywi-agent-${arch}"
      break
    fi
  done
  [[ -n "${agent_resolved}" && -f "${agent_resolved}" ]] \
    || fatal "Kein Agent-Binary für ${arch} gefunden."

  # Wrapper binary (optional)
  local wrapper_dest="${tmp}/wrapper-raw"
  local wrapper_resolved=""
  for suffix in ".tar.gz" ".zip" ""; do
    local asset_name="easywi-wrapper-${arch}${suffix}"
    if download_optional_asset "${asset_name}" "${wrapper_dest}" "${version}"; then
      case "${suffix}" in
        .tar.gz) tar -xzf "${wrapper_dest}" -C "${tmp}" ;;
        .zip)    unzip -oq "${wrapper_dest}" -d "${tmp}" ;;
        *)       cp "${wrapper_dest}" "${tmp}/easywi-wrapper-${arch}" ;;
      esac
      wrapper_resolved="${tmp}/easywi-wrapper-${arch}"
      break
    fi
  done

  install -m 0755 "${agent_resolved}" /usr/local/bin/easywi-agent
  ok "easywi-agent installiert: /usr/local/bin/easywi-agent"

  if [[ -n "${wrapper_resolved}" && -f "${wrapper_resolved}" ]]; then
    install -m 0755 "${wrapper_resolved}" /usr/local/bin/easywi-wrapper
    ok "easywi-wrapper installiert: /usr/local/bin/easywi-wrapper"
  else
    warn "Kein easywi-wrapper für diese Version gefunden – wird übersprungen."
  fi

  command -v easywi-agent >/dev/null || fatal "easywi-agent nicht im PATH nach Installation."
}

# ---------------------------------------------------------------------------
# Agent base dir helpers
# ---------------------------------------------------------------------------

build_agent_base_dir_config() {
  local input="${1:-/home,/var/www}"
  local primary="" joined="" dir
  local -a dirs=()
  IFS=',' read -ra raw <<< "${input}"
  for raw_dir in "${raw[@]}"; do
    dir="${raw_dir#"${raw_dir%%[! ]*}"}"; dir="${dir%"${dir##*[! ]}"}"
    [[ -z "${dir}" ]] && continue
    [[ "${dir}" == /* ]] || fatal "Verzeichnis muss absolut sein: ${dir}"
    [[ -z "${primary}" ]] && primary="${dir}"
    local dup=false; local d
    for d in "${dirs[@]+"${dirs[@]}"}"; do [[ "${d}" == "${dir}" ]] && dup=true && break; done
    "${dup}" || dirs+=("${dir}")
  done
  [[ -z "${primary}" ]] && primary="/home" && dirs=("/home" "/var/www")
  for dir in "${dirs[@]}"; do joined="${joined:+${joined},}${dir}"; done
  printf '%s\n%s\n' "${primary}" "${joined}"
}

detect_default_base_dirs() {
  local dirs_csv=""
  if command -v findmnt >/dev/null 2>&1; then
    local m
    while IFS= read -r m; do
      m="${m#"${m%%[! ]*}"}"; m="${m%"${m##*[! ]}"}"
      [[ -z "${m}" ]] && continue
      case "${m}" in /|/boot*|/run*|/proc|/sys|/dev*|/snap|/var/lib/docker) continue ;; esac
      dirs_csv="${dirs_csv:+${dirs_csv},}${m}"
    done < <(findmnt -rn -o TARGET -t ext4,xfs,btrfs,zfs 2>/dev/null)
  fi
  printf '%s\n' "${dirs_csv:-/home,/var/www}"
}

detect_local_ipv4() {
  command -v ip >/dev/null && ip -o -4 addr show scope global 2>/dev/null \
    | awk '{print $4}' | cut -d/ -f1 | paste -sd, - && return
  printf '\n'
}

# ---------------------------------------------------------------------------
# Agent registration
# ---------------------------------------------------------------------------

build_sig_payload() {
  local agent_id="$1" method="$2" path="$3" ts="$4" nonce="$5" body="$6"
  local bh; bh="$(sha256_hex "${body}")"
  printf '%s\n%s\n%s\n%s\n%s\n%s' "${agent_id}" "${method}" "${path}" "${bh}" "${ts}" "${nonce}"
}

register_agent_with_core() {
  local core_url="$1" bootstrap_token="$2" version="$3" name="$4" hostname="$5" state_file="$6"
  [[ -z "${hostname}" ]] && hostname="$(hostname -f 2>/dev/null || hostname)"

  local payload; payload="$(jq -n \
    --arg t "${bootstrap_token}" --arg h "${hostname}" \
    --arg o "linux" --arg v "${version}" \
    '{bootstrap_token:$t,hostname:$h,os:$o,agent_version:$v}')"

  step "Bootstrap-Registrierung am Core (${core_url})."
  local resp code
  resp="$(curl -sS -w '\n%{http_code}' -X POST \
    "${core_url}/api/v1/agent/bootstrap" \
    -H "Content-Type: application/json" -d "${payload}")"
  code="${resp##*$'\n'}"; resp="${resp%$'\n'*}"
  [[ "${code}" == "200" ]] || fatal "Bootstrap fehlgeschlagen (HTTP ${code}): ${resp}"

  local reg_url reg_token agent_id
  reg_url="$(jq -r '.register_url // empty' <<< "${resp}")"
  reg_token="$(jq -r '.register_token // empty' <<< "${resp}")"
  agent_id="$(jq -r '.agent_id // empty' <<< "${resp}")"
  [[ -n "${reg_url}" && -n "${reg_token}" && -n "${agent_id}" ]] \
    || fatal "Ungültige Bootstrap-Antwort."

  # Persist state so installer can retry without re-bootstrapping
  if [[ -n "${state_file}" ]]; then
    mkdir -p "$(dirname "${state_file}")"
    jq -n --arg u "${reg_url}" --arg t "${reg_token}" \
       --arg a "${agent_id}" --arg b "${bootstrap_token}" \
      '{register_url:$u,register_token:$t,agent_id:$a,bootstrap_token:$b}' \
      > "${state_file}"
    chmod 600 "${state_file}"
  fi

  local agent_secret
  agent_secret="$(complete_agent_registration "${reg_url}" "${reg_token}" "${agent_id}" "${name}")"
  printf '%s|%s' "${agent_id}" "${agent_secret}"
}

complete_agent_registration() {
  local reg_url="$1" reg_token="$2" agent_id="$3" agent_name="$4"
  local payload; payload="$(jq -n \
    --arg a "${agent_id}" --arg t "${reg_token}" --arg n "${agent_name}" \
    '{agent_id:$a,register_token:$t,name:$n}')"

  local ts nonce path sig
  ts="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"
  nonce="$(random_hex 8)"
  path="$(printf '%s' "${reg_url}" | sed -E 's#https?://[^/]+##; s#/+$##')"
  [[ -z "${path}" ]] && path="/"
  sig="$(hmac_sha256_hex "${reg_token}" "$(build_sig_payload "${agent_id}" "POST" "${path}" "${ts}" "${nonce}" "${payload}")")"

  step "Registrierung abschließen."
  local resp code
  resp="$(curl -sS -w '\n%{http_code}' -X POST "${reg_url}" \
    -H "Content-Type: application/json" \
    -H "X-Agent-ID: ${agent_id}" \
    -H "X-Timestamp: ${ts}" \
    -H "X-Nonce: ${nonce}" \
    -H "X-Signature: ${sig}" \
    -d "${payload}")"
  code="${resp##*$'\n'}"; resp="${resp%$'\n'*}"
  [[ "${code}" == "200" || "${code}" == "201" ]] || fatal "Registrierung fehlgeschlagen (HTTP ${code}): ${resp}"

  local secret; secret="$(jq -r '.secret // empty' <<< "${resp}")"
  [[ -n "${secret}" ]] || fatal "Kein Secret in Registrierungsantwort."
  printf '%s' "${secret}"
}

write_agent_conf() {
  local agent_id="$1" secret="$2" api_url="$3"
  local file_base_dir="$4" sftp_base_dir="$5" bind_ips="$6"

  mapfile -t bdc < <(build_agent_base_dir_config "${file_base_dir}")
  local primary="${bdc[0]}" all_dirs="${bdc[1]}"

  mkdir -p /etc/easywi
  cat > /etc/easywi/agent.conf <<CONF
agent_id=${agent_id}
secret=${secret}
api_url=${api_url}
service_listen=0.0.0.0:7456
file_base_dir=${primary}
file_base_dirs=${all_dirs}
bind_ip_addresses=${bind_ips}
CONF
  chmod 600 /etc/easywi/agent.conf
  ok "Agent-Konfiguration geschrieben: /etc/easywi/agent.conf"
}

write_agent_conf_placeholder() {
  mkdir -p /etc/easywi
  [[ -f /etc/easywi/agent.conf ]] && return
  cat > /etc/easywi/agent.conf <<CONF
# EasyWI Agent – Konfiguration
# Nach Registrierung im Panel hier ausfüllen:
# agent_id=<ID>
# secret=<SECRET>
# api_url=https://panel.example.com
# service_listen=0.0.0.0:7456
# file_base_dir=/home
# file_base_dirs=/home,/var/www
CONF
  chmod 600 /etc/easywi/agent.conf
}

prepare_agent_dirs() {
  mkdir -p /etc/easywi /opt/easywi/templates /opt/easywi/instances \
           /opt/sinusbot/instances /var/lib/easywi/sftp
}

# ---------------------------------------------------------------------------
# Panel install core logic
# ---------------------------------------------------------------------------

install_panel() {
  local install_dir="$1" repo_url="$2" repo_ref="$3"
  local db_driver="$4" db_system="$5" db_root_pw="$6"
  local db_host="$7" db_port="$8" db_name="$9" db_user="${10}" db_password="${11}"
  local php_version="${12}" web_hostname="${13}"
  local system_user="${14}" app_secret="${15}" enc_keys="${16}"
  local reg_token="${17}" github_token="${18}"
  local run_migrations="${19}" web_scheme="${20}" provision_db="${21}"

  local family manager
  family="$(detect_os_family)"
  manager="$(detect_package_manager)"

  log "Erkanntes OS-Family: ${family} | Paketmanager: ${manager}"

  step "Richte PHP-Repositories ein."
  setup_php_repo "${manager}" "${family}" "${php_version}"
  php_version="$(resolve_php_version "${php_version}" "${manager}" "${family}")"

  resolve_php_packages  "${manager}" "${php_version}" "${db_system}"
  resolve_db_packages   "${manager}" "${db_system}"

  local base_pkgs=(git curl ca-certificates unzip openssl jq)
  base_pkgs+=("$(nginx_packages "${manager}")")

  install_packages "${manager}" "${base_pkgs[@]}" "${PHP_BASE_PKGS[@]}" "${DB_PKGS[@]}"

  # Find PHP binary
  local php_bin="php${php_version}"
  command -v "${php_bin}" >/dev/null 2>&1 || php_bin="php"
  command -v "${php_bin}" >/dev/null 2>&1 || fatal "PHP-Binary nicht gefunden."

  install_composer "${php_bin}"

  # Start services
  service_enable_start "${PHP_FPM_SERVICE}"
  service_enable_start nginx
  for svc in "${DB_SERVICES[@]}"; do
    start_first_available_service "${svc}" && break
  done

  ensure_system_user "${system_user}" "${install_dir}"

  # Database
  if [[ "${provision_db}" == "true" ]]; then
    case "${db_system}" in
      mariadb|mysql)  setup_database_mysql "${db_name}" "${db_user}" "${db_password}" "${db_root_pw}" ;;
      postgresql)     setup_database_postgresql "${db_name}" "${db_user}" "${db_password}" ;;
    esac
  else
    warn "DB-Provisionierung übersprungen – bestehende DB wird verwendet."
  fi

  # Source code
  step "Lade Panel-Quellcode."
  if [[ -d "${install_dir}/.git" ]]; then
    ensure_git_safe_dir "${install_dir}"
    git -C "${install_dir}" fetch --all --tags
    git -C "${install_dir}" checkout "${repo_ref}"
    git -C "${install_dir}" pull --ff-only
    ok "Repository aktualisiert."
  elif [[ -d "${install_dir}" && -n "$(ls -A "${install_dir}" 2>/dev/null)" ]]; then
    if prompt_yes_no "${install_dir} ist nicht leer. Inhalt löschen?" "no"; then
      (shopt -s dotglob nullglob; rm -rf "${install_dir:?}/"*)
      git clone "${repo_url}" "${install_dir}"
    else
      fatal "Installationsverzeichnis nicht leer und kein Git-Repo."
    fi
  else
    git clone "${repo_url}" "${install_dir}"
  fi
  ensure_git_safe_dir "${install_dir}"
  git -C "${install_dir}" checkout "${repo_ref}"

  local core_dir="${install_dir}/core"
  [[ -d "${core_dir}" ]] || fatal "core/-Verzeichnis nicht gefunden: ${core_dir}"

  step "Installiere Composer-Abhängigkeiten."
  COMPOSER_ALLOW_SUPERUSER=1 composer install \
    --no-dev --optimize-autoloader --working-dir "${core_dir}" --quiet

  [[ -z "${app_secret}" ]]  && app_secret="$(random_hex 32)"
  [[ -z "${enc_keys}" ]]    && enc_keys="$(random_base64 32)"

  local default_uri="${web_scheme}://${web_hostname}"
  [[ "${web_hostname}" == "_" ]] && default_uri="${web_scheme}://localhost"

  write_env_local "${core_dir}/.env.local" \
    "${db_driver}" "${db_host}" "${db_port}" "${db_name}" "${db_user}" "${db_password}" \
    "${app_secret}" "${enc_keys}" "${reg_token}" "${default_uri}" "${install_dir}"

  [[ -n "${github_token}" ]] && printf 'APP_GITHUB_TOKEN="%s"\n' "${github_token}" >> "${core_dir}/.env.local"

  step "Setze Dateiberechtigungen."
  chown -R "${system_user}:${system_user}" "${install_dir}"
  # nginx/web process needs to write var/ and read public/
  local web_group; web_group="$(id -gn www-data 2>/dev/null || id -gn nginx 2>/dev/null || echo "${system_user}")"
  chown -R ":${web_group}" "${core_dir}/var" "${core_dir}/public" 2>/dev/null || true
  find "${core_dir}/var" -type d -exec chmod 775 {} \; 2>/dev/null || true

  configure_nginx "${family}" "${web_hostname}" "${core_dir}/public" "${PHP_FPM_SOCKET}"

  if [[ "${run_migrations}" == "true" ]]; then
    step "Führe Datenbankmigrationen aus."
    "${php_bin}" "${core_dir}/bin/console" doctrine:migrations:migrate \
      --no-interaction --allow-no-migration 2>&1 | tail -5 || warn "Migrationen lieferten Fehler – prüfen."
    "${php_bin}" "${core_dir}/bin/console" cache:clear --env=prod --quiet
    ok "Migrationen abgeschlossen."
  else
    warn "Migrationen übersprungen."
  fi

  write_install_info "${install_dir}/INSTALLATION_INFO.txt" \
    "${default_uri}" "${db_driver}" "${db_host}" "${db_port:-Standard}" \
    "${db_name}" "${db_user}" "${db_password}" \
    "$(detect_nginx_conf_dir "${family}")/easywi.conf"

  write_panel_messenger_service "${php_bin}" "${core_dir}/bin/console"

  ok "Panel installiert unter ${install_dir}."
  printf '\n'
  printf '  ╔══════════════════════════════════════╗\n'
  printf '  ║  Panel erreichbar unter: %-13s║\n' "${default_uri}"
  printf '  ║  Zugangsdaten: %s/INSTALLATION_INFO.txt  ║\n' ""
  printf '  ╚══════════════════════════════════════╝\n'
}

# ---------------------------------------------------------------------------
# Agent install core logic
# ---------------------------------------------------------------------------

install_agent() {
  # $1 core_url        – URL für Bootstrap-HTTP-Call (und api_url wenn kein Override)
  # $2 bootstrap_token – Token aus dem Panel
  # $3 agent_version
  # $4 file_base_dir
  # $5 sftp_base_dir
  # $6 agent_name
  # $7 agent_hostname
  # $8 bind_ips
  # $9 api_url_override – (optional) echte Panel-URL für agent.conf wenn Bootstrap über localhost läuft
  local core_url="$1" bootstrap_token="$2" agent_version="$3"
  local file_base_dir="$4" sftp_base_dir="$5"
  local agent_name="$6" agent_hostname="$7" bind_ips="$8"
  local api_url_override="${9:-}"
  local state_file="${EASYWI_BOOTSTRAP_STATE_FILE:-/etc/easywi/bootstrap-state.json}"

  # URL die in agent.conf als api_url landet – echte Panel-URL, nicht 127.0.0.1
  local api_url="${api_url_override:-${core_url}}"

  local manager; manager="$(detect_package_manager)"
  install_packages "${manager}" ca-certificates curl openssl jq

  local arch; arch="$(detect_arch_suffix)"
  install_agent_binaries "${arch}" "${agent_version}"
  prepare_agent_dirs

  mapfile -t bdc < <(build_agent_base_dir_config "${file_base_dir}")
  local primary_base="${bdc[0]}"

  if [[ -z "${core_url}" ]]; then
    warn "Kein Core-URL angegeben – nur Binaries installiert, keine Registrierung."
    write_agent_conf_placeholder
    write_agent_service "${primary_base}" "${sftp_base_dir}"
    if has_systemctl; then
      systemctl daemon-reload
      systemctl enable easywi-agent.service
    fi
    return
  fi

  [[ -z "${agent_name}" ]] && agent_name="$(hostname -f 2>/dev/null || hostname)"

  local agent_id="" agent_secret=""

  # Gespeicherten Bootstrap-State wiederverwenden falls vorhanden
  if [[ -f "${state_file}" && -z "${bootstrap_token}" ]]; then
    local st_url st_token st_id
    st_url="$(jq -r '.register_url // empty' < "${state_file}")"
    st_token="$(jq -r '.register_token // empty' < "${state_file}")"
    st_id="$(jq -r '.agent_id // empty' < "${state_file}")"
    if [[ -n "${st_url}" && -n "${st_token}" && -n "${st_id}" ]]; then
      log "Verwende gespeicherten Bootstrap-State."
      if agent_secret="$(complete_agent_registration "${st_url}" "${st_token}" "${st_id}" "${agent_name}")"; then
        agent_id="${st_id}"
      else
        warn "Gespeicherter State ungültig – versuche neuen Bootstrap."
        rm -f "${state_file}"
      fi
    fi
  fi

  if [[ -z "${agent_id}" ]]; then
    [[ -z "${bootstrap_token}" ]] && fatal "Bootstrap-Token fehlt. Bitte EASYWI_BOOTSTRAP_TOKEN setzen oder im Installer angeben."
    local identity
    identity="$(register_agent_with_core "${core_url}" "${bootstrap_token}" \
      "${agent_version}" "${agent_name}" "${agent_hostname}" "${state_file}")"
    agent_id="${identity%%|*}"
    agent_secret="${identity#*|}"
  fi

  # agent.conf bekommt die echte Panel-URL (api_url), nicht die Bootstrap-URL (127.0.0.1)
  write_agent_conf "${agent_id}" "${agent_secret}" "${api_url}" \
    "${file_base_dir}" "${sftp_base_dir}" "${bind_ips}"
  write_agent_service "${primary_base}" "${sftp_base_dir}"

  if has_systemctl; then
    systemctl daemon-reload
    systemctl enable --now easywi-agent.service
  elif has_openrc; then
    rc-service easywi-agent start || true
  fi

  rm -f "${state_file}"

  ok "Agent installiert und gestartet."
  printf '\n  Agent-ID: %s\n  Config:   /etc/easywi/agent.conf\n' "${agent_id}"
}

# ---------------------------------------------------------------------------
# Agent update (binary only, keep config + service)
# ---------------------------------------------------------------------------

update_agent() {
  local agent_version="${1:-latest}"
  local arch; arch="$(detect_arch_suffix)"

  step "Aktualisiere Agent-Binaries (${arch}, ${agent_version})."
  local was_active=false
  service_is_active easywi-agent && was_active=true

  if "${was_active}"; then
    log "Stoppe laufenden Agent."
    if has_systemctl; then
      systemctl stop easywi-agent.service 2>/dev/null || true
    elif has_openrc; then
      rc-service easywi-agent stop 2>/dev/null || true
    fi
  fi

  install_agent_binaries "${arch}" "${agent_version}"

  if has_systemctl; then
    systemctl daemon-reload
  fi

  if "${was_active}"; then
    log "Starte Agent neu."
    service_enable_start easywi-agent
  fi

  local installed_ver="unknown"
  installed_ver="$(/usr/local/bin/easywi-agent --version 2>/dev/null | head -n1 || echo "unknown")"
  ok "Agent aktualisiert. Version: ${installed_ver}"
}

# ---------------------------------------------------------------------------
# Menus
# ---------------------------------------------------------------------------

db_system_menu() {
  menu_output <<'MENU'

  Datenbanksystem:
    1) MariaDB   (empfohlen)
    2) MySQL
    3) PostgreSQL
MENU
  local c; c="$(menu_prompt "  Auswahl [1-3]: ")"
  case "${c}" in 1) echo "mariadb";; 2) echo "mysql";; 3) echo "postgresql";; *) echo "mariadb";; esac
}

run_panel_install() {
  menu_output <<'INFO'

  ╔══════════════════════════════════════════════════╗
  ║  Panel (Core) Installation                       ║
  ║  Installiert: PHP, Nginx, Composer, Symfony      ║
  ║  Quellcode von GitHub (Easy-Wi-NextGen)          ║
  ╚══════════════════════════════════════════════════╝

INFO

  local install_dir="${EASYWI_INSTALL_DIR:-/var/www/easywi}"
  local repo_url="${EASYWI_REPO_URL:-https://github.com/Matlord93/Easy-Wi-NextGen.git}"
  local repo_ref="${EASYWI_REPO_REF:-main}"
  local db_driver="${EASYWI_DB_DRIVER:-mysql}"
  local db_system="${EASYWI_DB_SYSTEM:-}"
  local db_root_pw="${EASYWI_DB_ROOT_PASSWORD:-}"
  local db_host="${EASYWI_DB_HOST:-127.0.0.1}"
  local db_port="${EASYWI_DB_PORT:-}"
  local db_name="${EASYWI_DB_NAME:-easywi}"
  local db_user="${EASYWI_DB_USER:-easywi}"
  local db_password="${EASYWI_DB_PASSWORD:-}"
  local php_version="${EASYWI_PHP_VERSION:-${DEFAULT_PHP_VERSION}}"
  local web_hostname="${EASYWI_WEB_HOSTNAME:-_}"
  local system_user="${EASYWI_SYSTEM_USER:-easywi}"
  local app_secret="${EASYWI_APP_SECRET:-}"
  local enc_keys="${EASYWI_SECRET_KEY:-}"
  local reg_token="${EASYWI_AGENT_REGISTRATION_TOKEN:-}"
  local github_token="${EASYWI_APP_GITHUB_TOKEN:-}"
  local run_migrations="${EASYWI_RUN_MIGRATIONS:-true}"
  local web_scheme="${EASYWI_WEB_SCHEME:-https}"
  local provision_db="${EASYWI_DB_PROVISION:-true}"

  prompt_value repo_url       "Git-Repository URL"                           "${repo_url}"
  prompt_value install_dir    "Installationsverzeichnis"                     "${install_dir}"
  prompt_value repo_ref       "Git-Branch oder Tag"                          "${repo_ref}"
  prompt_value system_user    "Linux-Systembenutzer für EasyWI"              "${system_user}"
  prompt_value php_version    "PHP-Version"                                  "${php_version}"
  prompt_value web_hostname   "Hostname/Domain (_ = alle)"                  "${web_hostname}"
  prompt_value web_scheme     "Schema (http/https)"                          "${web_scheme}"
  prompt_value provision_db   "Datenbank automatisch erstellen? (true/false)" "${provision_db}"

  if [[ -z "${db_system}" ]]; then
    is_tty && db_system="$(db_system_menu)" || db_system="mariadb"
  fi
  case "${db_system}" in
    mariadb|mysql) db_driver="mysql" ;;
    postgresql)    db_driver="pgsql" ;;
    *) fatal "Unbekanntes DB-System: ${db_system}" ;;
  esac

  prompt_value db_root_pw   "DB-Root-Passwort (leer = Socket-Auth)"    "${db_root_pw}"
  prompt_value db_host      "DB-Host"                                   "${db_host}"
  prompt_value db_port      "DB-Port (leer = Standard)"                "${db_port}"
  prompt_value db_name      "Datenbankname"                             "${db_name}"
  prompt_value db_user      "Datenbankbenutzer"                         "${db_user}"
  prompt_value db_password  "Datenbankpasswort"                         "${db_password}"
  prompt_value reg_token    "Agent-Registrierungstoken (optional)"     "${reg_token}"
  prompt_value github_token "GitHub-Token (optional)"                  "${github_token}"
  prompt_value run_migrations "Migrationen ausführen? (true/false)"    "${run_migrations}"

  [[ -z "${db_password}" ]] && fatal "Datenbankpasswort darf nicht leer sein."

  install_panel \
    "${install_dir}" "${repo_url}" "${repo_ref}" \
    "${db_driver}" "${db_system}" "${db_root_pw}" \
    "${db_host}" "${db_port}" "${db_name}" "${db_user}" "${db_password}" \
    "${php_version}" "${web_hostname}" \
    "${system_user}" "${app_secret}" "${enc_keys}" \
    "${reg_token}" "${github_token}" \
    "${run_migrations}" "${web_scheme}" "${provision_db}"
}

run_agent_install() {
  menu_output <<'INFO'

  ╔══════════════════════════════════════════════════╗
  ║  Agent Installation                              ║
  ║  Lädt Agent-Binary, konfiguriert Systemd-Service ║
  ║  Optional: Registrierung am Panel                ║
  ╚══════════════════════════════════════════════════╝

INFO

  local core_url="${EASYWI_CORE_URL:-${EASYWI_API_URL:-}}"
  local bootstrap_token="${EASYWI_BOOTSTRAP_TOKEN:-}"
  local agent_version="${EASYWI_AGENT_VERSION:-latest}"
  local file_base_dir="${EASYWI_FILE_BASE_DIR:-}"
  local sftp_base_dir="${EASYWI_SFTP_BASE_DIR:-/var/lib/easywi/sftp}"
  local agent_name="${EASYWI_AGENT_NAME:-}"
  local agent_hostname="${EASYWI_AGENT_HOSTNAME:-}"
  local bind_ips="${EASYWI_BIND_IP_ADDRESSES:-}"

  [[ -z "${file_base_dir}" ]] && file_base_dir="$(detect_default_base_dirs)"
  [[ -z "${bind_ips}" ]]      && bind_ips="$(detect_local_ipv4)"

  prompt_value core_url        "Panel API URL (leer = nur Binary)"    "${core_url}"
  prompt_value bootstrap_token "Bootstrap-Token"                      "${bootstrap_token}"
  prompt_value agent_version   "Agent-Version (latest oder Tag)"      "${agent_version}"
  prompt_value file_base_dir   "Dateiverzeichnisse (kommagetrennt)"   "${file_base_dir}"
  prompt_value sftp_base_dir   "SFTP-Basisverzeichnis"                "${sftp_base_dir}"
  prompt_value bind_ips        "Bind-IP-Adressen (optional)"          "${bind_ips}"
  prompt_value agent_name      "Agent-Name (optional)"                "${agent_name}"
  prompt_value agent_hostname  "Agent-Hostname (optional)"            "${agent_hostname}"

  [[ -n "${core_url}" ]] && core_url="${core_url%/}"

  install_agent \
    "${core_url}" "${bootstrap_token}" "${agent_version}" \
    "${file_base_dir}" "${sftp_base_dir}" \
    "${agent_name}" "${agent_hostname}" "${bind_ips}"
}

run_agent_update() {
  menu_output <<'INFO'

  ╔══════════════════════════════════════════════════╗
  ║  Agent aktualisieren                             ║
  ║  Lädt neue Binary, behält Konfiguration          ║
  ╚══════════════════════════════════════════════════╝

INFO

  local agent_version="${EASYWI_AGENT_VERSION:-latest}"
  prompt_value agent_version "Agent-Version (latest oder Tag)" "${agent_version}"

  local manager; manager="$(detect_package_manager)"
  install_packages "${manager}" ca-certificates curl openssl jq

  update_agent "${agent_version}"
}

run_both_install() {
  menu_output <<'INFO'

  ╔══════════════════════════════════════════════════╗
  ║  Panel (Core) + Agent – Gemeinsame Installation  ║
  ║                                                  ║
  ║  Ablauf:                                         ║
  ║    1) Alle Parameter abfragen                    ║
  ║    2) Panel installieren                         ║
  ║    3) Agent automatisch registrieren             ║
  ║       (kein manueller Token nötig)               ║
  ╚══════════════════════════════════════════════════╝

INFO

  # ── Panel-Parameter ────────────────────────────────────────────────────
  local install_dir="${EASYWI_INSTALL_DIR:-/var/www/easywi}"
  local repo_url="${EASYWI_REPO_URL:-https://github.com/Matlord93/Easy-Wi-NextGen.git}"
  local repo_ref="${EASYWI_REPO_REF:-main}"
  local db_driver="${EASYWI_DB_DRIVER:-mysql}"
  local db_system="${EASYWI_DB_SYSTEM:-}"
  local db_root_pw="${EASYWI_DB_ROOT_PASSWORD:-}"
  local db_host="${EASYWI_DB_HOST:-127.0.0.1}"
  local db_port="${EASYWI_DB_PORT:-}"
  local db_name="${EASYWI_DB_NAME:-easywi}"
  local db_user="${EASYWI_DB_USER:-easywi}"
  local db_password="${EASYWI_DB_PASSWORD:-}"
  local php_version="${EASYWI_PHP_VERSION:-${DEFAULT_PHP_VERSION}}"
  local web_hostname="${EASYWI_WEB_HOSTNAME:-_}"
  local system_user="${EASYWI_SYSTEM_USER:-easywi}"
  local app_secret="${EASYWI_APP_SECRET:-}"
  local enc_keys="${EASYWI_SECRET_KEY:-}"
  local github_token="${EASYWI_APP_GITHUB_TOKEN:-}"
  local run_migrations="${EASYWI_RUN_MIGRATIONS:-true}"
  local web_scheme="${EASYWI_WEB_SCHEME:-https}"
  local provision_db="${EASYWI_DB_PROVISION:-true}"

  # ── Agent-Parameter ─────────────────────────────────────────────────────
  local agent_version="${EASYWI_AGENT_VERSION:-latest}"
  local file_base_dir="${EASYWI_FILE_BASE_DIR:-}"
  local sftp_base_dir="${EASYWI_SFTP_BASE_DIR:-/var/lib/easywi/sftp}"
  local agent_name="${EASYWI_AGENT_NAME:-}"
  local agent_hostname="${EASYWI_AGENT_HOSTNAME:-}"
  local bind_ips="${EASYWI_BIND_IP_ADDRESSES:-}"

  [[ -z "${file_base_dir}" ]] && file_base_dir="$(detect_default_base_dirs)"
  [[ -z "${bind_ips}" ]]      && bind_ips="$(detect_local_ipv4)"

  # ── Panel-Fragen ─────────────────────────────────────────────────────────
  menu_output <<'HDR'

  ── Panel-Einstellungen ──────────────────────────────
HDR
  prompt_value repo_url       "Git-Repository URL"                            "${repo_url}"
  prompt_value install_dir    "Installationsverzeichnis"                      "${install_dir}"
  prompt_value repo_ref       "Git-Branch oder Tag"                           "${repo_ref}"
  prompt_value system_user    "Linux-Systembenutzer für EasyWI"               "${system_user}"
  prompt_value php_version    "PHP-Version"                                   "${php_version}"
  prompt_value web_hostname   "Hostname/Domain (_ = alle)"                   "${web_hostname}"
  prompt_value web_scheme     "Schema (http/https)"                           "${web_scheme}"
  prompt_value provision_db   "Datenbank automatisch erstellen? (true/false)" "${provision_db}"

  if [[ -z "${db_system}" ]]; then
    is_tty && db_system="$(db_system_menu)" || db_system="mariadb"
  fi
  case "${db_system}" in
    mariadb|mysql) db_driver="mysql" ;;
    postgresql)    db_driver="pgsql" ;;
    *) fatal "Unbekanntes DB-System: ${db_system}" ;;
  esac

  prompt_value db_root_pw     "DB-Root-Passwort (leer = Socket-Auth)"    "${db_root_pw}"
  prompt_value db_host        "DB-Host"                                   "${db_host}"
  prompt_value db_port        "DB-Port (leer = Standard)"                "${db_port}"
  prompt_value db_name        "Datenbankname"                             "${db_name}"
  prompt_value db_user        "Datenbankbenutzer"                         "${db_user}"
  prompt_value db_password    "Datenbankpasswort"                         "${db_password}"
  prompt_value github_token   "GitHub-Token (optional)"                   "${github_token}"
  prompt_value run_migrations "Migrationen ausführen? (true/false)"       "${run_migrations}"

  [[ -z "${db_password}" ]] && fatal "Datenbankpasswort darf nicht leer sein."

  # ── Agent-Fragen ──────────────────────────────────────────────────────────
  menu_output <<'HDR'

  ── Agent-Einstellungen ──────────────────────────────
HDR
  prompt_value agent_version  "Agent-Version (latest oder Tag)"          "${agent_version}"
  prompt_value file_base_dir  "Dateiverzeichnisse (kommagetrennt)"       "${file_base_dir}"
  prompt_value sftp_base_dir  "SFTP-Basisverzeichnis"                    "${sftp_base_dir}"
  prompt_value bind_ips       "Bind-IP-Adressen (optional)"              "${bind_ips}"
  prompt_value agent_name     "Agent-Name (optional)"                    "${agent_name}"
  prompt_value agent_hostname "Agent-Hostname (optional)"                "${agent_hostname}"

  # ── Registrierungs-Token automatisch generieren ───────────────────────────
  # Der Token wird in Panel-.env.local UND für den Agent-Bootstrap verwendet.
  # Kein manueller Schritt nötig.
  local reg_token="${EASYWI_AGENT_REGISTRATION_TOKEN:-}"
  if [[ -z "${reg_token}" ]]; then
    reg_token="$(random_hex 32)"
    log "Auto-generierter Registrierungstoken (wird in .env.local gespeichert)."
  fi

  # ── Schritt 1: Panel installieren ────────────────────────────────────────
  menu_output <<'HDR'

  ── Starte Panel-Installation ────────────────────────
HDR
  install_panel \
    "${install_dir}" "${repo_url}" "${repo_ref}" \
    "${db_driver}" "${db_system}" "${db_root_pw}" \
    "${db_host}" "${db_port}" "${db_name}" "${db_user}" "${db_password}" \
    "${php_version}" "${web_hostname}" \
    "${system_user}" "${app_secret}" "${enc_keys}" \
    "${reg_token}" "${github_token}" \
    "${run_migrations}" "${web_scheme}" "${provision_db}"

  # ── Schritt 2: Agent gegen das soeben installierte Panel registrieren ────
  # Bootstrap-Call geht immer gegen 127.0.0.1 – kein DNS nötig, Panel läuft bereits.
  # Die echte Panel-URL kommt in agent.conf als api_url.
  local bootstrap_url="http://127.0.0.1"
  local real_panel_url="${web_scheme}://${web_hostname}"
  [[ "${web_hostname}" == "_" ]] && real_panel_url="http://127.0.0.1"

  menu_output <<'HDR'

  ── Starte Agent-Installation & Registrierung ────────
HDR
  log "Bootstrap via: ${bootstrap_url}  |  api_url in agent.conf: ${real_panel_url}"

  install_agent \
    "${bootstrap_url}" "${reg_token}" "${agent_version}" \
    "${file_base_dir}" "${sftp_base_dir}" \
    "${agent_name}" "${agent_hostname}" "${bind_ips}" \
    "${real_panel_url}"

  # ── Abschlussmeldung ─────────────────────────────────────────────────────
  printf '\n'
  printf '  ╔══════════════════════════════════════════════════╗\n'
  printf '  ║  Installation abgeschlossen!                     ║\n'
  printf '  ║                                                  ║\n'
  printf '  ║  Panel: %-41s║\n'  "${real_panel_url}"
  printf '  ║  Agent: /etc/easywi/agent.conf                   ║\n'
  printf '  ║  Info:  %s/INSTALLATION_INFO.txt        ║\n' "${install_dir}"
  printf '  ╚══════════════════════════════════════════════════╝\n'
}

# ---------------------------------------------------------------------------
# Main menu
# ---------------------------------------------------------------------------

print_banner() {
  menu_output <<BANNER

  ╔══════════════════════════════════════════════════╗
  ║        EasyWI Linux Installer v${VERSION}              ║
  ║  Unterstützte Distros:                           ║
  ║    Debian · Ubuntu · RHEL · CentOS · Rocky       ║
  ║    AlmaLinux · Fedora · openSUSE · SLES          ║
  ║    Arch · Manjaro · Alpine · Amazon Linux        ║
  ╚══════════════════════════════════════════════════╝

BANNER
}

main_menu() {
  menu_output <<'MENU'
  Was möchten Sie tun?

    1) Panel (Core) installieren
    2) Agent installieren
    3) Panel + Agent installieren
    4) Agent aktualisieren
    5) Beenden

MENU
  local choice; choice="$(menu_prompt "  Auswahl [1-5]: ")"
  case "${choice}" in
    1) run_panel_install ;;
    2) run_agent_install ;;
    3) run_both_install  ;;
    4) run_agent_update  ;;
    *) log "Installation beendet."; exit 0 ;;
  esac
}

# ---------------------------------------------------------------------------
# Entrypoint
# ---------------------------------------------------------------------------

require_root
print_banner
main_menu
