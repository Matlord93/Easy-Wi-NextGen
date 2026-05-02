#!/usr/bin/env bash
set -euo pipefail

VERSION="0.1.0"

LOG_PREFIX="[easywi-installer-menu]"
STEP_COUNTER=0
DEFAULT_PHP_VERSION="8.4"
APT_UPDATED=0

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

read_from_tty() {
  local prompt="${1:-}"
  local value=""

  if [[ -e /dev/tty ]]; then
    if [[ -n "${prompt}" ]]; then
      printf '%s' "${prompt}" >/dev/tty
    fi
    IFS= read -r value </dev/tty || true
  fi

  echo "${value}"
}

menu_output() {
  if [[ -e /dev/tty ]]; then
    cat >/dev/tty
  else
    cat
  fi
}

menu_prompt() {
  local prompt="$1"
  read_from_tty "${prompt}"
}

require_command() {
  local cmd="$1"
  if ! command -v "${cmd}" >/dev/null 2>&1; then
    fatal "Benötigtes Kommando fehlt: ${cmd}"
  fi
}

ensure_git_safe_dir() {
  local repo_dir="$1"
  if ! git config --global --get-all safe.directory | grep -Fxq "${repo_dir}"; then
    git config --global --add safe.directory "${repo_dir}"
  fi
}

detect_package_manager() {
  if command -v apt-get >/dev/null 2>&1; then
    echo "apt"
    return
  fi
  if command -v dnf >/dev/null 2>&1; then
    echo "dnf"
    return
  fi
  if command -v yum >/dev/null 2>&1; then
    echo "yum"
    return
  fi
  if command -v zypper >/dev/null 2>&1; then
    echo "zypper"
    return
  fi
  if command -v pacman >/dev/null 2>&1; then
    echo "pacman"
    return
  fi
  fatal "Kein unterstützter Paketmanager gefunden (apt, dnf, yum, zypper, pacman)."
}

apt_update_once() {
  if [[ "${APT_UPDATED}" -eq 0 ]]; then
    DEBIAN_FRONTEND=noninteractive apt-get update -y 1>&2
    APT_UPDATED=1
  fi
}

get_os_release_value() {
  local key="$1"
  if [[ -r /etc/os-release ]]; then
    awk -F= -v target="${key}" '$1==target {gsub(/"/,"",$2); print $2}' /etc/os-release | head -n1
  fi
}

is_ubuntu_2604_or_newer() {
  local distro version_id
  distro="$(get_os_release_value ID)"
  version_id="$(get_os_release_value VERSION_ID)"
  if [[ "${distro}" != "ubuntu" || -z "${version_id}" ]]; then
    return 1
  fi
  awk -v v="${version_id}" 'BEGIN { split(v,a,"."); major=a[1]+0; minor=a[2]+0; exit !((major>26) || (major==26 && minor>=4)) }'
}

package_exists_apt() {
  local package="$1"
  apt-cache show "${package}" >/dev/null 2>&1
}

resolve_php_version() {
  local requested="$1"
  local manager="$2"
  local resolved=""
  local extracted=""

  extracted="$(printf '%s' "${requested}" | tr -d '\\r' | grep -oE '[0-9]+(\\.[0-9]+)?' | head -n1 || true)"
  if [[ -z "${extracted}" ]]; then
    requested="${DEFAULT_PHP_VERSION}"
  else
    requested="${extracted}"
  fi
  if [[ ! "${requested}" =~ ^[0-9]+(\.[0-9]+)?$ ]]; then
    log "Ungültige PHP-Version (${requested}), verwende ${DEFAULT_PHP_VERSION}."
    requested="${DEFAULT_PHP_VERSION}"
  fi
  resolved="${requested}"

  if [[ "${manager}" == "apt" ]]; then
    apt_update_once
    if ! package_exists_apt "php${requested}"; then
      log "PHP ${requested} nicht in den Paketquellen gefunden."
      resolved=""
      local candidate
      for candidate in 8.4 8.3 8.2 8.1 8.0 7.4; do
        if package_exists_apt "php${candidate}"; then
          resolved="${candidate}"
          break
        fi
      done
      if [[ -z "${resolved}" ]]; then
        fatal "Keine unterstützte PHP-Version in den Paketquellen gefunden."
      fi
      if [[ "${resolved}" != "${requested}" ]]; then
        log "Verwende PHP ${resolved} statt ${requested}."
      fi
    fi
  else
    if [[ ! "${requested}" =~ ^8\.(4|3|2|1|0)$ ]]; then
      log "Hinweis: Für ${manager} wird ein distributionsabhängiges PHP-Paket genutzt, Versionssuffix wird ignoriert."
    fi
  fi

  echo "${resolved}"
}

install_packages() {
  local manager="$1"
  shift
  local packages=("$@")

  step "Installiere Systempakete."
  log "Folgende Pakete werden installiert:"
  printf '%s\n' "${packages[@]}" | sed 's/^/  - /' >&2
  printf '\n' >&2

  case "${manager}" in
    apt)
      apt_update_once
      DEBIAN_FRONTEND=noninteractive apt-get install -y "${packages[@]}" 1>&2
      ;;
    dnf)
      dnf install -y "${packages[@]}" 1>&2
      ;;
    yum)
      yum install -y "${packages[@]}" 1>&2
      ;;
    zypper)
      zypper --non-interactive install --no-confirm "${packages[@]}" 1>&2
      ;;
    pacman)
      pacman -Sy --noconfirm --needed "${packages[@]}" 1>&2
      ;;
    *)
      fatal "Unbekannter Paketmanager: ${manager}"
      ;;
  esac
}

random_hex() {
  local bytes="${1:-32}"
  if command -v openssl >/dev/null 2>&1; then
    openssl rand -hex "${bytes}"
    return
  fi
  head -c "${bytes}" /dev/urandom | od -An -tx1 | tr -d ' \n'
}

random_base64() {
  local bytes="${1:-32}"
  if command -v openssl >/dev/null 2>&1; then
    openssl rand -base64 "${bytes}"
    return
  fi
  head -c "${bytes}" /dev/urandom | base64
}

sha256_hex() {
  local input="$1"
  printf '%s' "${input}" | openssl dgst -sha256 -hex | awk '{print $2}'
}

