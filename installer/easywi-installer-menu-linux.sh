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

detect_package_manager() {
  if command -v apt-get >/dev/null 2>&1; then
    echo "apt"
    return
  fi
  fatal "Dieses Skript unterstützt derzeit nur apt-basierte Distributionen (Debian/Ubuntu)."
}

apt_update_once() {
  if [[ "${APT_UPDATED}" -eq 0 ]]; then
    DEBIAN_FRONTEND=noninteractive apt-get update -y 1>&2
    APT_UPDATED=1
  fi
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

  extracted="$(printf '%s' "${requested}" | tr -d '\r' | grep -oE '[0-9]+(\.[0-9]+)?' | head -n1 || true)"
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
      local missing_packages=()
      local package
      for package in "${packages[@]}"; do
        if [[ -z "${package}" ]]; then
          continue
        fi
        if ! package_exists_apt "${package}"; then
          missing_packages+=("${package}")
        fi
      done
      if [[ "${#missing_packages[@]}" -gt 0 ]]; then
        fatal "Folgende Pakete sind nicht verfügbar: ${missing_packages[*]}"
      fi
      DEBIAN_FRONTEND=noninteractive apt-get install -y "${packages[@]}" 1>&2
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

enable_universe_repo() {
  local manager="$1"
  if [[ "${manager}" != "apt" ]]; then
    return
  fi

  step "Aktiviere Universe-Repository und PHP-PPA für PHP 8.4."
  DEBIAN_FRONTEND=noninteractive apt-get install -y software-properties-common 1>&2
  add-apt-repository -y universe 1>&2
  add-apt-repository -y ppa:ondrej/php 1>&2
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

  local encoded_password
  encoded_password="$(printf '%s' "${db_password}" | jq -sRr @uri)"

  local db_scheme
  case "${db_driver}" in
    mysql) db_scheme="mysql";;
    pgsql) db_scheme="postgresql";;
    *)
      fatal "Unbekannter DB-Treiber für DATABASE_URL: ${db_driver}"
      ;;
  esac

  local db_port_segment=""
  if [[ -n "${db_port}" ]]; then
    db_port_segment=":${db_port}"
  fi

  local db_url="${db_scheme}://${db_user}:${encoded_password}@${db_host}${db_port_segment}/${db_name}"

  local key_id="v1"
  local key_ring="${encryption_keys}"
  if [[ "${key_ring}" == *":"* ]]; then
    key_id="${key_ring%%:*}"
  else
    key_ring="v1:${key_ring}"
  fi

  step "Schreibe .env.local."
  cat <<ENV >"${env_path}"
APP_ENV=prod
APP_SECRET="${app_secret}"
APP_ENCRYPTION_KEY_ID=${key_id}
APP_ENCRYPTION_KEYS="${key_ring}"
DATABASE_URL="${db_url}"
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