hmac_sha256_hex() {
  local secret="$1"
  local payload="$2"
  printf '%s' "${payload}" | openssl dgst -sha256 -hmac "${secret}" -hex | awk '{print $2}'
}

build_signature_payload() {
  local agent_id="$1"
  local method="$2"
  local path="$3"
  local timestamp="$4"
  local nonce="$5"
  local raw_body="$6"
  local body_hash
  body_hash="$(sha256_hex "${raw_body}")"
  local payload
  payload="$(printf '%s\n%s\n%s\n%s\n%s' "${agent_id}" "${method}" "${path}" "${body_hash}" "${timestamp}")"
  if [[ -n "${nonce}" ]]; then
    payload="${payload}"$'\n'"${nonce}"
  fi
  printf '%s' "${payload}"
}

resolve_php_binary() {
  local version="$1"
  if command -v "php${version}" >/dev/null 2>&1; then
    echo "php${version}"
    return
  fi
  echo "php"
}

ensure_service_enabled() {
  local service="$1"
  if systemctl list-unit-files "${service}" >/dev/null 2>&1; then
    systemctl enable --now "${service}"
  fi
}

resolve_panel_package_sets() {
  local manager="$1"
  local php_version="$2"
  local db_system="$3"

  PANEL_BASE_PACKAGES=()
  PANEL_PHP_PACKAGES=()
  PANEL_DB_PACKAGES=()
  PANEL_PHP_DB_PACKAGES=()
  PANEL_PHP_FPM_SERVICE=""
  PANEL_DB_SERVICES=()

  case "${manager}" in
    apt)
      PANEL_BASE_PACKAGES=(ca-certificates curl git unzip nginx openssl jq)
      PANEL_PHP_PACKAGES=(
        "php${php_version}" "php${php_version}-fpm" "php${php_version}-cli"
        "php${php_version}-mbstring" "php${php_version}-xml" "php${php_version}-curl"
        "php${php_version}-zip" "php${php_version}-gd" "php${php_version}-intl"
        "php${php_version}-bcmath" "php${php_version}-opcache"
      )
      PANEL_PHP_FPM_SERVICE="php${php_version}-fpm.service"
      ;;
    dnf|yum)
      PANEL_BASE_PACKAGES=(ca-certificates curl git unzip nginx openssl jq)
      PANEL_PHP_PACKAGES=(php php-fpm php-cli php-mbstring php-xml php-curl php-zip php-gd php-intl php-bcmath php-opcache)
      PANEL_PHP_FPM_SERVICE="php-fpm.service"
      ;;
    zypper)
      PANEL_BASE_PACKAGES=(ca-certificates curl git unzip nginx openssl jq)
      PANEL_PHP_PACKAGES=(php8 php8-fpm php8-cli php8-mbstring php8-xmlwriter php8-curl php8-zip php8-gd php8-intl php8-bcmath php8-opcache)
      PANEL_PHP_FPM_SERVICE="php-fpm.service"
      ;;
    pacman)
      PANEL_BASE_PACKAGES=(ca-certificates curl git unzip nginx openssl jq)
      PANEL_PHP_PACKAGES=(php php-fpm)
      PANEL_PHP_FPM_SERVICE="php-fpm.service"
      ;;
    *)
      fatal "Keine Paketdefinitionen für Paketmanager ${manager} vorhanden."
      ;;
  esac

  case "${db_system}" in
    mariadb)
      case "${manager}" in
        apt) PANEL_DB_PACKAGES=(mariadb-server mariadb-client); PANEL_PHP_DB_PACKAGES=("php${php_version}-mysql");;
        dnf|yum|zypper|pacman) PANEL_DB_PACKAGES=(mariadb); PANEL_PHP_DB_PACKAGES=(php-mysqlnd);;
      esac
      PANEL_DB_SERVICES=(mariadb.service mysql.service)
      ;;
    mysql)
      case "${manager}" in
        apt) PANEL_DB_PACKAGES=(mysql-server mysql-client); PANEL_PHP_DB_PACKAGES=("php${php_version}-mysql");;
        dnf|yum) PANEL_DB_PACKAGES=(mysql-server); PANEL_PHP_DB_PACKAGES=(php-mysqlnd);;
        zypper) PANEL_DB_PACKAGES=(mariadb); PANEL_PHP_DB_PACKAGES=(php8-mysql);;
        pacman) PANEL_DB_PACKAGES=(mariadb); PANEL_PHP_DB_PACKAGES=(php-mysqlnd);;
      esac
      PANEL_DB_SERVICES=(mysql.service mariadb.service)
      ;;
    postgresql)
      case "${manager}" in
        apt) PANEL_DB_PACKAGES=(postgresql postgresql-contrib); PANEL_PHP_DB_PACKAGES=("php${php_version}-pgsql");;
        dnf|yum) PANEL_DB_PACKAGES=(postgresql postgresql-server); PANEL_PHP_DB_PACKAGES=(php-pgsql);;
        zypper) PANEL_DB_PACKAGES=(postgresql postgresql-server); PANEL_PHP_DB_PACKAGES=(php8-pgsql);;
        pacman) PANEL_DB_PACKAGES=(postgresql); PANEL_PHP_DB_PACKAGES=(php-pgsql);;
      esac
      PANEL_DB_SERVICES=(postgresql.service)
      ;;
  esac
}

enable_universe_repo() {
  local manager="$1"
  if [[ "${manager}" != "apt" ]]; then
    return
  fi

  step "Aktiviere Universe-Repository und optionales PHP-PPA."
  DEBIAN_FRONTEND=noninteractive apt-get install -y software-properties-common 1>&2
  add-apt-repository -y universe 1>&2
  if is_ubuntu_2604_or_newer; then
    log "Ubuntu 26.04+ erkannt: nutze primär offizielle Ubuntu-Pakete."
  else
    add-apt-repository -y ppa:ondrej/php 1>&2
  fi
  APT_UPDATED=0
  apt_update_once
}

install_composer() {
  local php_bin="$1"

  step "Installiere Composer."
  "${php_bin}" -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
  "${php_bin}" composer-setup.php --install-dir=/usr/local/bin --filename=composer
  "${php_bin}" -r "unlink('composer-setup.php');"
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

  value="$(read_from_tty "${prompt}: ")"
  if [[ -z "${value}" ]]; then
    value="${default}"
  fi

  if [[ -n "${value}" ]]; then
    printf -v "${var_name}" '%s' "${value}"
  fi
}

prompt_yes_no() {
  local prompt="$1"
  local default="${2:-no}"
  local value=""

  if ! is_tty; then
    return 1
  fi

  case "${default}" in
    yes)
      prompt="${prompt} [Y/n]"
      ;;
    no)
      prompt="${prompt} [y/N]"
      ;;
    *)
      prompt="${prompt} [y/n]"
      ;;
  esac

  value="$(read_from_tty "${prompt}: ")"
  if [[ -z "${value}" ]]; then
    value="${default}"
  fi

  case "${value}" in
    y|Y|yes|YES|Yes)
      return 0
      ;;
    *)
      return 1
      ;;
  esac
}

db_system_menu() {
  menu_output <<'MENU'

Datenbanksystem:
  1) MariaDB
  2) MySQL
  3) PostgreSQL
MENU

  local choice
  choice="$(menu_prompt "Bitte wählen Sie [1-3]: ")"
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

write_env_local() {
  local env_path="$1"
  local db_driver="$2"
  local db_host="$3"
  local db_port="$4"
  local db_name="$5"
  local db_user="$6"
  local db_password="$7"
  local app_secret="$8"
  local encryption_keys="$9"
  local registration_token="${10}"
  local default_uri="${11}"
  local install_dir="${12}"

  local key_id="v1"
  local key_ring="${encryption_keys}"
  if [[ "${key_ring}" == *":"* ]]; then
    key_id="${key_ring%%:*}"
  else
    key_ring="v1:${key_ring}"
  fi

  local key_material="${key_ring%%,*}"
  key_material="${key_material#*:}"

  local key_path="/etc/easywi/secret.key"
  step "Schreibe Encryption Key."
  mkdir -p "$(dirname "${key_path}")"
  cat <<KEY >"${key_path}"
{"active_key_id":"${key_id}","keys":{"${key_id}":"${key_material}"}}
KEY
  chmod 600 "${key_path}"

  step "Schreibe .env.local."
  cat <<ENV >"${env_path}"
APP_ENV=prod
APP_SECRET="${app_secret}"
DEFAULT_URI=${default_uri}
MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0
TRUSTED_PROXIES=127.0.0.1
APP_CORE_UPDATE_INSTALL_DIR=${install_dir}
ENV

  if [[ -n "${registration_token}" ]]; then
    echo "AGENT_REGISTRATION_TOKEN=\"${registration_token}\"" >>"${env_path}"
  fi
}

configure_nginx() {
  local server_name="$1"
  local web_root="$2"
  local php_version="$3"
  local config_path="/etc/nginx/sites-available/easywi.conf"
  local enabled_path="/etc/nginx/sites-enabled/easywi.conf"

  step "Konfiguriere Nginx."
  cat <<NGINX >"${config_path}"
server {
    listen 80;
    server_name ${server_name};

    root ${web_root};
    index index.php;

    # Security headers
    add_header Content-Security-Policy "default-src 'self' https: data: blob: 'unsafe-inline' 'unsafe-eval'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    location / {
        try_files \$uri /index.php\$is_args\$args;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php${php_version}-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
    }

    location ~ /\.ht {
        deny all;
    }
}
NGINX

  ln -sf "${config_path}" "${enabled_path}"
  rm -f /etc/nginx/sites-enabled/default 2>/dev/null || true
  systemctl reload nginx.service
}

write_installation_info() {
  local info_path="$1"
  local db_driver="$2"
  local db_host="$3"
  local db_port="$4"
  local db_name="$5"
  local db_user="$6"
  local db_password="$7"
  local default_uri="$8"
  local webserver_config="$9"

  step "Schreibe Installationsinformationen."
  cat <<INFO >"${info_path}"
EasyWI Installationsdaten
=========================

URL: ${default_uri}

Datenbank
---------
Treiber: ${db_driver}
Host: ${db_host}
Port: ${db_port:-Standard}
Name: ${db_name}
User: ${db_user}
Passwort: ${db_password}

Webserver
---------
Nginx-Config: ${webserver_config}
INFO

  chmod 600 "${info_path}"
}

create_systemd_service() {
  local name="$1"
  local exec_start="$2"
  local description="$3"
  local unit_path="/etc/systemd/system/${name}.service"

  cat <<SERVICE >"${unit_path}"
[Unit]
Description=${description}
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart=${exec_start}
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
SERVICE
}

create_agent_systemd_service() {
  local instance_base_dir="$1"
  local sftp_base_dir="$2"
  local unit_path="/etc/systemd/system/easywi-agent.service"

  cat <<SERVICE >"${unit_path}"
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
SERVICE
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
  if curl -fsSL "${base_url}/${asset}" -o "${destination}"; then
    return 0
  fi

  if [[ "${asset}" =~ ^easywi-agent-linux-(amd64|x86_64|arm64|aarch64)$ ]]; then
    local fallback_asset
    for fallback_asset in "${asset}.tar.gz" "${asset}.zip"; do
      log "Primäres Asset nicht verfügbar, versuche Fallback: ${fallback_asset}"
      if curl -fsSL "${base_url}/${fallback_asset}" -o "${destination}"; then
        return 0
      fi
    done
  fi

  fatal "Asset nicht verfügbar: ${asset} (${base_url}/${asset})"
}

download_optional_release_asset() {
  local asset="$1"
  local destination="$2"
  local version="$3"
  local base_url="https://github.com/Matlord93/Easy-Wi-NextGen/releases/latest/download"

  if [[ -n "${version}" && "${version}" != "latest" ]]; then
    base_url="https://github.com/Matlord93/Easy-Wi-NextGen/releases/download/${version}"
  fi

  if curl -fsSL "${base_url}/${asset}" -o "${destination}"; then
    log "Optionales Asset geladen: ${asset}"
    return 0
  fi

  rm -f "${destination}"
  log "Optionales Asset nicht verfügbar: ${asset}"
  return 1
}

download_release_asset_from_candidates() {
  local destination="$1"
  local version="$2"
  shift 2
  local candidates=("$@")
  local asset

  for asset in "${candidates[@]}"; do
    if download_optional_release_asset "${asset}" "${destination}" "${version}"; then
      echo "${asset}"
      return 0
    fi
  done

  fatal "Keines der erwarteten Release-Assets gefunden: ${candidates[*]}"
}