ensure_filesvc_certificates() {
  local cert_path="$1"
  local key_path="$2"
  local hostname="$3"
  local ip_addresses="$4"

  if [[ -f "${cert_path}" && -f "${key_path}" ]]; then
    return
  fi

  step "Erzeuge TLS-Zertifikat für File Service."
  local san_entries=()
  if [[ -n "${hostname}" ]]; then
    san_entries+=("DNS:${hostname}")
  fi
  if [[ -n "${ip_addresses}" ]]; then
    local ip
    for ip in ${ip_addresses}; do
      san_entries+=("IP:${ip}")
    done
  fi
  if [[ "${#san_entries[@]}" -gt 0 ]]; then
    local san_list
    san_list="$(IFS=,; echo "${san_entries[*]}")"
    openssl req -x509 -newkey rsa:4096 -nodes -keyout "${key_path}" -out "${cert_path}" -days 3650 \
      -subj "/CN=${hostname}" -addext "subjectAltName=${san_list}"
  else
    openssl req -x509 -newkey rsa:4096 -nodes -keyout "${key_path}" -out "${cert_path}" -days 3650 \
      -subj "/CN=${hostname}"
  fi
  chmod 600 "${key_path}"
  chmod 644 "${cert_path}"
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

  step "Prüfe Panel-Abhängigkeiten."
  local pkg_manager
  pkg_manager="$(detect_package_manager)"
  enable_universe_repo "${pkg_manager}"
  php_version="$(resolve_php_version "${php_version}" "${pkg_manager}")"

  local base_packages=(ca-certificates curl git unzip nginx openssl jq)
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

  local php_bin
  php_bin="$(resolve_php_binary "${php_version}")"
  install_composer "${php_bin}"

  ensure_service_enabled "php${php_version}-fpm.service"
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

  step "Lade Panel-Quellcode."
  if [[ -d "${install_dir}/.git" ]]; then
    git -C "${install_dir}" fetch --all --tags
    git -C "${install_dir}" checkout "${repo_ref}"
    git -C "${install_dir}" pull --ff-only
  elif [[ -d "${install_dir}" && -n "$(ls -A "${install_dir}" 2>/dev/null)" ]]; then
    fatal "Installationsverzeichnis ${install_dir} ist nicht leer und kein Git-Repository."
  else
    git clone "${repo_url}" "${install_dir}"
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

  if [[ -n "${app_github_token}" ]]; then
    echo "APP_GITHUB_TOKEN=\"${app_github_token}\"" >>"${core_dir}/.env.local"
  fi

  step "Setze Dateiberechtigungen."
  chown -R "${system_user}:${system_user}" "${install_dir}"
  chown -R "${web_user}:${web_user}" "${core_dir}/var" "${core_dir}/public"

  if [[ "${mode}" == "standalone" ]]; then
    configure_nginx "${web_hostname}" "${core_dir}/public" "${php_version}"
  fi

  if [[ "${run_migrations}" == "true" ]]; then
    step "Führe Datenbankmigrationen aus."
    "${php_bin}" "${core_dir}/bin/console" doctrine:migrations:migrate --no-interaction --allow-no-migration
    "${php_bin}" "${core_dir}/bin/console" cache:clear --env=prod
  else
    log "Migrationen wurden übersprungen."
  fi
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
# agent_id=<AGENT_ID>
# secret=<SECRET>
# api_url=https://panel.example.com
CONF
    chmod 600 /etc/easywi/agent.conf
  fi

  if [[ ! -f /etc/easywi/filesvc.conf ]]; then
    cat <<'CONF' >/etc/easywi/filesvc.conf
# Beispiel-Konfiguration für File Service (nach Registrierung ergänzen)
# agent_id=<AGENT_ID>
# secret=<SECRET>
# tls_cert=/etc/easywi/filesvc.crt
# tls_key=/etc/easywi/filesvc.key
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

install_agent_services() {
  local agent_id="$1"
  local secret="$2"
  local api_url="$3"
  local filesvc_base_dir="$4"
  local filesvc_cert="$5"
  local filesvc_key="$6"
  local filesvc_ca="$7"

  mkdir -p /etc/easywi
  cat <<CONF >/etc/easywi/agent.conf
agent_id=${agent_id}
secret=${secret}
api_url=${api_url}
CONF
  chmod 600 /etc/easywi/agent.conf

  cat <<CONF >/etc/easywi/filesvc.conf
agent_id=${agent_id}
secret=${secret}
listen_addr=:8444
base_dir=${filesvc_base_dir}
tls_cert=${filesvc_cert}
tls_key=${filesvc_key}
tls_ca=${filesvc_ca}
CONF
  chmod 600 /etc/easywi/filesvc.conf

  create_systemd_service "easywi-agent" "/usr/local/bin/easywi-agent --config /etc/easywi/agent.conf" "EasyWI Agent"
  create_systemd_service "easywi-filesvc" "/usr/local/bin/easywi-filesvc --config /etc/easywi/filesvc.conf" "EasyWI File Service"

  systemctl daemon-reload
  systemctl enable --now easywi-agent.service
  systemctl enable --now easywi-filesvc.service
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
  payload="${agent_id}\nPOST\n${path}\n${body_hash}\n${timestamp}\n${nonce}"
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
  if [[ "${register_status}" != "200" ]]; then
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
  local app_encryption_keys="${EASYWI_APP_ENCRYPTION_KEYS:-}"
  local agent_registration_token="${EASYWI_AGENT_REGISTRATION_TOKEN:-}"
  local app_github_token="${EASYWI_APP_GITHUB_TOKEN:-}"
  local run_migrations="${EASYWI_RUN_MIGRATIONS:-true}"
  local web_scheme="${EASYWI_WEB_SCHEME:-https}"

  echo
  echo "Panel-Setup: Wir laden den Quellcode, schreiben die .env.local,"
  echo "konfigurieren den Webserver (falls Standalone), und führen optional"
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
  prompt_value app_encryption_keys "APP_ENCRYPTION_KEYS (optional)" "${app_encryption_keys}"
  prompt_value agent_registration_token "AGENT_REGISTRATION_TOKEN (optional)" "${agent_registration_token}"
  prompt_value app_github_token "GitHub Token (optional)" "${app_github_token}"
  prompt_value run_migrations "Migrationen ausführen? (true/false)" "${run_migrations}"

  if [[ "${mode}" == "standalone" ]]; then
    prompt_value php_version "PHP-Version (8.4)" "${php_version}"
    prompt_value web_hostname "Servername" "${web_hostname}"
    prompt_value web_user "Web-User" "${web_user}"
    prompt_value web_scheme "Web-Schema (http/https)" "${web_scheme}"
  fi
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

  install_panel "${mode}" "${install_dir}" "${repo_url}" "${repo_ref}" "${db_driver}" "${db_system}" "${db_root_password}" "${db_host}" "${db_port}" "${db_name}" "${db_user}" "${db_password}" "${php_version}" "${web_hostname}" "${web_user}" "${system_user}" "${app_secret}" "${app_encryption_keys}" "${agent_registration_token}" "${app_github_token}" "${run_migrations}" "${web_scheme}"
}

run_agent_install() {
  local core_url="${EASYWI_CORE_URL:-${EASYWI_API_URL:-}}"
  local bootstrap_token="${EASYWI_BOOTSTRAP_TOKEN:-}"
  local agent_version="${EASYWI_AGENT_VERSION:-latest}"
  local filesvc_base_dir="${EASYWI_FILE_BASE_DIR:-/home}"
  local agent_name="${EASYWI_AGENT_NAME:-}"
  local agent_hostname="${EASYWI_AGENT_HOSTNAME:-}"
  local bootstrap_state_file="${EASYWI_BOOTSTRAP_STATE_FILE:-/etc/easywi/bootstrap-state.json}"
  local register_token="${EASYWI_AGENT_REGISTER_TOKEN:-}"
  local agent_id="${EASYWI_AGENT_ID:-}"
  local register_url="${EASYWI_AGENT_REGISTER_URL:-}"
  local filesvc_hostname="${EASYWI_FILESVC_HOSTNAME:-}"

  echo
  echo "Agent-Setup: Wir laden die Agent-Binaries."
  echo "Optional registrieren wir sie am Core und richten Systemd-Services ein."

  prompt_value core_url "Core API URL" "${core_url}"
  prompt_value bootstrap_token "Bootstrap Token" "${bootstrap_token}"
  prompt_value agent_version "Agent Version (latest oder Tag)" "${agent_version}"
  prompt_value filesvc_base_dir "File Service Base Directory" "${filesvc_base_dir}"
  prompt_value agent_name "Agent Name (optional)" "${agent_name}"
  prompt_value agent_hostname "Agent Hostname (optional)" "${agent_hostname}"
  prompt_value filesvc_hostname "File Service Hostname (optional)" "${filesvc_hostname}"

  local pkg_manager
  pkg_manager="$(detect_package_manager)"
  install_packages "${pkg_manager}" ca-certificates curl openssl jq

  if [[ -n "${core_url}" ]]; then
    core_url="${core_url%/}"
  fi

  if [[ -z "${core_url}" ]]; then
    log "Kein Core-URL angegeben -> Installiere nur die Binaries."
    install_agent_binaries_only "${agent_version}"
    return
  fi

  step "Installiere Agent-Binaries."
  install_agent_binaries_only "${agent_version}"

  if [[ -z "${agent_name}" ]]; then
    agent_name="$(hostname -f 2>/dev/null || hostname)"
  fi
  if [[ -z "${filesvc_hostname}" ]]; then
    filesvc_hostname="${agent_hostname:-${agent_name}}"
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
      install_agent_binaries_only "${agent_version}"
      return
    fi
  fi

  if [[ -z "${agent_secret}" ]]; then
    if [[ -z "${bootstrap_token}" ]]; then
      log "Kein Bootstrap-Token verfügbar -> Installiere nur die Binaries."
      install_agent_binaries_only "${agent_version}"
      return
    fi
    local agent_identity
    agent_identity="$(register_agent_with_core "${core_url}" "${bootstrap_token}" "${agent_version}" "${agent_name}" "${agent_hostname}" "${bootstrap_state_file}")"
    agent_id="${agent_identity%%|*}"
    agent_secret="${agent_identity#*|}"
  fi

  local filesvc_cert="/etc/easywi/filesvc.crt"
  local filesvc_key="/etc/easywi/filesvc.key"
  local filesvc_ca="/etc/easywi/filesvc.crt"
  local filesvc_ips
  filesvc_ips="$(hostname -I 2>/dev/null || true)"
  ensure_filesvc_certificates "${filesvc_cert}" "${filesvc_key}" "${filesvc_hostname}" "${filesvc_ips}"

  install_agent_services "${agent_id}" "${agent_secret}" "${core_url}" "${filesvc_base_dir}" "${filesvc_cert}" "${filesvc_key}" "${filesvc_ca}"
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