detect_release_arch() {
  local os
  local arch
  os="$(uname -s | tr '[:upper:]' '[:lower:]')"
  arch="$(uname -m)"

  if [[ "${os}" != "linux" ]]; then
    fatal "Nur Linux wird vom Installer unterstützt (gefunden: ${os})."
  fi

  case "${arch}" in
    x86_64|amd64)
      echo "linux-amd64"
      ;;
    aarch64|arm64)
      echo "linux-arm64"
      ;;
    *)
      fatal "Nicht unterstützte Architektur: ${arch} (erwartet: amd64 oder arm64)."
      ;;
  esac
}

install_agent_release_binaries() {
  local agent_version="$1"
  local release_arch="$2"
  local tmp_dir
  tmp_dir="$(mktemp -d)"

  local downloaded_agent_asset
  local downloaded_wrapper_asset

  step "Lade Agent-Releaseassets."
  downloaded_agent_asset="$(download_release_asset_from_candidates "${tmp_dir}/agent-asset" "${agent_version}" \
    "easywi-agent-${release_arch}.tar.gz" \
    "easywi-agent-${release_arch}.zip" \
    "easywi-agent-${release_arch}")"
  downloaded_wrapper_asset="$(download_release_asset_from_candidates "${tmp_dir}/wrapper-asset" "${agent_version}" \
    "easywi-wrapper-${release_arch}.tar.gz" \
    "easywi-wrapper-${release_arch}.zip" \
    "easywi-wrapper-${release_arch}" \
    "easywi-wrapper-linux-${release_arch#linux-}")"

  local extracted_agent="${tmp_dir}/easywi-agent-${release_arch}"
  local extracted_wrapper="${tmp_dir}/easywi-wrapper-${release_arch}"
  case "${downloaded_agent_asset}" in
    *.tar.gz)
      tar -xzf "${tmp_dir}/agent-asset" -C "${tmp_dir}"
      ;;
    *.zip)
      unzip -oq "${tmp_dir}/agent-asset" -d "${tmp_dir}"
      ;;
    *)
      mv "${tmp_dir}/agent-asset" "${extracted_agent}"
      ;;
  esac
  case "${downloaded_wrapper_asset}" in
    *.tar.gz)
      tar -xzf "${tmp_dir}/wrapper-asset" -C "${tmp_dir}"
      ;;
    *.zip)
      unzip -oq "${tmp_dir}/wrapper-asset" -d "${tmp_dir}"
      ;;
    *)
      mv "${tmp_dir}/wrapper-asset" "${extracted_wrapper}"
      ;;
  esac

  if [[ ! -f "${extracted_agent}" ]]; then
    fatal "Agent-Binary nicht gefunden nach Entpacken: ${extracted_agent}"
  fi
  if [[ ! -f "${extracted_wrapper}" ]]; then
    fatal "Wrapper-Binary nicht gefunden nach Entpacken: ${extracted_wrapper}"
  fi

  install -m 0755 "${extracted_agent}" /usr/local/bin/easywi-agent
  install -m 0755 "${extracted_wrapper}" /usr/local/bin/easywi-wrapper
  chmod +x /usr/local/bin/easywi-agent /usr/local/bin/easywi-wrapper

  if ! command -v easywi-agent >/dev/null 2>&1; then
    fatal "easywi-agent wurde nicht korrekt installiert."
  fi
  if ! command -v easywi-wrapper >/dev/null 2>&1; then
    fatal "easywi-wrapper wurde nicht korrekt installiert."
  fi
  rm -rf "${tmp_dir}"
}

validate_systemd_unit() {
  local service_name="$1"
  local unit_path="/etc/systemd/system/${service_name}.service"
  if [[ ! -f "${unit_path}" ]]; then
    fatal "Systemd-Unit fehlt: ${unit_path}"
  fi
}

has_systemctl() {
  command -v systemctl >/dev/null 2>&1
}

install_panel() {
  local mode="$1"
  local install_dir="$2"
  local repo_url="$3"
  local repo_ref="$4"
  local db_driver="$5"
  local db_system="$6"
  local db_root_password="$7"
  local db_host="$8"
  local db_port="$9"
  local db_name="${10}"
  local db_user="${11}"
  local db_password="${12}"
  local php_version="${13}"
  local web_hostname="${14}"
  local web_user="${15}"
  local system_user="${16}"
  local app_secret="${17}"
  local app_encryption_keys="${18}"
  local agent_registration_token="${19}"
  local app_github_token="${20}"
  local run_migrations="${21}"
  local web_scheme="${22}"
  local provision_database="${23}"

  step "Prüfe Panel-Abhängigkeiten."
  local pkg_manager
  pkg_manager="$(detect_package_manager)"
  enable_universe_repo "${pkg_manager}"
  php_version="$(resolve_php_version "${php_version}" "${pkg_manager}")"

  resolve_panel_package_sets "${pkg_manager}" "${php_version}" "${db_system}"

  install_packages "${pkg_manager}" "${PANEL_BASE_PACKAGES[@]}" "${PANEL_PHP_PACKAGES[@]}" "${PANEL_PHP_DB_PACKAGES[@]}" "${PANEL_DB_PACKAGES[@]}"

  local php_bin
  php_bin="$(resolve_php_binary "${php_version}")"
  install_composer "${php_bin}"

  ensure_service_enabled "${PANEL_PHP_FPM_SERVICE}"
  ensure_service_enabled nginx.service
  local db_service
  for db_service in "${PANEL_DB_SERVICES[@]}"; do
    ensure_service_enabled "${db_service}"
  done

  ensure_system_user "${system_user}" "${install_dir}"

  if [[ "${provision_database}" == "true" ]]; then
    case "${db_system}" in
      mariadb|mysql)
        setup_database_mysql "${db_name}" "${db_user}" "${db_password}" "${db_root_password}"
        ;;
      postgresql)
        setup_database_postgresql "${db_name}" "${db_user}" "${db_password}"
        ;;
    esac
  else
    log "Überspringe DB-Provisionierung (CREATE DATABASE/CREATE USER), nutze bestehende Datenbankkonfiguration."
  fi

  step "Lade Panel-Quellcode."
  if [[ -d "${install_dir}/.git" ]]; then
    ensure_git_safe_dir "${install_dir}"
    git -C "${install_dir}" fetch --all --tags
    git -C "${install_dir}" checkout "${repo_ref}"
    git -C "${install_dir}" pull --ff-only
  elif [[ -d "${install_dir}" && -n "$(ls -A "${install_dir}" 2>/dev/null)" ]]; then
    if prompt_yes_no "Installationsverzeichnis ${install_dir} ist nicht leer. Inhalt löschen und fortfahren?" "no"; then
      (shopt -s dotglob nullglob; rm -rf "${install_dir:?}/"*)
      git clone "${repo_url}" "${install_dir}"
      ensure_git_safe_dir "${install_dir}"
      git -C "${install_dir}" checkout "${repo_ref}"
    else
      fatal "Installationsverzeichnis ${install_dir} ist nicht leer und kein Git-Repository."
    fi
  else
    git clone "${repo_url}" "${install_dir}"
    ensure_git_safe_dir "${install_dir}"
    git -C "${install_dir}" checkout "${repo_ref}"
  fi

  step "Installiere Composer-Abhängigkeiten."
  local core_dir="${install_dir}/core"
  if [[ ! -d "${core_dir}" ]]; then
    fatal "Core-Verzeichnis nicht gefunden: ${core_dir}"
  fi

  COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --working-dir "${core_dir}"

  if [[ -z "${app_secret}" ]]; then
    app_secret="$(random_hex 32)"
  fi
  if [[ -z "${app_encryption_keys}" ]]; then
    app_encryption_keys="$(random_base64 32)"
  fi

  local default_uri="${web_scheme}://${web_hostname}"
  if [[ "${web_hostname}" == "_" ]]; then
    default_uri="${web_scheme}://localhost"
  fi

  write_env_local "${core_dir}/.env.local" "${db_driver}" "${db_host}" "${db_port}" "${db_name}" "${db_user}" "${db_password}" "${app_secret}" "${app_encryption_keys}" "${agent_registration_token}" "${default_uri}" "${install_dir}"
  write_installation_info "${install_dir}/INSTALLATION_INFO.txt" "${db_driver}" "${db_host}" "${db_port}" "${db_name}" "${db_user}" "${db_password}" "${default_uri}" "/etc/nginx/sites-available/easywi.conf"

  if [[ -n "${app_github_token}" ]]; then
    echo "APP_GITHUB_TOKEN=\"${app_github_token}\"" >>"${core_dir}/.env.local"
  fi

  step "Setze Dateiberechtigungen."
  chown -R "${system_user}:${system_user}" "${install_dir}"
  chown -R "${web_user}:${web_user}" "${core_dir}/var" "${core_dir}/public"

  configure_nginx "${web_hostname}" "${core_dir}/public" "${php_version}"

  if [[ "${run_migrations}" == "true" ]]; then
    step "Führe Datenbankmigrationen aus."
    "${php_bin}" "${core_dir}/bin/console" doctrine:migrations:migrate --no-interaction --allow-no-migration
    "${php_bin}" "${core_dir}/bin/console" cache:clear --env=prod
  else
    log "Migrationen wurden übersprungen."
  fi
}


prepare_agent_runtime_layout() {
  mkdir -p /etc/easywi
  mkdir -p /opt/easywi/templates
  mkdir -p /opt/easywi/instances
  mkdir -p /opt/sinusbot/instances
}

install_agent_binaries_only() {
  local agent_version="$1"
  local file_base_dir="$2"
  local sftp_base_dir="$3"

  step "Installiere Agent-Binaries (ohne Registrierung)."
  log "Es werden nur die Binaries installiert, kein Token notwendig."

  local release_arch
  release_arch="$(detect_release_arch)"
  install_agent_release_binaries "${agent_version}" "${release_arch}"

  mapfile -t base_dir_config < <(build_agent_base_dir_config "${file_base_dir}")
  local primary_base_dir="${base_dir_config[0]}"

  prepare_agent_runtime_layout
  if [[ ! -f /etc/easywi/agent.conf ]]; then
    cat <<'CONF' >/etc/easywi/agent.conf
# Beispiel-Konfiguration für die spätere Registrierung
# agent_id=<AGENT_ID>
# secret=<SECRET>
# api_url=https://panel.example.com
# service_listen=0.0.0.0:7456
# file_base_dir=/home
# file_base_dirs=/home,/var/www
#
# Hinweis: Der normale easywi-agent stellt zusätzlich die internen
# Game- und Sinusbot-Endpunkte bereit (kein separater gamesvc/sinusbotsvc Dienst nötig).
CONF
    chmod 600 /etc/easywi/agent.conf
  fi

  create_agent_systemd_service "${primary_base_dir}" "${sftp_base_dir}"
  validate_systemd_unit "easywi-agent"

  if has_systemctl; then
    systemctl daemon-reload
    systemctl enable easywi-agent.service
  fi

  cat <<'INFO'
Die Binaries wurden installiert. Die Konfiguration und Dienste können
nach der Registrierung im Webinterface ergänzt werden:
  - /etc/easywi/agent.conf
Der Systemd-Service wurde angelegt.
INFO

  if has_systemctl; then
    echo "Systemd wurde erkannt: easywi-agent.service ist für den Autostart aktiviert."
  else
    echo "Hinweis: Kein systemctl gefunden. Starte den Agent manuell: /usr/local/bin/easywi-agent --config /etc/easywi/agent.conf"
  fi
}

install_agent_services() {
  local agent_id="$1"
  local secret="$2"
  local api_url="$3"
  local file_base_dir="$4"
  local sftp_base_dir="$5"
  local bind_ip_addresses="$6"

  mapfile -t base_dir_config < <(build_agent_base_dir_config "${file_base_dir}")
  local primary_base_dir="${base_dir_config[0]}"
  local all_base_dirs="${base_dir_config[1]}"

  prepare_agent_runtime_layout
  cat <<CONF >/etc/easywi/agent.conf
agent_id=${agent_id}
secret=${secret}
api_url=${api_url}
service_listen=0.0.0.0:7456
file_base_dir=${primary_base_dir}
file_base_dirs=${all_base_dirs}
bind_ip_addresses=${bind_ip_addresses}
# Der Agent stellt auch die internen Game-/Sinusbot-Endpunkte bereit.
CONF
  chmod 600 /etc/easywi/agent.conf

  create_agent_systemd_service "${primary_base_dir}" "${sftp_base_dir}"
  validate_systemd_unit "easywi-agent"

  if has_systemctl; then
    systemctl daemon-reload
    systemctl enable --now easywi-agent.service
  else
    log "Kein systemctl gefunden: easywi-agent.service kann nicht automatisch gestartet werden."
    log "Manueller Start: /usr/local/bin/easywi-agent --config /etc/easywi/agent.conf"
  fi
}

write_bootstrap_state() {
  local state_file="$1"
  local register_url="$2"
  local register_token="$3"
  local agent_id="$4"
  local bootstrap_token="$5"
  local lock_file="${state_file}.lock"

  install -d -m 700 "$(dirname "${state_file}")"
  {
    flock -x 9
    jq -n --arg url "${register_url}" --arg token "${register_token}" --arg agent "${agent_id}" --arg bootstrap "${bootstrap_token}" \
      '{register_url:$url,register_token:$token,agent_id:$agent,bootstrap_token:$bootstrap}' >"${state_file}"
    chmod 600 "${state_file}"
  } 9>"${lock_file}"
}

read_bootstrap_state() {
  local state_file="$1"
  local lock_file="${state_file}.lock"

  {
    flock -x 9
    if [[ ! -f "${state_file}" ]]; then
      return 1
    fi
    jq -r '.register_url // empty, .register_token // empty, .agent_id // empty, .bootstrap_token // empty' "${state_file}"
  } 9>"${lock_file}"
}

delete_bootstrap_state() {
  local state_file="$1"
  local lock_file="${state_file}.lock"

  {
    flock -x 9
    rm -f "${state_file}"
  } 9>"${lock_file}"
}

register_agent_with_core() {
  local core_url="$1"
  local bootstrap_token="$2"
  local agent_version="$3"
  local agent_name="$4"
  local bootstrap_hostname="$5"
  local state_file="$6"

  local hostname
  if [[ -n "${bootstrap_hostname}" ]]; then
    hostname="${bootstrap_hostname}"
  else
    hostname="$(hostname -f 2>/dev/null || hostname)"
  fi

  local bootstrap_payload
  bootstrap_payload="$(jq -n --arg token "${bootstrap_token}" --arg hostname "${hostname}" --arg os "linux" --arg version "${agent_version}" '{bootstrap_token:$token,hostname:$hostname,os:$os,agent_version:$version}')"

  step "Registriere Agent am Core (Bootstrap)."
  log "Bootstrap Debug: core_url=${core_url} hostname=${hostname}"
  local bootstrap_response bootstrap_status
  bootstrap_response="$(curl -sS -w '\n%{http_code}' -X POST "${core_url}/api/v1/agent/bootstrap" -H "Content-Type: application/json" -d "${bootstrap_payload}")"
  bootstrap_status="${bootstrap_response##*$'\n'}"
  bootstrap_response="${bootstrap_response%$'\n'*}"
  if [[ "${bootstrap_status}" != "200" ]]; then
    log "Bootstrap-Fehler (HTTP ${bootstrap_status})."
    if [[ -n "${bootstrap_response}" ]]; then
      log "Antwort: ${bootstrap_response}"
    fi
    fatal "Agent-Registrierung fehlgeschlagen."
  fi

  local register_url register_token agent_id
  register_url="$(jq -r '.register_url // empty' <<<"${bootstrap_response}")"
  register_token="$(jq -r '.register_token // empty' <<<"${bootstrap_response}")"
  agent_id="$(jq -r '.agent_id // empty' <<<"${bootstrap_response}")"

  if [[ -z "${register_url}" || -z "${register_token}" || -z "${agent_id}" ]]; then
    fatal "Ungültige Antwort vom Core Bootstrap-Endpunkt."
  fi
  if [[ -n "${state_file}" ]]; then
    write_bootstrap_state "${state_file}" "${register_url}" "${register_token}" "${agent_id}" "${bootstrap_token}"
    log "Bootstrap-State gespeichert: ${state_file}"
  fi

  local agent_secret
  if ! agent_secret="$(register_agent_with_token "${register_url}" "${register_token}" "${agent_id}" "${agent_name}")"; then
    fatal "Agent-Registrierung fehlgeschlagen."
  fi
  echo "${agent_id}|${agent_secret}"
}

register_agent_with_token() {
  local register_url="$1"
  local register_token="$2"
  local agent_id="$3"
  local agent_name="$4"
  export REGISTER_HTTP_STATUS=""
  export REGISTER_HTTP_BODY=""

  if [[ "${register_url}" =~ ^https?://[^/]+/.+/$ ]]; then
    register_url="${register_url%/}"
  fi

  local register_payload
  register_payload="$(jq -n --arg agent_id "${agent_id}" --arg token "${register_token}" --arg name "${agent_name}" '{agent_id:$agent_id,register_token:$token,name:$name}')"

  local timestamp nonce path body_hash signature payload
  timestamp="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
  nonce="$(random_hex 8)"
  path="$(printf '%s' "${register_url}" | sed -E 's#https?://[^/]+##')"
  if [[ -z "${path}" ]]; then
    path="/"
  fi
  path="${path%%\?*}"
  path="${path%%#*}"
  if [[ "${path}" != "/" ]]; then
    path="${path%/}"
  fi
  body_hash="$(sha256_hex "${register_payload}")"
  payload="$(build_signature_payload "${agent_id}" "POST" "${path}" "${timestamp}" "${nonce}" "${register_payload}")"
  signature="$(hmac_sha256_hex "${register_token}" "${payload}")"

  step "Agent-Registrierung abschließen."
  log "Register Debug: agent_id=${agent_id} path=${path} body_hash=${body_hash} timestamp=${timestamp} nonce=${nonce} signature_prefix=${signature:0:12}"
  local register_response register_status
  register_response="$(curl -sS -w '\n%{http_code}' -X POST "${register_url}" \
    -H "Content-Type: application/json" \
    -H "X-Agent-ID: ${agent_id}" \
    -H "X-Timestamp: ${timestamp}" \
    -H "X-Nonce: ${nonce}" \
    -H "X-Signature: ${signature}" \
    -d "${register_payload}")"
  register_status="${register_response##*$'\n'}"
  register_response="${register_response%$'\n'*}"
  if [[ "${register_status}" != "200" && "${register_status}" != "201" ]]; then
    log "Registrierungsfehler (HTTP ${register_status})."
    if [[ -n "${register_response}" ]]; then
      log "Antwort: ${register_response}"
    fi
    REGISTER_HTTP_STATUS="${register_status}"
    REGISTER_HTTP_BODY="${register_response}"
    return 1
  fi

  local agent_secret
  agent_secret="$(jq -r '.secret // empty' <<<"${register_response}")"
  if [[ -z "${agent_secret}" ]]; then
    REGISTER_HTTP_STATUS="200"
    REGISTER_HTTP_BODY="${register_response}"
    return 1
  fi

  echo "${agent_secret}"
}

print_intro() {
  cat <<INTRO
EasyWI Installer Menü (Linux) v${VERSION}

Dieses Skript führt Sie Schritt für Schritt durch die Installation.
Es erklärt vor jeder Aktion, was passiert, und übernimmt alle
Installationsschritte direkt.
INTRO
}

panel_mode_menu() {
  menu_output <<'MENU'

Panel-Installationsmodus:
  1) Standalone (eigene Webserver-Konfiguration)
  2) Plesk
  3) aaPanel
  4) Abbrechen
MENU

  local choice
  choice="$(menu_prompt "Bitte wählen Sie den Modus [1-4]: ")"
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
  local app_secret="${EASYWI_APP_SECRET:-}"
  local app_encryption_keys="${EASYWI_SECRET_KEY:-}"
  local agent_registration_token="${EASYWI_AGENT_REGISTRATION_TOKEN:-}"
  local app_github_token="${EASYWI_APP_GITHUB_TOKEN:-}"
  local run_migrations="${EASYWI_RUN_MIGRATIONS:-true}"
  local web_scheme="${EASYWI_WEB_SCHEME:-https}"
  local provision_database="${EASYWI_DB_PROVISION:-true}"

  echo
  echo "Panel-Setup: Wir laden den Quellcode, schreiben die .env.local,"
  echo "konfigurieren den Webserver, und führen optional"
  echo "die Datenbankmigrationen aus."

  prompt_value repo_url "Git-Repository URL" "https://github.com/Matlord93/Easy-Wi-NextGen.git"
  prompt_value install_dir "Installationsverzeichnis" "${install_dir}"
  prompt_value repo_ref "Git-Branch/Tag" "${repo_ref}"
  if [[ -z "${repo_url}" ]]; then
    repo_url="https://github.com/Matlord93/Easy-Wi-NextGen.git"
  fi
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
  prompt_value app_encryption_keys "Encryption key (base64, stored in /etc/easywi/secret.key)" "${app_encryption_keys}"
  prompt_value agent_registration_token "AGENT_REGISTRATION_TOKEN (optional)" "${agent_registration_token}"
  prompt_value app_github_token "GitHub Token (optional)" "${app_github_token}"
  prompt_value provision_database "DB automatisch erstellen? (true/false)" "${provision_database}"
  prompt_value run_migrations "Migrationen ausführen? (true/false)" "${run_migrations}"

  prompt_value php_version "PHP-Version (8.4)" "${php_version}"
  prompt_value web_hostname "Servername" "${web_hostname}"
  prompt_value web_user "Web-User" "${web_user}"
  prompt_value web_scheme "Web-Schema (http/https)" "${web_scheme}"
  if [[ -z "${web_scheme}" ]]; then
    web_scheme="https"
  fi

  if [[ -z "${web_user}" ]]; then
    web_user="${system_user}"
  fi
  if [[ "${web_user}" != "${system_user}" ]] && ! id -u "${web_user}" >/dev/null 2>&1; then
    fatal "Web-User ${web_user} existiert nicht."
  fi

  if [[ -z "${db_password}" ]]; then
    fatal "DB-Passwort darf nicht leer sein."
  fi

  log "Webserver: nginx"
  log "PHP-Version: ${php_version}"
  log "Datenbanksystem: ${db_system}"
  log "System-User: ${system_user}"
  log "Web-User: ${web_user}"

  install_panel "${mode}" "${install_dir}" "${repo_url}" "${repo_ref}" "${db_driver}" "${db_system}" "${db_root_password}" "${db_host}" "${db_port}" "${db_name}" "${db_user}" "${db_password}" "${php_version}" "${web_hostname}" "${web_user}" "${system_user}" "${app_secret}" "${app_encryption_keys}" "${agent_registration_token}" "${app_github_token}" "${run_migrations}" "${web_scheme}" "${provision_database}"
}

build_agent_base_dir_config() {
  local base_input="$1"

  if [[ -z "${base_input}" ]]; then
    base_input="/home,/var/www"
  fi

  local primary=""
  local -a unique_dirs=()
  IFS=',' read -ra raw_dirs <<<"${base_input}"
  for raw_dir in "${raw_dirs[@]}"; do
    local dir
    dir="$(printf '%s' "${raw_dir}" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
    if [[ -z "${dir}" ]]; then
      continue
    fi
    if [[ "${dir}" != /* ]]; then
      fatal "File Base Directory muss absolut sein: ${dir}"
    fi

    if [[ -z "${primary}" ]]; then
      primary="${dir}"
    fi

    local exists="false"
    local candidate
    for candidate in "${unique_dirs[@]}"; do
      if [[ "${candidate}" == "${dir}" ]]; then
        exists="true"
        break
      fi
    done
    if [[ "${exists}" == "false" ]]; then
      unique_dirs+=("${dir}")
    fi
  done

  if [[ -z "${primary}" ]]; then
    primary="/home"
    unique_dirs=("/home" "/var/www")
  fi

  local joined=""
  local dir
  for dir in "${unique_dirs[@]}"; do
    if [[ -z "${joined}" ]]; then
      joined="${dir}"
    else
      joined+=",${dir}"
    fi
  done

  printf '%s\n%s\n' "${primary}" "${joined}"
}

detect_default_linux_file_base_dirs() {
  local defaults=""
  if command -v findmnt >/dev/null 2>&1; then
    local mount
    while IFS= read -r mount; do
      mount="$(printf '%s' "${mount}" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
      [[ -z "${mount}" ]] && continue
      case "${mount}" in
        /|/boot|/boot/efi|/run|/run/*|/proc|/sys|/dev|/var/lib/docker|/snap) continue ;;
      esac
      if [[ -z "${defaults}" ]]; then
        defaults="${mount}"
      else
        defaults+=",${mount}"
      fi
    done < <(findmnt -rn -o TARGET -t ext4,xfs,btrfs,zfs 2>/dev/null)
  fi

  if [[ -z "${defaults}" ]]; then
    defaults="/home,/var/www"
  fi

  printf '%s\n' "${defaults}"
}

detect_local_ipv4_addresses() {
  if command -v ip >/dev/null 2>&1; then
    ip -o -4 addr show scope global 2>/dev/null | awk '{print $4}' | cut -d/ -f1 | paste -sd, -
    return
  fi
  printf '\n'
}

run_agent_install() {
  local core_url="${EASYWI_CORE_URL:-${EASYWI_API_URL:-}}"
  local bootstrap_token="${EASYWI_BOOTSTRAP_TOKEN:-}"
  local agent_version="${EASYWI_AGENT_VERSION:-latest}"
  local file_base_dir="${EASYWI_FILE_BASE_DIR:-}"
  local sftp_base_dir="${EASYWI_SFTP_BASE_DIR:-/var/lib/easywi/sftp}"
  local agent_name="${EASYWI_AGENT_NAME:-}"
  local agent_hostname="${EASYWI_AGENT_HOSTNAME:-}"
  local bootstrap_state_file="${EASYWI_BOOTSTRAP_STATE_FILE:-/etc/easywi/bootstrap-state.json}"
  local register_token="${EASYWI_AGENT_REGISTER_TOKEN:-}"
  local agent_id="${EASYWI_AGENT_ID:-}"
  local register_url="${EASYWI_AGENT_REGISTER_URL:-}"
  local bind_ip_addresses="${EASYWI_BIND_IP_ADDRESSES:-}"

  echo
  echo "Agent-Setup: Wir laden die Agent-Binaries."
  echo "Optional registrieren wir sie am Core und richten Systemd-Services ein."

  prompt_value core_url "Core API URL" "${core_url}"
  prompt_value bootstrap_token "Bootstrap Token" "${bootstrap_token}"
  prompt_value agent_version "Agent Version (latest oder Tag)" "${agent_version}"
  if [[ -z "${file_base_dir}" ]]; then
    file_base_dir="$(detect_default_linux_file_base_dirs)"
  fi
  if [[ -z "${bind_ip_addresses}" ]]; then
    bind_ip_addresses="$(detect_local_ipv4_addresses)"
  fi
  prompt_value file_base_dir "File Base Directory(s, comma separated)" "${file_base_dir}"
  prompt_value bind_ip_addresses "Bind IP Addresses (comma separated, optional)" "${bind_ip_addresses}"
  prompt_value sftp_base_dir "SFTP Base Directory" "${sftp_base_dir}"
  prompt_value agent_name "Agent Name (optional)" "${agent_name}"
  prompt_value agent_hostname "Agent Hostname (optional)" "${agent_hostname}"

  local pkg_manager
  pkg_manager="$(detect_package_manager)"
  install_packages "${pkg_manager}" ca-certificates curl openssl jq

  if [[ -n "${core_url}" ]]; then
    core_url="${core_url%/}"
  fi

  if [[ -z "${core_url}" ]]; then
    log "Kein Core-URL angegeben -> Installiere nur die Binaries."
    install_agent_binaries_only "${agent_version}" "${file_base_dir}" "${sftp_base_dir}"
    return
  fi

  step "Installiere Agent-Binaries."
  install_agent_binaries_only "${agent_version}" "${file_base_dir}" "${sftp_base_dir}"

  if [[ -z "${agent_name}" ]]; then
    agent_name="$(hostname -f 2>/dev/null || hostname)"
  fi

  if [[ -z "${bootstrap_token}" && -z "${register_token}" && -f "${bootstrap_state_file}" ]]; then
    log "Bootstrap-State gefunden: ${bootstrap_state_file}"
    readarray -t bootstrap_state < <(read_bootstrap_state "${bootstrap_state_file}")
    register_url="${bootstrap_state[0]:-}"
    register_token="${bootstrap_state[1]:-}"
    agent_id="${bootstrap_state[2]:-}"
    if [[ -z "${bootstrap_token}" ]]; then
      bootstrap_token="${bootstrap_state[3]:-}"
    fi
    if [[ -z "${register_url}" || -z "${register_token}" || -z "${agent_id}" ]]; then
      fatal "Bootstrap-State ist unvollständig."
    fi
  fi

  local agent_secret=""
  if [[ -n "${register_token}" && -n "${agent_id}" ]]; then
    log "Registrierung mit gespeicherten Register-Token."
    if [[ -z "${register_url}" ]]; then
      register_url="${core_url}/api/v1/agent/register"
    fi
    if ! agent_secret="$(register_agent_with_token "${register_url}" "${register_token}" "${agent_id}" "${agent_name}")"; then
      log "Registrierung mit Register-Token fehlgeschlagen, versuche Bootstrap erneut."
      delete_bootstrap_state "${bootstrap_state_file}"
      register_url=""
      register_token=""
      agent_id=""
    fi
  else
    if [[ -z "${bootstrap_token}" ]]; then
      log "Kein Bootstrap-Token angegeben -> Installiere nur die Binaries."
      install_agent_binaries_only "${agent_version}" "${file_base_dir}" "${sftp_base_dir}"
      return
    fi
  fi

  if [[ -z "${agent_secret}" ]]; then
    if [[ -z "${bootstrap_token}" ]]; then
      log "Kein Bootstrap-Token verfügbar -> Installiere nur die Binaries."
      install_agent_binaries_only "${agent_version}" "${file_base_dir}" "${sftp_base_dir}"
      return
    fi
    local agent_identity
    agent_identity="$(register_agent_with_core "${core_url}" "${bootstrap_token}" "${agent_version}" "${agent_name}" "${agent_hostname}" "${bootstrap_state_file}")"
    agent_id="${agent_identity%%|*}"
    agent_secret="${agent_identity#*|}"
  fi

  install_agent_services "${agent_id}" "${agent_secret}" "${core_url}" "${file_base_dir}" "${sftp_base_dir}" "${bind_ip_addresses}"
  delete_bootstrap_state "${bootstrap_state_file}"
}

main_menu() {
  menu_output <<'MENU'

Was möchten Sie installieren?
  1) Webinterface (Panel)
  2) Agent
  3) Panel + Agent
  4) Beenden
MENU

  local choice
  choice="$(menu_prompt "Bitte wählen Sie [1-4]: ")"
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
