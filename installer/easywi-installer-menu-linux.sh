#!/usr/bin/env bash
# EasyWI Linux Installer – v2.0.0
# Supports: Debian, Ubuntu, RHEL, CentOS, Rocky, AlmaLinux, Fedora,
#           openSUSE, SLES, Arch Linux, Manjaro, Alpine Linux
# Modes: Panel (Core), Agent, Panel+Agent, Agent-Update
set -Eeuo pipefail

VERSION="2.0.0"
INSTALLER_NAME="[easywi-installer]"
STEP_COUNTER=0
DEFAULT_PHP_VERSION="8.4"
MIN_PANEL_PHP_VERSION="8.4"
APT_UPDATED=0
EASYWI_GITHUB_REPO="Matlord93/webinterface"
EASYWI_GITHUB_RELEASE_BASE="https://github.com/${EASYWI_GITHUB_REPO}/releases"
LOG_FILE="${EASYWI_INSTALLER_LOG_FILE:-/var/log/easywi-installer.log}"
DIAG_LOG_FILE="${EASYWI_INSTALLER_DIAG_LOG_FILE:-/var/log/easywi-installer-diagnostics.log}"
INSTALLER_WARNINGS=()
INSTALLER_FAILURES=()
INSTALLER_PASSES=()
INSTALLER_MODE="menu"
MUSICBOT_PACKAGES_INSTALLED=()
MUSICBOT_PACKAGES_SKIPPED=()
MUSICBOT_PHP_CONFIGURED=()
MUSICBOT_NGINX_TEST_STATUS="nicht ausgeführt"
MUSICBOT_NGINX_RELOAD_STATUS="nicht ausgeführt"
MUSICBOT_PHP_FPM_RELOAD_STATUS="nicht ausgeführt"

# ---------------------------------------------------------------------------
# Logging helpers
# ---------------------------------------------------------------------------

log() {
  local line="${INSTALLER_NAME} $*"
  printf '%s\n' "${line}" >&2
  if [[ -n "${LOG_FILE:-}" ]]; then
    mkdir -p "$(dirname "${LOG_FILE}")" 2>/dev/null || true
    printf '%s %s\n' "$(date -Is 2>/dev/null || date)" "${line}" >>"${LOG_FILE}" 2>/dev/null || true
  fi
}
step()  { STEP_COUNTER=$((STEP_COUNTER + 1)); log "── Schritt ${STEP_COUNTER}: $*"; }
ok()    { INSTALLER_PASSES+=("$*"); log "   ✓ $*"; }
warn()  { INSTALLER_WARNINGS+=("$*"); log "   ⚠ $*"; }
fatal() { INSTALLER_FAILURES+=("$*"); log "FEHLER: $*"; exit 1; }

easywi_err_trap() {
  local status="$?" line="${1:-?}" command="${2:-?}"
  if [[ -n "${EASYWI_LAST_RUN_LOG:-}" && -s "${EASYWI_LAST_RUN_LOG}" ]]; then
    log "Letzte 80 Zeilen der Befehlsausgabe vor dem unerwarteten Fehler:"
    tail -n 80 "${EASYWI_LAST_RUN_LOG}" | sed "s/^/${INSTALLER_NAME}   | /" >&2 || true
  fi
  fatal "Unerwarteter Fehler in Zeile ${line}: ${command} (Exit-Code ${status})"
}
trap 'easywi_err_trap "${LINENO}" "${BASH_COMMAND}"' ERR

run_or_fatal() {
  local description="$1"; shift
  local command_text="$*" tmp_log
  tmp_log="$(mktemp -t easywi-installer.XXXXXX.log)"

  EASYWI_LAST_RUN_LOG="${tmp_log}"
  trap - ERR
  set +e
  "$@" >"${tmp_log}" 2>&1
  local status=$?
  set -e
  trap 'easywi_err_trap "${LINENO}" "${BASH_COMMAND}"' ERR
  EASYWI_LAST_RUN_LOG=""

  if [[ -n "${LOG_FILE:-}" && -s "${tmp_log}" ]]; then
    mkdir -p "$(dirname "${LOG_FILE}")" 2>/dev/null || true
    {
      printf '%s %s Befehl: %s\n' "$(date -Is 2>/dev/null || date)" "${INSTALLER_NAME}" "${command_text}"
      cat "${tmp_log}"
    } >>"${LOG_FILE}" 2>/dev/null || true
  fi

  if [[ "${status}" -eq 0 ]]; then
    rm -f "${tmp_log}"
    return 0
  fi

  log "FEHLER: ${description} (Exit-Code ${status})."
  if [[ -s "${tmp_log}" ]]; then
    log "Letzte 80 Zeilen der Befehlsausgabe ('${command_text}'):"
    tail -n 80 "${tmp_log}" | sed "s/^/${INSTALLER_NAME}   | /" >&2
  elif [[ -s "${LOG_FILE:-}" ]]; then
    log "Befehl erzeugte keine eigene Ausgabe. Letzte 80 Zeilen aus ${LOG_FILE}:"
    tail -n 80 "${LOG_FILE}" | sed "s/^/${INSTALLER_NAME}   | /" >&2
  else
    log "Der fehlgeschlagene Befehl hat keine Ausgabe erzeugt: ${command_text}"
  fi
  rm -f "${tmp_log}"
  exit "${status}"
}

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
  [[ -n "${current}" ]] && return 0
  is_tty || return 0
  [[ -n "${default}" ]] && prompt="${prompt} [${default}]"
  value="$(read_from_tty "${prompt}: ")"
  [[ -z "${value}" ]] && value="${default}"
  [[ -n "${value}" ]] && printf -v "${var_name}" '%s' "${value}"

  # Empty answers are valid for optional prompts (for example DB root
  # password uses socket auth). Do not let the final [[ ... ]] status abort
  # the installer while set -e is active.
  return 0
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


validate_agent_interval_seconds() {
  local value="${1:-}" name="${2:-Intervall}" min="${3:-2}" max="${4:-300}"
  [[ "${value}" =~ ^[0-9]+$ ]] || fatal "${name} muss eine Zahl zwischen ${min} und ${max} sein."
  (( value >= min && value <= max )) || fatal "${name} muss zwischen ${min} und ${max} Sekunden liegen."
  printf '%s' "${value}"
}

agent_interval_traffic_hint() {
  warn "Je niedriger das Intervall, desto schneller reagiert der Agent. Auf getrennten Systemen erhöht ein niedriges Intervall den Netzwerk-Traffic zwischen Agent und Panel."
}

# ---------------------------------------------------------------------------
# OS detection
# ---------------------------------------------------------------------------

get_os_field() {
  local key="$1" os_release_file="${EASYWI_OS_RELEASE_FILE:-/etc/os-release}"
  [[ -r "${os_release_file}" ]] \
    && awk -F= -v k="${key}" '$1==k{gsub(/"/,"",$2);print $2}' "${os_release_file}" | head -n1
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

apt_lock_holder_info() {
  local lock="$1" pid="" comm="" inode=""

  [[ -e "${lock}" ]] || return 1

  if command -v fuser >/dev/null 2>&1; then
    pid="$(fuser "${lock}" 2>/dev/null | awk '{print $1}' || true)"
  fi

  if [[ -z "${pid}" ]] && command -v lsof >/dev/null 2>&1; then
    pid="$(lsof -t "${lock}" 2>/dev/null | head -n1 || true)"
  fi

  if [[ -z "${pid}" && -r /proc/locks ]]; then
    inode="$(stat -Lc '%i' "${lock}" 2>/dev/null || true)"
    if [[ -n "${inode}" ]]; then
      pid="$(awk -v inode="${inode}" 'split($6, a, ":") == 3 && a[3] == inode { print $5; exit }' /proc/locks 2>/dev/null || true)"
    fi
  fi

  [[ -n "${pid}" ]] || return 1
  comm="$(ps -p "${pid}" -o comm= 2>/dev/null || true)"
  printf '%s|%s|%s\n' "${lock}" "${pid}" "${comm:-unbekannt}"
}

wait_for_apt_locks() {
  local timeout="${APT_LOCK_TIMEOUT:-900}"
  local interval="${APT_LOCK_WAIT_INTERVAL:-10}"
  local waited=0
  local locks=(
    /var/lib/dpkg/lock-frontend
    /var/lib/dpkg/lock
    /var/cache/apt/archives/lock
    /var/lib/apt/lists/lock
  )

  [[ "${timeout}" =~ ^[0-9]+$ ]] || timeout=900
  [[ "${interval}" =~ ^[0-9]+$ && "${interval}" -gt 0 ]] || interval=10

  while true; do
    local blocking_info=""
    local lock pid comm

    for lock in "${locks[@]}"; do
      blocking_info="$(apt_lock_holder_info "${lock}" || true)"
      [[ -n "${blocking_info}" ]] && break
    done

    [[ -z "${blocking_info}" ]] && return 0

    IFS='|' read -r lock pid comm <<< "${blocking_info}"

    if (( waited >= timeout )); then
      fatal "APT/dpkg ist seit ${timeout} Sekunden blockiert durch Prozess ${pid} (${comm}) auf ${lock}. Bitte laufenden Prozess prüfen und Installer erneut starten."
    fi

    warn "APT/dpkg ist gerade durch Prozess ${pid} (${comm}) blockiert (${lock}) – warte ${interval} Sekunden..."
    sleep "${interval}"
    waited=$((waited + interval))
  done
}

apt_update_once() {
  if [[ "${APT_UPDATED}" -eq 0 ]]; then
    wait_for_apt_locks
    run_or_fatal "apt-Paketlisten konnten nicht aktualisiert werden" \
      env DEBIAN_FRONTEND=noninteractive apt-get update
    APT_UPDATED=1
  fi
}

backup_conflicting_ondrej_php_sources() {
  # add-apt-repository on newer Ubuntu releases writes deb822 source files with
  # an inline Signed-By key. Older EasyWI runs used a keyring path for the same
  # PPA URL. APT rejects that mixed state with "Conflicting values set for
  # option Signed-By", so remove stale Ondrej/PHP source definitions before
  # adding the PPA again.
  local source_dir="/etc/apt/sources.list.d"
  local backup_dir
  backup_dir="${source_dir}/easywi-disabled-ondrej-php.$(date +%Y%m%d%H%M%S)"
  local source_file backed_up=false

  [[ -d "${source_dir}" ]] || return 0

  while IFS= read -r -d '' source_file; do
    if grep -Eq 'ppa\.launchpad(content)?\.net/ondrej/php|ondrej-ubuntu-php|ppa:ondrej/php' "${source_file}"; then
      if [[ "${backed_up}" == false ]]; then
        mkdir -p "${backup_dir}"
        backed_up=true
      fi
      mv "${source_file}" "${backup_dir}/$(basename "${source_file}")"
      warn "Vorhandene Ondrej/PHP-APT-Quelle wegen möglichem Signed-By-Konflikt deaktiviert: ${source_file}"
    fi
  done < <(find "${source_dir}" -maxdepth 1 -type f \( -name '*.list' -o -name '*.sources' \) -print0 2>/dev/null)
}

install_packages() {
  local manager="$1"; shift
  local packages=("$@")
  [[ ${#packages[@]} -eq 0 ]] && return

  log "Installiere Pakete: ${packages[*]}"
  case "${manager}" in
    apt)
      wait_for_apt_locks
      apt_update_once
      wait_for_apt_locks
      run_or_fatal "apt-Paketinstallation fehlgeschlagen: ${packages[*]}" \
        env DEBIAN_FRONTEND=noninteractive apt-get install -y "${packages[@]}"
      ;;
    dnf)
      run_or_fatal "dnf-Paketinstallation fehlgeschlagen: ${packages[*]}" \
        dnf install -y "${packages[@]}"
      ;;
    yum)
      run_or_fatal "yum-Paketinstallation fehlgeschlagen: ${packages[*]}" \
        yum install -y "${packages[@]}"
      ;;
    zypper)
      run_or_fatal "zypper-Paketinstallation fehlgeschlagen: ${packages[*]}" \
        zypper --non-interactive install --no-confirm "${packages[@]}"
      ;;
    pacman)
      run_or_fatal "pacman-Paketinstallation fehlgeschlagen: ${packages[*]}" \
        pacman -Sy --noconfirm --needed "${packages[@]}"
      ;;
    apk)
      run_or_fatal "apk-Paketinstallation fehlgeschlagen: ${packages[*]}" \
        apk add --no-cache "${packages[@]}"
      ;;
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
      local distro codename
      distro="$(get_os_field ID)"
      codename="$(get_os_field VERSION_CODENAME)"

      local native_php_version
      native_php_version="$(native_php_version_for_os 2>/dev/null || true)"
      if [[ "${distro}" == "ubuntu" && -n "${native_php_version}" && "${native_php_version}" == "${php_version}" ]]; then
        step "Nutze Ubuntu-PHP-Pakete (PHP ${php_version}); Ondrej/PHP-PPA wird übersprungen."
        backup_conflicting_ondrej_php_sources
        apt_update_once
        return
      fi

      step "Richte PHP-Repository ein (Ondrej/PHP)."
      if [[ "${distro}" == "ubuntu" ]]; then
        backup_conflicting_ondrej_php_sources
      fi
      apt_update_once
      install_packages "${manager}" ca-certificates curl gnupg

      if [[ "${distro}" == "ubuntu" ]]; then
        install_packages "${manager}" software-properties-common
        # Ubuntu >= 24.04 ships PHP 8.x in main; still add PPA for latest.
        # Clean up once more in case software-properties-common created or
        # normalized a source definition before add-apt-repository runs.
        backup_conflicting_ondrej_php_sources
        add-apt-repository -y ppa:ondrej/php >/dev/null 2>&1 || warn "PPA konnte nicht hinzugefügt werden – nutze Distro-Pakete."
      else
        # Debian: sury.org
        [[ -n "${codename}" ]] || fatal "Debian-Codename konnte nicht ermittelt werden (/etc/os-release: VERSION_CODENAME fehlt)."
        mkdir -p /usr/share/keyrings
        curl -fsSL https://packages.sury.org/php/apt.gpg \
          | gpg --batch --yes --dearmor -o /usr/share/keyrings/sury-php.gpg 2>/dev/null \
          || fatal "PHP-Repository-Key konnte nicht installiert werden (curl/gnupg prüfen)."
        echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ ${codename} main" \
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

normalize_php_version_for_os() {
  local requested="$1" manager="$2" family="$3"
  local distro codename

  if [[ "${manager}" == "apt" && "${family}" == "debian" ]]; then
    distro="$(get_os_field ID)"
    codename="$(get_os_field VERSION_CODENAME)"
    if [[ "${distro}" == "ubuntu" && "${codename}" == "resolute" ]]; then
      echo "8.5"
      return
    fi
  fi

  echo "${requested}"
}

resolve_php_version() {
  local requested="$1" manager="$2" family="$3"
  local ver; ver="$(printf '%s' "${requested}" | grep -oE '[0-9]+(\.[0-9]+)?' | head -n1 || true)"
  [[ -z "${ver}" ]] && ver="${DEFAULT_PHP_VERSION}"

  if [[ "${manager}" == "apt" ]]; then
    apt_update_once
    for candidate in "${ver}" 8.5 8.4; do
      php_version_ge "${candidate}" "${MIN_PANEL_PHP_VERSION}" || continue
      if apt-cache show "php${candidate}-cli" >/dev/null 2>&1; then
        [[ "${candidate}" != "${ver}" ]] && warn "PHP ${ver} nicht verfügbar – nutze ${candidate}."
        echo "${candidate}"; return
      fi
    done
    fatal "Keine unterstützte PHP-Version >=${MIN_PANEL_PHP_VERSION} in den Paketquellen gefunden."
  fi

  # Non-apt: accept as-is (repo was set up above)
  echo "${ver}"
}

apt_package_exists() {
  local pkg="$1" candidate=""
  candidate="$(apt-cache policy "${pkg}" 2>/dev/null | awk -F': ' '/Candidate:/{print $2; exit}')"
  [[ -n "${candidate}" && "${candidate}" != "(none)" ]] && return 0
  apt-cache show "${pkg}" >/dev/null 2>&1
}

apt_require_package() {
  local pkg="$1"
  if apt_package_exists "${pkg}"; then
    PHP_BASE_PKGS+=("${pkg}")
  else
    fatal "Benötigtes Paket nicht verfügbar: ${pkg}"
  fi
}

apt_add_package_if_exists() {
  local pkg="$1"
  if apt_package_exists "${pkg}"; then
    PHP_BASE_PKGS+=("${pkg}")
  else
    warn "Paket ${pkg} nicht verfügbar – wird übersprungen."
  fi
}

# Build the full list of PHP packages for a given manager/version
resolve_php_packages() {
  local manager="$1" php_version="$2" db_system="$3"
  PHP_BASE_PKGS=()
  PHP_FPM_SERVICE=""
  PHP_FPM_SOCKET=""

  case "${manager}" in
    apt)
      ensure_panel_php_version_supported "${php_version}"
      local module
      for module in cli common fpm mysql pgsql sqlite3 curl mbstring intl xml zip gd bcmath readline; do
        apt_require_package "php${php_version}-${module}"
      done
      apt_add_package_if_exists "php${php_version}-redis"
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

resolve_redis_packages() {
  local manager="$1"
  REDIS_SERVER_PKGS=()
  REDIS_SERVICES=(redis redis-server)

  case "${manager}" in
    apt)     REDIS_SERVER_PKGS=(redis-server) ;;
    dnf|yum) REDIS_SERVER_PKGS=(redis) ;;
    zypper)  REDIS_SERVER_PKGS=(redis) ;;
    pacman)  REDIS_SERVER_PKGS=(redis) ;;
    apk)     REDIS_SERVER_PKGS=(redis) ;;
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

certbot_packages() {
  local manager="$1"
  case "${manager}" in
    apt)     echo "certbot python3-certbot-nginx" ;;
    dnf|yum) echo "certbot python3-certbot-nginx" ;;
    zypper)  echo "python3-certbot python3-certbot-nginx" ;;
    pacman)  echo "certbot certbot-nginx" ;;
    apk)     echo "certbot py3-certbot-nginx" ;;
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
    systemctl daemon-reload 2>/dev/null || true
    systemctl enable "${svc}" 2>/dev/null || warn "Konnte ${svc} nicht für Autostart aktivieren."
    systemctl start "${svc}" 2>/dev/null || warn "Konnte ${svc} nicht starten."
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
    systemctl reload "${svc}" 2>/dev/null || systemctl restart "${svc}" 2>/dev/null || { warn "Konnte ${svc} nicht neuladen."; return 1; }
  elif has_openrc; then
    rc-service "${svc}" restart 2>/dev/null || { warn "Konnte ${svc} nicht neustarten."; return 1; }
  else
    return 1
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

configure_php_fpm_easywi_sandbox() {
  local php_fpm_service="$1"
  [[ -n "${php_fpm_service}" ]] || return 0
  has_systemctl || return 0

  local unit_name="${php_fpm_service%.service}.service"
  local dropin_dir="/etc/systemd/system/${unit_name}.d"
  local dropin_file="${dropin_dir}/easywi-etc.conf"

  step "Erlaube PHP-FPM Schreibzugriff auf /etc/easywi."
  mkdir -p /etc/easywi "${dropin_dir}"
  cat > "${dropin_file}" <<UNIT
# Managed by easywi-installer.
# Debian 13/Trixie hardens PHP-FPM with systemd ProtectSystem, which makes
# the global /etc tree read-only. EasyWI only needs write access to its own
# encrypted configuration directory.
[Service]
ReadWritePaths=/etc/easywi
UNIT

  systemctl daemon-reload 2>/dev/null || warn "systemd daemon-reload für PHP-FPM-Drop-in fehlgeschlagen."
  if systemctl list-unit-files "${unit_name}" >/dev/null 2>&1; then
    systemctl restart "${unit_name}" 2>/dev/null \
      || warn "Konnte ${unit_name} nach Sandbox-Anpassung nicht neustarten."
  else
    warn "PHP-FPM-Unit ${unit_name} nicht gefunden – Drop-in wurde dennoch geschrieben: ${dropin_file}"
  fi
  ok "PHP-FPM darf /etc/easywi schreiben: ${dropin_file}"
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

run_composer() {
  COMPOSER_ALLOW_SUPERUSER=1 \
  COMPOSER_NO_INTERACTION=1 \
  composer --no-interaction "$@" </dev/null
}

composer_diagnose_status() {
  local tmp_log status
  tmp_log="$(mktemp -t easywi-composer-diagnose.XXXXXX.log)"
  if ! command -v composer >/dev/null 2>&1; then
    echo "Composer diagnose: WARN/FAILED, composer not installed."
    record_warn "Composer ist nicht installiert; composer diagnose übersprungen."
    rm -f "${tmp_log}"
    return 0
  fi
  trap - ERR
  set +e
  run_composer diagnose >"${tmp_log}" 2>&1
  status=$?
  set -e
  trap 'easywi_err_trap "${LINENO}" "${BASH_COMMAND}"' ERR
  if [[ -s "${tmp_log}" ]]; then
    {
      printf '%s %s Composer diagnose output (Exit-Code %s):\n' "$(date -Is 2>/dev/null || date)" "${INSTALLER_NAME}" "${status}"
      cat "${tmp_log}"
    } >>"${LOG_FILE}" 2>/dev/null || true
  fi
  if [[ "${status}" -eq 0 ]]; then
    echo "Composer diagnose: OK"
  else
    echo "Composer diagnose: WARN/FAILED, siehe Log"
    record_warn "composer diagnose meldete Fehler; Details siehe ${LOG_FILE}."
  fi
  rm -f "${tmp_log}"
}

install_composer() {
  local php_bin="$1"
  if command -v composer >/dev/null 2>&1; then
    ok "Composer bereits installiert – aktualisiere."
    run_composer self-update --quiet 2>/dev/null || true
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
  [[ -f "${config_file}" ]] && backup_file_once "${config_file}"

  cat > "${config_file}" <<NGINX
## EasyWI Panel – managed by easywi-installer
server {
    listen 80;
    server_name ${server_name};
    client_max_body_size 500M;

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
    MUSICBOT_NGINX_TEST_STATUS="erfolgreich"
    if service_reload nginx; then
      MUSICBOT_NGINX_RELOAD_STATUS="erfolgreich"
    else
      MUSICBOT_NGINX_RELOAD_STATUS="fehlgeschlagen"
    fi
  else
    MUSICBOT_NGINX_TEST_STATUS="fehlgeschlagen"
    warn "nginx -t schlug fehl – Konfiguration prüfen."
  fi
  ok "Nginx konfiguriert: ${config_file}"
}

set_nginx_client_max_body_size() {
  local config_file="$1" size="${2:-500M}"
  [[ -f "${config_file}" ]] || return 0

  backup_file_once "${config_file}"
  if grep -Eq '^[[:space:]]*client_max_body_size[[:space:]]+' "${config_file}"; then
    sed -i -E "0,/^[[:space:]]*client_max_body_size[[:space:]]+[^;]+;/{s//    client_max_body_size ${size};/}" "${config_file}"
  else
    awk -v line="    client_max_body_size ${size};" '
      !inserted && /^[[:space:]]*server[[:space:]]*\{/ {
        print
        print line
        inserted=1
        next
      }
      { print }
    ' "${config_file}" >"${config_file}.easywi.tmp"
    mv "${config_file}.easywi.tmp" "${config_file}"
  fi
}

detect_php_ini_versions() {
  local ini version
  shopt -s nullglob
  for ini in /etc/php/*/fpm/php.ini /etc/php/*/cli/php.ini; do
    version="${ini#/etc/php/}"
    version="${version%%/*}"
    [[ -n "${version}" ]] && printf '%s\n' "${version}"
  done
  shopt -u nullglob
}

reload_php_fpm_for_versions() {
  local versions=("$@") version svc failed=0 attempted=0
  for version in "${versions[@]}"; do
    svc="php${version}-fpm"
    if has_systemctl && systemctl list-unit-files "${svc}.service" >/dev/null 2>&1; then
      attempted=1
      systemctl reload "${svc}.service" 2>/dev/null || systemctl restart "${svc}.service" 2>/dev/null || failed=1
    elif has_openrc; then
      attempted=1
      rc-service "${svc}" restart 2>/dev/null || failed=1
    fi
  done
  if ((attempted == 0)); then
    MUSICBOT_PHP_FPM_RELOAD_STATUS="kein PHP-FPM-Service gefunden"
  elif ((failed == 0)); then
    MUSICBOT_PHP_FPM_RELOAD_STATUS="erfolgreich"
  else
    MUSICBOT_PHP_FPM_RELOAD_STATUS="teilweise/fehlgeschlagen"
  fi
}

configure_musicbot_panel_limits() {
  local php_version="${1:-}" nginx_config="${2:-}" ini key value pair versions=()
  local settings=(
    'upload_max_filesize=500M'
    'post_max_size=500M'
    'max_file_uploads=50'
    'max_execution_time=300'
    'max_input_time=300'
    'memory_limit=512M'
  )

  step "Setze Musicbot Upload-Limits für PHP und Nginx."

  if [[ -n "${php_version}" ]]; then
    versions+=("${php_version}")
  fi
  while IFS= read -r php_version; do
    [[ -n "${php_version}" ]] && versions+=("${php_version}")
  done < <(detect_php_ini_versions | awk 'NF && !seen[$0]++')

  if ((${#versions[@]})); then
    mapfile -t versions < <(printf '%s\n' "${versions[@]}" | awk 'NF && !seen[$0]++')
  fi

  for php_version in "${versions[@]}"; do
    for ini in "/etc/php/${php_version}/fpm/php.ini" "/etc/php/${php_version}/cli/php.ini"; do
      [[ -f "${ini}" ]] || continue
      backup_file_once "${ini}"
      for pair in "${settings[@]}"; do
        key="${pair%%=*}"
        value="${pair#*=}"
        set_ini_value "${ini}" "${key}" "${value}"
      done
      MUSICBOT_PHP_CONFIGURED+=("${ini}")
    done
  done

  if ((${#versions[@]})); then
    reload_php_fpm_for_versions "${versions[@]}"
  else
    MUSICBOT_PHP_FPM_RELOAD_STATUS="keine PHP-Version erkannt"
    warn "Keine PHP-FPM/CLI php.ini unter /etc/php erkannt."
  fi

  if [[ -z "${nginx_config}" ]]; then
    nginx_config="/etc/nginx/sites-available/easywi.conf"
    [[ -f "${nginx_config}" ]] || nginx_config="/etc/nginx/conf.d/easywi.conf"
  fi
  if [[ -f "${nginx_config}" ]]; then
    set_nginx_client_max_body_size "${nginx_config}" "500M"
    if nginx -t 2>/dev/null; then
      MUSICBOT_NGINX_TEST_STATUS="erfolgreich"
      if service_reload nginx; then
        MUSICBOT_NGINX_RELOAD_STATUS="erfolgreich"
      else
        MUSICBOT_NGINX_RELOAD_STATUS="fehlgeschlagen"
      fi
    else
      MUSICBOT_NGINX_TEST_STATUS="fehlgeschlagen"
      warn "nginx -t schlug nach client_max_body_size-Anpassung fehl."
    fi
  else
    warn "EasyWI nginx-Konfiguration nicht gefunden; client_max_body_size konnte nicht gesetzt werden."
  fi

  print_musicbot_validation "${versions[*]:-unbekannt}" "${nginx_config}"
}

install_musicbot_system_dependencies() {
  local manager="${1:-$(detect_package_manager)}"
  local packages=() optional=() pkg

  step "Installiere Musicbot-/TeamSpeak-Bridge-Systemabhängigkeiten."
  MUSICBOT_PACKAGES_INSTALLED=()
  MUSICBOT_PACKAGES_SKIPPED=()

  case "${manager}" in
    apt)
      apt_update_once
      packages=(ffmpeg pulseaudio pulseaudio-utils xvfb libnss3 libxss1 libatk1.0-0
        libatk-bridge2.0-0 libcups2 libdrm2 libxkbcommon0 libxcomposite1 libxdamage1
        libxfixes3 libxrandr2 libgbm1 libgtk-3-0 libglib2.0-0 dbus-x11 ca-certificates
        curl unzip tar jq psmisc procps alsa-utils yt-dlp)
      optional=(x11vnc xauth)
      if apt_package_exists libasound2t64; then
        packages+=(libasound2t64)
      elif apt_package_exists libasound2; then
        packages+=(libasound2)
      else
        MUSICBOT_PACKAGES_SKIPPED+=(libasound2t64 libasound2)
      fi
      for pkg in "${packages[@]}" "${optional[@]}"; do
        if apt_package_exists "${pkg}"; then
          MUSICBOT_PACKAGES_INSTALLED+=("${pkg}")
        else
          MUSICBOT_PACKAGES_SKIPPED+=("${pkg}")
        fi
      done
      if ((${#MUSICBOT_PACKAGES_INSTALLED[@]})); then
        install_packages "${manager}" "${MUSICBOT_PACKAGES_INSTALLED[@]}"
      fi
      ;;
    *)
      warn "Musicbot-Systempakete werden derzeit nur für Debian/Ubuntu automatisch robust aufgelöst (Paketmanager: ${manager})."
      MUSICBOT_PACKAGES_SKIPPED+=(ffmpeg yt-dlp pulseaudio pulseaudio-utils xvfb x11vnc xauth libnss3 libxss1 libasound2 libatk1.0-0 libatk-bridge2.0-0 libcups2 libdrm2 libxkbcommon0 libxcomposite1 libxdamage1 libxfixes3 libxrandr2 libgbm1 libgtk-3-0 libglib2.0-0 dbus-x11 ca-certificates curl unzip tar jq psmisc procps alsa-utils)
      ;;
  esac

  ok "Musicbot-Paketprüfung abgeschlossen."
}

print_musicbot_validation() {
  local php_versions="${1:-unbekannt}" nginx_config="${2:-}" size="unbekannt"
  if [[ -f "${nginx_config}" ]]; then
    size="$(awk '/^[[:space:]]*client_max_body_size[[:space:]]+/{gsub(/;/,"",$2); print $2; exit}' "${nginx_config}")"
    [[ -n "${size}" ]] || size="nicht gesetzt"
  fi
  log "Musicbot-Validierung: PHP-Version(en): ${php_versions}"
  log "Musicbot-Validierung: PHP-Werte: upload_max_filesize=500M, post_max_size=500M, max_file_uploads=50, max_execution_time=300, max_input_time=300, memory_limit=512M"
  log "Musicbot-Validierung: nginx client_max_body_size=${size} (${nginx_config:-keine Konfiguration})"
  log "Musicbot-Validierung: nginx -t=${MUSICBOT_NGINX_TEST_STATUS}; nginx reload=${MUSICBOT_NGINX_RELOAD_STATUS}; PHP-FPM reload/restart=${MUSICBOT_PHP_FPM_RELOAD_STATUS}"
  log "Musicbot-Validierung: installierte/angeforderte Pakete: ${MUSICBOT_PACKAGES_INSTALLED[*]:-(keine)}"
  log "Musicbot-Validierung: optionale/übersprungene Pakete: ${MUSICBOT_PACKAGES_SKIPPED[*]:-(keine)}"
}

detect_existing_php_fpm_socket() {
  local socket
  for socket in /run/php/php*-fpm.sock /run/php-fpm/www.sock /run/php-fpm/php-fpm.sock; do
    [[ -S "${socket}" ]] && { printf '%s\n' "${socket}"; return 0; }
  done
  return 1
}

update_default_uri() {
  local env_path="$1" default_uri="$2"
  local contents="" line
  line="DEFAULT_URI=${default_uri}"
  [[ -f "${env_path}" ]] && contents="$(cat "${env_path}")"

  if [[ "${contents}" =~ (^|$'\n')DEFAULT_URI= ]]; then
    printf '%s\n' "${contents}" | sed "s#^DEFAULT_URI=.*#${line}#" > "${env_path}"
  else
    { [[ -n "${contents}" ]] && printf '%s\n' "${contents}"; printf '%s\n' "${line}"; } > "${env_path}"
  fi
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

  local messenger_dsn="${EASYWI_MESSENGER_TRANSPORT_DSN:-doctrine://default}"
  local mailer_dsn="${EASYWI_MAILER_DSN:-smtp://localhost}"
  local release_repository="${EASYWI_CORE_RELEASE_REPOSITORY:-${EASYWI_GITHUB_REPO}}"
  local release_channel="${EASYWI_CORE_RELEASE_CHANNEL:-dev}"
  local core_version="${EASYWI_CORE_VERSION:-latest}"
  local update_releases_dir="${EASYWI_CORE_UPDATE_RELEASES_DIR:-${install_dir}/releases}"
  local update_current_symlink="${EASYWI_CORE_UPDATE_CURRENT_SYMLINK:-${install_dir}/current}"
  local update_lock_file="${EASYWI_CORE_UPDATE_LOCK_FILE:-${install_dir}/tmp/webinterface_update.lock}"

  mkdir -p "${update_releases_dir}" "$(dirname "${update_lock_file}")"

  step "Schreibe ${env_path}."
  cat > "${env_path}" <<ENV
APP_ENV=prod
APP_SECRET="${app_secret}"
DATABASE_URL="${db_url}"
DEFAULT_URI=${default_uri}
MESSENGER_TRANSPORT_DSN=${messenger_dsn}
MAILER_DSN=${mailer_dsn}
REDIS_DSN=redis://127.0.0.1:6379
TRUSTED_PROXIES=127.0.0.1
AGENT_SIGNATURE_SKEW_SECONDS=120
AGENT_NONCE_TTL_SECONDS=180
APP_AGENT_RELEASE_REPOSITORY=${release_repository}
APP_CHANGELOG_REPOSITORY=${release_repository}
APP_CORE_RELEASE_REPOSITORY=${release_repository}
APP_CORE_RELEASE_CHANNEL=${release_channel}
APP_CORE_VERSION=${core_version}
APP_CORE_UPDATE_REPOSITORY=${release_repository}
APP_CORE_UPDATE_INSTALL_DIR=${install_dir}
APP_CORE_UPDATE_RELEASES_DIR=${update_releases_dir}
APP_CORE_UPDATE_CURRENT_SYMLINK=${update_current_symlink}
APP_CORE_UPDATE_LOCK_FILE=${update_lock_file}
EASYWI_DB_CONFIG_PATH=/etc/easywi/db.json
EASYWI_SECRET_KEY_PATH=/etc/easywi/secret.key
APP_ENCRYPTION_ACTIVE_KEY_ID=${key_id}
APP_ENCRYPTION_KEYS=${key_id}:${key_material}
ENV
  [[ -n "${registration_token}" ]] && printf 'AGENT_REGISTRATION_TOKEN="%s"\n' "${registration_token}" >> "${env_path}"
  chmod 600 "${env_path}"
  ok ".env.local geschrieben."
}


set_easywi_config_permissions() {
  local runtime_user="$1" readable_group="$2"
  [[ -z "${runtime_user}" || -z "${readable_group}" ]] && return 0

  step "Setze Rechte für /etc/easywi-Konfiguration."
  install -d -o "${runtime_user}" -g "${readable_group}" -m 2775 /etc/easywi \
    || warn "Konnte /etc/easywi nicht für ${runtime_user}:${readable_group} anlegen."
  chown "${runtime_user}:${readable_group}" /etc/easywi 2>/dev/null || warn "Konnte Besitzer für /etc/easywi nicht setzen."
  chmod 2775 /etc/easywi 2>/dev/null || warn "Konnte Schreibrechte für /etc/easywi nicht setzen."

  local path
  for path in /etc/easywi/secret.key /etc/easywi/db.json; do
    [[ -f "${path}" ]] || continue
    chown "${runtime_user}:${readable_group}" "${path}" 2>/dev/null || warn "Konnte Besitzer für ${path} nicht setzen."
    chmod 660 "${path}" 2>/dev/null || warn "Konnte Rechte für ${path} nicht setzen."
  done
}

set_panel_file_permissions() {
  local install_dir="$1" core_dir="$2" system_user="$3" web_group="$4"

  step "Setze Panel-Dateiberechtigungen."
  chown -R "${system_user}:${system_user}" "${install_dir}"

  # nginx/PHP-FPM and EasyWI workers must be able to read public/ and write
  # runtime/setup state. The setgid bit keeps newly created files in the web
  # group even after console commands, migrations or cache warmups.
  install -d -o "${system_user}" -g "${web_group}" -m 2775 \
    "${core_dir}/var" "${core_dir}/var/cache" "${core_dir}/var/log" \
    "${core_dir}/var/easywi" "${core_dir}/srv/setup" "${core_dir}/srv/setup/state"
  chown -R "${system_user}:${web_group}" "${core_dir}/var" "${core_dir}/srv/setup" "${core_dir}/public" 2>/dev/null || true
  if [[ -f "${core_dir}/.env.local" ]]; then
    chown "${system_user}:${web_group}" "${core_dir}/.env.local" 2>/dev/null || true
    chmod 640 "${core_dir}/.env.local" 2>/dev/null || true
  fi
  find "${core_dir}/var" "${core_dir}/srv/setup" -type d -exec chmod 2775 {} \; 2>/dev/null || true
  find "${core_dir}/var" "${core_dir}/srv/setup" -type f -exec chmod 664 {} \; 2>/dev/null || true
  chmod -R g+rwX "${core_dir}/var" "${core_dir}/srv/setup" 2>/dev/null || true
  chmod -R g+rX "${core_dir}/public" 2>/dev/null || true
  set_easywi_config_permissions "${system_user}" "${web_group}"
}

write_db_config() {
  local db_driver="$1" db_host="$2" db_port="$3" db_name="$4" db_user="$5" db_password="$6"

  if [[ "${db_driver}" != "mysql" ]]; then
    fatal "Der Core unterstützt im Installer aktuell nur MySQL/MariaDB als persistente DB-Konfiguration."
  fi

  step "Schreibe DB-Konfiguration nach /etc/easywi/db.json."
  mkdir -p /etc/easywi
  EASYWI_INSTALLER_DB_HOST="${db_host}" \
  EASYWI_INSTALLER_DB_PORT="${db_port}" \
  EASYWI_INSTALLER_DB_NAME="${db_name}" \
  EASYWI_INSTALLER_DB_USER="${db_user}" \
  EASYWI_INSTALLER_DB_PASSWORD="${db_password}" \
  php <<'PHP' || fatal "DB-Konfiguration konnte nicht verschlüsselt geschrieben werden."
<?php
$keyPath = '/etc/easywi/secret.key';
$outPath = '/etc/easywi/db.json';

$contents = trim((string) file_get_contents($keyPath));
if ($contents === '') {
    fwrite(STDERR, "secret.key is empty\n");
    exit(1);
}

$keyMaterial = null;
$decodedJson = json_decode($contents, true);
if (is_array($decodedJson)) {
    $activeKeyId = isset($decodedJson['active_key_id']) && is_string($decodedJson['active_key_id'])
        ? trim($decodedJson['active_key_id'])
        : '';
    if (isset($decodedJson['keys']) && is_array($decodedJson['keys'])) {
        if ($activeKeyId !== '' && isset($decodedJson['keys'][$activeKeyId]) && is_string($decodedJson['keys'][$activeKeyId])) {
            $keyMaterial = trim($decodedJson['keys'][$activeKeyId]);
        } else {
            foreach ($decodedJson['keys'] as $candidate) {
                if (is_string($candidate) && trim($candidate) !== '') {
                    $keyMaterial = trim($candidate);
                    break;
                }
            }
        }
    } elseif (isset($decodedJson['keyring']) && is_string($decodedJson['keyring'])) {
        $contents = trim($decodedJson['keyring']);
    }
}

if ($keyMaterial === null) {
    if (str_contains($contents, ':')) {
        [, $keyMaterial] = array_pad(explode(':', $contents, 2), 2, '');
        $keyMaterial = trim($keyMaterial);
    } else {
        $keyMaterial = $contents;
    }
}

$key = base64_decode($keyMaterial, true);
if ($key === false) {
    $key = $keyMaterial;
}
$keyBytes = defined('SODIUM_CRYPTO_SECRETBOX_KEYBYTES') ? SODIUM_CRYPTO_SECRETBOX_KEYBYTES : 32;
if (strlen($key) !== $keyBytes) {
    fwrite(STDERR, "secret.key has invalid length\n");
    exit(1);
}

$payload = array_filter([
    'host' => getenv('EASYWI_INSTALLER_DB_HOST') ?: '',
    'port' => ctype_digit((string) getenv('EASYWI_INSTALLER_DB_PORT')) ? (int) getenv('EASYWI_INSTALLER_DB_PORT') : null,
    'dbname' => getenv('EASYWI_INSTALLER_DB_NAME') ?: '',
    'user' => getenv('EASYWI_INSTALLER_DB_USER') ?: '',
    'password' => getenv('EASYWI_INSTALLER_DB_PASSWORD') ?: '',
    'charset' => 'utf8mb4',
], static fn ($value) => $value !== null && $value !== '');

$plaintext = json_encode($payload, JSON_UNESCAPED_SLASHES);
if ($plaintext === false) {
    fwrite(STDERR, "failed to encode db payload\n");
    exit(1);
}

if (function_exists('sodium_crypto_secretbox')) {
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $encrypted = [
        'nonce' => base64_encode($nonce),
        'ciphertext' => base64_encode(sodium_crypto_secretbox($plaintext, $nonce, $key)),
    ];
} else {
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($ciphertext === false) {
        fwrite(STDERR, "failed to encrypt db payload\n");
        exit(1);
    }
    $encrypted = [
        'backend' => 'openssl',
        'nonce' => base64_encode($nonce),
        'ciphertext' => base64_encode($ciphertext),
        'tag' => base64_encode($tag),
    ];
}

if (file_put_contents($outPath, json_encode($encrypted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
    fwrite(STDERR, "failed to write db config\n");
    exit(1);
}
chmod($outPath, 0600);
PHP
  ok "DB-Konfiguration geschrieben: /etc/easywi/db.json"
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
# Symfony setup helpers
# ---------------------------------------------------------------------------

run_panel_migrations() {
  local php_bin="$1" console="$2"
  local log_file; log_file="$(mktemp)"
  local core_dir install_dir release_repository release_channel core_version update_releases_dir update_current_symlink update_lock_file
  core_dir="$(cd "$(dirname "${console}")/.." && pwd)"
  install_dir="$(dirname "${core_dir}")"
  release_repository="${APP_CORE_RELEASE_REPOSITORY:-${EASYWI_CORE_RELEASE_REPOSITORY:-${EASYWI_GITHUB_REPO}}}"
  release_channel="${APP_CORE_RELEASE_CHANNEL:-${EASYWI_CORE_RELEASE_CHANNEL:-dev}}"
  core_version="${APP_CORE_VERSION:-${EASYWI_CORE_VERSION:-latest}}"
  update_releases_dir="${APP_CORE_UPDATE_RELEASES_DIR:-${install_dir}/releases}"
  update_current_symlink="${APP_CORE_UPDATE_CURRENT_SYMLINK:-${install_dir}/current}"
  update_lock_file="${APP_CORE_UPDATE_LOCK_FILE:-${install_dir}/tmp/webinterface_update.lock}"
  mkdir -p "${update_releases_dir}" "$(dirname "${update_lock_file}")"

  local -a console_env=(
    APP_ENV=prod
    MAILER_DSN="${MAILER_DSN:-${EASYWI_MAILER_DSN:-smtp://localhost}}"
    AGENT_SIGNATURE_SKEW_SECONDS="${AGENT_SIGNATURE_SKEW_SECONDS:-120}"
    AGENT_NONCE_TTL_SECONDS="${AGENT_NONCE_TTL_SECONDS:-180}"
    APP_AGENT_RELEASE_REPOSITORY="${APP_AGENT_RELEASE_REPOSITORY:-${release_repository}}"
    APP_CHANGELOG_REPOSITORY="${APP_CHANGELOG_REPOSITORY:-${release_repository}}"
    APP_CORE_RELEASE_REPOSITORY="${release_repository}"
    APP_CORE_RELEASE_CHANNEL="${release_channel}"
    APP_CORE_VERSION="${core_version}"
    APP_CORE_UPDATE_REPOSITORY="${APP_CORE_UPDATE_REPOSITORY:-${release_repository}}"
    APP_CORE_UPDATE_RELEASES_DIR="${update_releases_dir}"
    APP_CORE_UPDATE_CURRENT_SYMLINK="${update_current_symlink}"
    APP_CORE_UPDATE_LOCK_FILE="${update_lock_file}"
  )

  step "Führe Datenbankmigrationen aus."
  if env "${console_env[@]}" "${php_bin}" "${console}" doctrine:migrations:migrate \
      --no-interaction --allow-no-migration >"${log_file}" 2>&1; then
    tail -20 "${log_file}" >&2 || true
    rm -f "${log_file}"
    rm -rf "${core_dir}/var/cache/prod"
    env "${console_env[@]}" "${php_bin}" "${console}" cache:clear --env=prod --quiet
    env "${console_env[@]}" "${php_bin}" "${console}" assets:install --env=prod --quiet
    ok "Migrationen abgeschlossen."
    return 0
  fi

  warn "Datenbankmigrationen fehlgeschlagen. Letzte Ausgabe:"
  tail -40 "${log_file}" >&2 || true
  rm -f "${log_file}"
  fatal "Installation abgebrochen, damit der Agent nicht gegen ein unvollständig migriertes Panel registriert wird."
}


ensure_agent_bootstrap_schema() {
  local db_driver="$1" db_host="$2" db_port="$3" db_name="$4" db_user="$5" db_password="$6"
  [[ "${db_driver}" == "mysql" ]] || return 0

  step "Prüfe Agent-Registrierungs-Tabellen."
  EASYWI_INSTALLER_DB_HOST="${db_host}" \
  EASYWI_INSTALLER_DB_PORT="${db_port}" \
  EASYWI_INSTALLER_DB_NAME="${db_name}" \
  EASYWI_INSTALLER_DB_USER="${db_user}" \
  EASYWI_INSTALLER_DB_PASSWORD="${db_password}" \
  php <<'PHP' || fatal "Agent-Bootstrap-Tabellen konnten nicht geprüft/angelegt werden."
<?php
$host = getenv('EASYWI_INSTALLER_DB_HOST') ?: '127.0.0.1';
$port = getenv('EASYWI_INSTALLER_DB_PORT') ?: null;
$dbname = getenv('EASYWI_INSTALLER_DB_NAME') ?: '';
$user = getenv('EASYWI_INSTALLER_DB_USER') ?: '';
$password = getenv('EASYWI_INSTALLER_DB_PASSWORD') ?: '';

$dsn = sprintf('mysql:host=%s;%sdbname=%s;charset=utf8mb4', $host, $port !== null && $port !== '' ? 'port=' . $port . ';' : '', $dbname);
$pdo = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$pdo->exec("CREATE TABLE IF NOT EXISTS agents (
    id VARCHAR(64) NOT NULL,
    name VARCHAR(120) DEFAULT NULL,
    secret_payload JSON NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    last_heartbeat_at DATETIME DEFAULT NULL,
    last_seen_at DATETIME DEFAULT NULL,
    last_heartbeat_ip VARCHAR(45) DEFAULT NULL,
    last_heartbeat_version VARCHAR(40) DEFAULT NULL,
    last_heartbeat_stats JSON DEFAULT NULL,
    metadata JSON DEFAULT NULL,
    service_base_url VARCHAR(255) DEFAULT NULL,
    service_api_token_encrypted LONGTEXT DEFAULT NULL,
    disk_scan_interval_seconds INT NOT NULL DEFAULT 180,
    disk_warning_percent INT NOT NULL DEFAULT 85,
    disk_hard_block_percent INT NOT NULL DEFAULT 120,
    node_disk_protection_threshold_percent INT NOT NULL DEFAULT 5,
    node_disk_protection_override_until DATETIME DEFAULT NULL,
    roles JSON NOT NULL,
    status VARCHAR(20) NOT NULL,
    job_concurrency INT NOT NULL DEFAULT 50,
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

$pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT NOT NULL,
    actor_id INT DEFAULT NULL,
    action VARCHAR(120) NOT NULL,
    payload JSON NOT NULL,
    created_at DATETIME NOT NULL,
    hash_prev VARCHAR(64) DEFAULT NULL,
    hash_current VARCHAR(64) NOT NULL,
    PRIMARY KEY(id),
    INDEX idx_audit_logs_actor (actor_id),
    INDEX idx_audit_logs_created_at (created_at)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

$pdo->exec("CREATE TABLE IF NOT EXISTS agent_bootstrap_tokens (
    id INT AUTO_INCREMENT NOT NULL,
    created_by_id INT DEFAULT NULL,
    name VARCHAR(190) NOT NULL,
    token_prefix VARCHAR(16) NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    encrypted_token JSON NOT NULL,
    bound_cidr VARCHAR(64) DEFAULT NULL,
    bound_node_name VARCHAR(190) DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    expires_at DATETIME DEFAULT NULL,
    used_at DATETIME DEFAULT NULL,
    revoked_at DATETIME DEFAULT NULL,
    invalidated_at DATETIME DEFAULT NULL,
    last_used_at DATETIME DEFAULT NULL,
    attempts_count INT NOT NULL DEFAULT 0,
    max_attempts INT NOT NULL DEFAULT 5,
    PRIMARY KEY(id),
    INDEX idx_agent_bootstrap_tokens_token_hash (token_hash),
    INDEX idx_agent_bootstrap_tokens_created_by (created_by_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

$pdo->exec("CREATE TABLE IF NOT EXISTS agent_registration_tokens (
    id INT AUTO_INCREMENT NOT NULL,
    bootstrap_token_id INT DEFAULT NULL,
    agent_id VARCHAR(64) NOT NULL,
    token_prefix VARCHAR(16) NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    encrypted_token JSON NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME DEFAULT NULL,
    PRIMARY KEY(id),
    INDEX idx_agent_registration_tokens_token_hash (token_hash),
    INDEX idx_agent_registration_tokens_bootstrap (bootstrap_token_id),
    INDEX idx_agent_registration_tokens_agent (agent_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

$columnExists = static function (PDO $pdo, string $table, string $column) use ($dbname): bool {
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    $stmt->execute([$dbname, $table, $column]);

    return (bool) $stmt->fetchColumn();
};

$ensureColumn = static function (PDO $pdo, callable $columnExists, string $table, string $column, string $definition): void {
    if (!$columnExists($pdo, $table, $column)) {
        $pdo->exec(sprintf('ALTER TABLE %s ADD %s %s', $table, $column, $definition));
    }
};

$ensureColumn($pdo, $columnExists, 'agents', 'service_base_url', 'VARCHAR(255) DEFAULT NULL');
$ensureColumn($pdo, $columnExists, 'agents', 'service_api_token_encrypted', 'LONGTEXT DEFAULT NULL');
$ensureColumn($pdo, $columnExists, 'agents', 'disk_scan_interval_seconds', 'INT NOT NULL DEFAULT 180');
$ensureColumn($pdo, $columnExists, 'agents', 'disk_warning_percent', 'INT NOT NULL DEFAULT 85');
$ensureColumn($pdo, $columnExists, 'agents', 'disk_hard_block_percent', 'INT NOT NULL DEFAULT 120');
$ensureColumn($pdo, $columnExists, 'agents', 'node_disk_protection_threshold_percent', 'INT NOT NULL DEFAULT 5');
$ensureColumn($pdo, $columnExists, 'agents', 'node_disk_protection_override_until', 'DATETIME DEFAULT NULL');
$ensureColumn($pdo, $columnExists, 'agents', 'job_concurrency', 'INT NOT NULL DEFAULT 50');

$ensureColumn($pdo, $columnExists, 'agent_bootstrap_tokens', 'invalidated_at', 'DATETIME DEFAULT NULL');
$ensureColumn($pdo, $columnExists, 'agent_bootstrap_tokens', 'last_used_at', 'DATETIME DEFAULT NULL');
$ensureColumn($pdo, $columnExists, 'agent_bootstrap_tokens', 'attempts_count', 'INT NOT NULL DEFAULT 0');
$ensureColumn($pdo, $columnExists, 'agent_bootstrap_tokens', 'max_attempts', 'INT NOT NULL DEFAULT 5');
$ensureColumn($pdo, $columnExists, 'agent_registration_tokens', 'bootstrap_token_id', 'INT DEFAULT NULL');
PHP
  ok "Agent-Registrierungs-Tabellen sind bereit."
}

create_agent_bootstrap_token() {
  local php_bin="$1" console="$2" token="$3"
  [[ -z "${token}" ]] && return 0

  step "Erstelle Agent-Bootstrap-Token für automatische Registrierung."
  "${php_bin}" "${console}" app:agent:bootstrap-token:create \
    --token="${token}" \
    --name="Installer bootstrap $(date -u +'%Y-%m-%d %H:%M UTC')" \
    --expires-in=30 \
    --max-attempts=5 \
    --no-interaction \
    --quiet \
    || fatal "Agent-Bootstrap-Token konnte nicht erstellt werden. Prüfe Migrationen und /etc/easywi/secret.key."
  ok "Agent-Bootstrap-Token erstellt."
}

# ---------------------------------------------------------------------------
# Systemd / OpenRC unit writers
# ---------------------------------------------------------------------------

write_panel_messenger_service() {
  local php_bin="$1" console="$2" core_dir="$3" system_user="$4" web_group="$5"
  local messenger_dsn="${EASYWI_MESSENGER_TRANSPORT_DSN:-doctrine://default}"

  if [[ "${messenger_dsn}" == "sync://" ]]; then
    if has_systemctl && systemctl list-unit-files easywi-messenger.service >/dev/null 2>&1; then
      systemctl disable --now easywi-messenger.service 2>/dev/null || true
    fi
    ok "Messenger nutzt sync:// – kein Worker-Service erforderlich."
    return 0
  fi

  if has_systemctl; then
    cat > /etc/systemd/system/easywi-messenger.service <<UNIT
[Unit]
Description=EasyWI Symfony Messenger Worker
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
WorkingDirectory=${core_dir}
ExecStart=${php_bin} ${console} messenger:consume async --time-limit=3600
Restart=always
RestartSec=5
User=${system_user}
Group=${web_group}
Environment=LANG=C.UTF-8
Environment=LC_ALL=C.UTF-8
LimitNOFILE=1048576

[Install]
WantedBy=multi-user.target
UNIT
    service_enable_start easywi-messenger.service
  fi
}

write_panel_scheduler_service() {
  local php_bin="$1" console="$2" core_dir="$3" system_user="$4" web_group="$5"

  step "Richte zentralen Scheduler ein."
  if has_systemctl; then
    cat > /etc/systemd/system/easywi-scheduler.service <<UNIT
[Unit]
Description=EasyWI central scheduler
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
WorkingDirectory=${core_dir}
ExecStart=${php_bin} ${console} app:run-schedules --env=prod --no-interaction
User=${system_user}
Group=${web_group}
Environment=LANG=C.UTF-8
Environment=LC_ALL=C.UTF-8
LimitNOFILE=1048576
UNIT

    cat > /etc/systemd/system/easywi-scheduler.timer <<UNIT
[Unit]
Description=Run EasyWI central scheduler every minute

[Timer]
OnBootSec=1min
OnUnitActiveSec=1min
AccuracySec=10s
Unit=easywi-scheduler.service

[Install]
WantedBy=timers.target
UNIT

    systemctl daemon-reload 2>/dev/null || true
    systemctl disable --now easywi-scheduler.service 2>/dev/null || true
    systemctl enable easywi-scheduler.timer 2>/dev/null || warn "Konnte easywi-scheduler.timer nicht für Autostart aktivieren."
    systemctl start easywi-scheduler.timer 2>/dev/null || warn "Konnte easywi-scheduler.timer nicht starten."
    ok "Scheduler-Timer gestartet."
  elif has_openrc; then
    cat > /etc/cron.d/easywi-scheduler <<CRON
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
* * * * * ${system_user} ${php_bin} ${console} app:run-schedules --env=prod --no-interaction >/dev/null 2>&1
CRON
    chmod 0644 /etc/cron.d/easywi-scheduler
    start_first_available_service crond cron
    ok "Scheduler-Cron eingerichtet (OpenRC)."
  else
    warn "Kein systemd/OpenRC – Scheduler muss manuell eingerichtet werden."
  fi
}

write_panel_console_relay_service() {
  local php_bin="$1" core_dir="$2" system_user="$3" web_group="$4"

  step "Richte Console-Relay-Service ein."
  if has_systemctl; then
    cat > /etc/systemd/system/easywi-console-relay.service <<UNIT
[Unit]
Description=EasyWI console relay worker
After=network.target redis.service

[Service]
Type=simple
WorkingDirectory=${core_dir}
ExecStart=${php_bin} ${core_dir}/bin/console app:console:relay --env=prod
Restart=always
RestartSec=2
User=${system_user}
Group=${web_group}
Environment=LANG=C.UTF-8
Environment=LC_ALL=C.UTF-8
LimitNOFILE=1048576

[Install]
WantedBy=multi-user.target
UNIT
    service_enable_start easywi-console-relay.service
    ok "Console-Relay-Service gestartet."
  else
    warn "Kein systemd – Console-Relay muss manuell eingerichtet werden."
  fi
}

write_panel_console_wrapper() {
  local php_bin="$1" console="$2"
  step "Erstelle easywi-console Wrapper-Skript."
  cat > /usr/local/bin/easywi-console <<WRAPPER
#!/usr/bin/env bash
# EasyWI Console Wrapper – managed by easywi-installer
exec ${php_bin} ${console} "\$@"
WRAPPER
  chmod +x /usr/local/bin/easywi-console
  ok "Console-Wrapper verfügbar: /usr/local/bin/easywi-console <befehl>"
}

write_panel_cron() {
  local php_bin="$1" console="$2" system_user="$3"
  step "Richte EasyWI Cron-Jobs ein."
  cat > /etc/cron.d/easywi <<CRON
# EasyWI Cron – managed by easywi-installer
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin

# Symfony-Cache nach Systemstart aufwärmen
@reboot ${system_user} ${php_bin} ${console} cache:warmup --env=prod --quiet 2>/dev/null
CRON
  chmod 644 /etc/cron.d/easywi
  ok "Cron-Job eingerichtet: /etc/cron.d/easywi"
}

normalize_hostname() {
  local host="${1:-}"
  host="${host#http://}"
  host="${host#https://}"
  host="${host%%/*}"
  host="${host%%:*}"
  printf '%s' "${host}"
}

is_valid_certbot_domain() {
  local domain="$1"
  [[ "${domain}" =~ ^[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+$ ]]
}

resolve_ipv4s() {
  local host="$1"
  getent ahostsv4 "${host}" 2>/dev/null | awk '{print $1}' | sort -u
}

resolve_local_ipv4s() {
  {
    hostname -I 2>/dev/null | tr ' ' '\n'
    ip -4 addr show scope global 2>/dev/null | awk '/inet /{sub(/\/.*/,"",$2); print $2}'
    detect_primary_ipv4 2>/dev/null || true
  } | awk 'NF && $0 !~ /^127\./' | sort -u
}

check_domain_points_to_server() {
  local domain="$1"
  local domain_ips local_ips
  domain_ips="$(resolve_ipv4s "${domain}" | tr '\n' ' ' | sed 's/[[:space:]]*$//')"
  local_ips="$(resolve_local_ipv4s | tr '\n' ' ' | sed 's/[[:space:]]*$//')"

  if [[ -z "${domain_ips}" ]]; then
    warn "DNS-Prüfung: Für ${domain} wurden keine A-Records gefunden. Let's Encrypt wird vermutlich fehlschlagen."
    return 1
  fi
  if [[ -z "${local_ips}" ]]; then
    warn "DNS-Prüfung: Lokale Server-IP konnte nicht ermittelt werden."
    return 1
  fi

  local dip lip
  for dip in ${domain_ips}; do
    for lip in ${local_ips}; do
      if [[ "${dip}" == "${lip}" ]]; then
        ok "DNS-Prüfung bestanden: ${domain} zeigt auf ${dip}."
        return 0
      fi
    done
  done

  warn "DNS-Prüfung: ${domain} zeigt auf [${domain_ips}], lokale Server-IPs sind [${local_ips}]. Bitte DNS auf diesen Server setzen."
  return 1
}

certbot_has_nginx_plugin() {
  local certbot_bin="${1:-certbot}"
  "${certbot_bin}" plugins 2>/dev/null | awk 'BEGIN{ok=0} /nginx|Nginx/{ok=1} END{exit ok?0:1}'
}

select_certbot_binary() {
  if command -v certbot >/dev/null 2>&1 && certbot_has_nginx_plugin "$(command -v certbot)"; then
    command -v certbot
    return 0
  fi
  if [[ -x /usr/bin/certbot ]] && certbot_has_nginx_plugin /usr/bin/certbot; then
    printf '%s\n' /usr/bin/certbot
    return 0
  fi
  command -v certbot 2>/dev/null || true
}

setup_certbot() {
  local manager="$1" domain="$2" email="${3:-}"
  domain="$(normalize_hostname "${domain}")"
  [[ -z "${domain}" || "${domain}" == "_" ]] && return 1

  if ! is_valid_certbot_domain "${domain}"; then
    warn "Ungültige Domain für Let's Encrypt: ${domain}"
    return 1
  fi

  step "Installiere Certbot mit Nginx-Plugin und stelle SSL-Zertifikat aus (${domain})."
  local cb_pkgs=()
  read -r -a cb_pkgs <<< "$(certbot_packages "${manager}")"
  install_packages "${manager}" "${cb_pkgs[@]}"

  check_domain_points_to_server "${domain}" || true

  local certbot_bin
  certbot_bin="$(select_certbot_binary)"
  if [[ -z "${certbot_bin}" ]]; then
    warn "Certbot wurde nicht gefunden, obwohl die Pakete installiert wurden."
    return 1
  fi
  if ! certbot_has_nginx_plugin "${certbot_bin}"; then
    warn "Das Certbot-Nginx-Plugin ist nicht verfügbar. Prüfe Paketinstallation (${cb_pkgs[*]}) oder ob ein Snap-/pip-Certbot vor /usr/bin/certbot im PATH liegt."
    return 1
  fi

  local certbot_args=(--nginx --non-interactive --agree-tos --keep-until-expiring --redirect -d "${domain}")
  [[ -n "${email}" ]] \
    && certbot_args+=(--email "${email}") \
    || certbot_args+=(--register-unsafely-without-email)

  if "${certbot_bin}" "${certbot_args[@]}"; then
    ok "SSL-Zertifikat ausgestellt, HTTP→HTTPS-Weiterleitung aktiviert und Nginx aktualisiert (${domain})."
    return 0
  fi
  warn "Certbot fehlgeschlagen – bitte DNS, Port 80/443 und die Nginx-Konfiguration prüfen."
  return 1
}

write_agent_service() {
  local instance_base_dir="$1" sftp_base_dir="$2"
  local poll_interval_seconds="${3:-30}" heartbeat_interval_seconds="${4:-60}" request_timeout_seconds="${5:-15}"

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
Environment=EASYWI_AGENT_JOB_POLL_INTERVAL_SECONDS=${poll_interval_seconds}
Environment=EASYWI_AGENT_HEARTBEAT_INTERVAL_SECONDS=${heartbeat_interval_seconds}
Environment=EASYWI_AGENT_REQUEST_TIMEOUT_SECONDS=${request_timeout_seconds}
Environment=LANG=C.UTF-8
Environment=LC_ALL=C.UTF-8
ExecStart=/usr/local/bin/easywi-agent --config /etc/easywi/agent.conf
Restart=always
RestartSec=5
LimitNOFILE=1048576
RuntimeDirectory=easywi-agent
RuntimeDirectoryMode=0755

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
export EASYWI_AGENT_JOB_POLL_INTERVAL_SECONDS="${poll_interval_seconds}"
export EASYWI_AGENT_HEARTBEAT_INTERVAL_SECONDS="${heartbeat_interval_seconds}"
export EASYWI_AGENT_REQUEST_TIMEOUT_SECONDS="${request_timeout_seconds}"
INIT
    chmod +x /etc/init.d/easywi-agent
    rc-update add easywi-agent default 2>/dev/null || true
  fi
}

# ---------------------------------------------------------------------------
# Release / binary download
# ---------------------------------------------------------------------------

release_download_bases() {
  local version="${1:-latest}"
  if [[ -n "${version}" && "${version}" != "latest" ]]; then
    printf '%s/download/%s\n' "${EASYWI_GITHUB_RELEASE_BASE}" "${version}"
    if [[ "${version}" != v* && "${version}" != V* ]]; then
      printf '%s/download/v%s\n' "${EASYWI_GITHUB_RELEASE_BASE}" "${version}"
    fi
  else
    printf '%s/latest/download\n' "${EASYWI_GITHUB_RELEASE_BASE}"
  fi
}

curl_release_asset() {
  local url="$1" dest="$2" token="${3:-}"
  local -a args=(-fsSL --retry 3 --retry-delay 2 -H "User-Agent: easywi-installer/${VERSION}")
  [[ -n "${token}" ]] && args+=(-H "Authorization: Bearer ${token}")
  curl "${args[@]}" "${url}" -o "${dest}"
}

curl_github_api() {
  local url="$1" dest="$2" token="${3:-}"
  local -a args=(
    -fsSL --retry 3 --retry-delay 2
    -H "Accept: application/vnd.github+json"
    -H "User-Agent: easywi-installer/${VERSION}"
  )
  [[ -n "${token}" ]] && args+=(-H "Authorization: Bearer ${token}")
  curl "${args[@]}" "${url}" -o "${dest}"
}

resolve_latest_release_with_assets() {
  local token="${1:-}"; shift || true
  local -a asset_names=("$@")
  [[ "${#asset_names[@]}" -gt 0 ]] || return 1
  command -v jq >/dev/null 2>&1 || return 1

  local tmp releases_url names_json tag
  tmp="$(mktemp)"
  releases_url="https://api.github.com/repos/${EASYWI_GITHUB_REPO}/releases?per_page=50"
  if ! curl_github_api "${releases_url}" "${tmp}" "${token}" 2>/dev/null; then
    rm -f "${tmp}"
    return 1
  fi

  names_json="$(printf '%s\n' "${asset_names[@]}" | jq -R . | jq -s .)"
  tag="$(jq -r --argjson names "${names_json}" '
    [ .[]
      | select((.draft // false) | not)
      | select((.prerelease // false) | not)
      | select(any(.assets[]?.name; . as $asset_name | ($names | index($asset_name))))
    ]
    | .[0].tag_name // empty
  ' "${tmp}")"
  rm -f "${tmp}"

  [[ -n "${tag}" ]] || return 1
  printf '%s\n' "${tag}"
}

list_agent_release_options() {
  local token="${1:-}"; shift || true
  local -a asset_names=("$@")
  [[ "${#asset_names[@]}" -gt 0 ]] || return 1
  command -v jq >/dev/null 2>&1 || return 1

  local tmp releases_url names_json
  tmp="$(mktemp)"
  releases_url="https://api.github.com/repos/${EASYWI_GITHUB_REPO}/releases?per_page=50"
  if ! curl_github_api "${releases_url}" "${tmp}" "${token}" 2>/dev/null; then
    rm -f "${tmp}"
    return 1
  fi

  names_json="$(printf '%s\n' "${asset_names[@]}" | jq -R . | jq -s .)"
  jq -r --argjson names "${names_json}" '
    def release_channel:
      (((.tag_name // "") + " " + (.name // "")) | ascii_downcase) as $text
      | if ($text | test("beta")) then "beta"
        elif ($text | test("dev|alpha|nightly|snapshot")) then "dev"
        elif (.prerelease // false) then "dev"
        else "stable"
        end;

    [ .[]
      | select((.draft // false) | not)
      | . as $release
      | [ .assets[]?.name as $asset_name | select($names | index($asset_name)) | $asset_name ] as $matches
      | select($matches | length > 0)
      | {
          tag: ($release.tag_name // ""),
          channel: ($release | release_channel),
          published: ($release.published_at // ""),
          assets: ($matches | join(", "))
        }
    ]
    | reduce .[] as $release ({};
        if .[$release.channel] then . else . + {($release.channel): $release} end
      )
    | [ .dev?, .beta?, .stable? ][]?
    | [ .tag, .channel, .published, .assets ]
    | @tsv
  ' "${tmp}"
  local status=$?
  rm -f "${tmp}"
  return "${status}"
}

agent_release_output() {
  if [[ -e /dev/tty ]]; then cat >/dev/tty; else cat >&2; fi
}

select_agent_release_option() {
  local arch="$1" token="${2:-}"; shift 2 || true
  local -a asset_names=("$@") options=()
  mapfile -t options < <(list_agent_release_options "${token}" "${asset_names[@]}" 2>/dev/null || true)
  [[ "${#options[@]}" -gt 0 ]] || return 1

  warn "Kein Agent-Binary für ${arch} in der angeforderten Version gefunden."
  agent_release_output <<INFO

  Bitte wählen Sie den gewünschten Agent-Kanal für ${arch}:

INFO

  local i tag channel published _assets label
  for i in "${!options[@]}"; do
    IFS=$'\t' read -r tag channel published _assets <<< "${options[$i]}"
    case "${channel}" in
      dev)    label="Aktuelle Dev-Version" ;;
      beta)   label="Aktuelle Beta-Version" ;;
      stable) label="Aktuelle Stable-Version" ;;
      *)      label="Aktuelle Version" ;;
    esac
    printf '    %2d) %-24s %s\n' "$((i + 1))" "${label}" "${tag}" | agent_release_output
    [[ -n "${published}" ]] && printf '        veröffentlicht: %s\n' "${published}" | agent_release_output
  done
  printf '     0) Abbrechen\n\n' | agent_release_output

  is_tty || {
    warn "Nicht-interaktive Ausführung: Bitte EASYWI_AGENT_VERSION auf eine der oben genannten Versionen setzen."
    return 1
  }

  local choice=""
  while true; do
    choice="$(menu_prompt "  Auswahl [0-${#options[@]}]: ")"
    [[ -z "${choice}" ]] && choice="0"
    if [[ "${choice}" =~ ^[0-9]+$ ]] && (( choice >= 0 && choice <= ${#options[@]} )); then
      break
    fi
    printf '  Ungültige Auswahl.\n' | agent_release_output
  done

  [[ "${choice}" -gt 0 ]] || return 1
  IFS=$'\t' read -r tag channel published _assets <<< "${options[$((choice - 1))]}"
  printf '%s\n' "${tag}"
}

find_extracted_agent_binary() {
  local extract_dir="$1" arch="$2" found=""
  for candidate in \
    "${extract_dir}/easywi-agent-${arch}" \
    "${extract_dir}/easywi-agent" \
    "${extract_dir}/bin/easywi-agent-${arch}" \
    "${extract_dir}/bin/easywi-agent"; do
    if [[ -f "${candidate}" ]]; then
      printf '%s\n' "${candidate}"
      return 0
    fi
  done

  found="$(find "${extract_dir}" -maxdepth 3 -type f \
    \( -name "easywi-agent-${arch}" -o -name "easywi-agent" \) \
    -print -quit 2>/dev/null || true)"
  [[ -n "${found}" ]] || return 1
  printf '%s\n' "${found}"
}

prepare_agent_binary_asset() {
  local asset_path="$1" asset_name="$2" work_dir="$3" arch="$4"
  local extract_dir resolved
  extract_dir="${work_dir}/agent-extract-${asset_name//[^A-Za-z0-9_.-]/_}"
  mkdir -p "${extract_dir}"

  case "${asset_name}" in
    *.tar.gz) tar -xzf "${asset_path}" -C "${extract_dir}" ;;
    *.zip)    command -v unzip >/dev/null || fatal "unzip fehlt"; unzip -oq "${asset_path}" -d "${extract_dir}" ;;
    *)        cp "${asset_path}" "${extract_dir}/easywi-agent-${arch}" ;;
  esac

  if ! resolved="$(find_extracted_agent_binary "${extract_dir}" "${arch}")"; then
    fatal "Agent-Archiv ${asset_name} enthält keine ausführbare Agent-Datei für ${arch}."
  fi
  printf '%s\n' "${resolved}"
}

download_release_asset() {
  local asset="$1" dest="$2" version="${3:-latest}" token="${4:-}"
  local base
  while IFS= read -r base; do
    log "Download: ${base}/${asset}"
    if curl_release_asset "${base}/${asset}" "${dest}" "${token}"; then
      return 0
    fi
  done < <(release_download_bases "${version}")
  fatal "Asset nicht verfügbar: ${asset}"
}

download_optional_asset() {
  local asset="$1" dest="$2" version="${3:-latest}" token="${4:-}"
  local base
  while IFS= read -r base; do
    curl_release_asset "${base}/${asset}" "${dest}" "${token}" 2>/dev/null && return 0
  done < <(release_download_bases "${version}")
  rm -f "${dest}"; return 1
}

version_without_v() {
  local version="${1:-}"
  version="${version#v}"; version="${version#V}"
  printf '%s' "${version}"
}

verify_release_checksum() {
  local checksums_file="$1" asset_path="$2" asset_name="$3"
  [[ -f "${checksums_file}" ]] || fatal "Checksums-Datei fehlt: ${checksums_file}"
  local expected actual
  expected="$(awk -v n="${asset_name}" '$2==n || $2=="*" n {print $1}' "${checksums_file}" | head -n1)"
  [[ -n "${expected}" ]] || fatal "Kein Checksum-Eintrag für ${asset_name} gefunden."
  actual="$(sha256sum "${asset_path}" | awk '{print $1}')"
  [[ "${actual}" == "${expected}" ]] || fatal "Checksum für ${asset_name} ungültig. Erwartet ${expected}, erhalten ${actual}."
  ok "Checksum verifiziert: ${asset_name}"
}

verify_release_checksum_candidates() {
  local asset_path="$1" asset_name="$2" version="$3" token="${4:-}"
  shift 4 || true
  local -a checksum_candidates=("$@")
  local checksums_file checksum_name
  checksums_file="$(mktemp)"

  for checksum_name in "${checksum_candidates[@]}"; do
    rm -f "${checksums_file}"
    if ! download_optional_asset "${checksum_name}" "${checksums_file}" "${version}" "${token}"; then
      continue
    fi
    if ! checksum_file_contains_asset "${checksums_file}" "${asset_name}"; then
      continue
    fi

    log "Checksums geladen: ${checksum_name}"
    if verify_release_checksum "${checksums_file}" "${asset_path}" "${asset_name}"; then
      rm -f "${checksums_file}"
      return 0
    fi

    warn "Checksum-Datei ${checksum_name} passt nicht zu ${asset_name}; versuche nächste verfügbare Checksums-Datei."
  done

  rm -f "${checksums_file}"
  return 1
}


verify_panel_release_checksum() {
  local checksums_file="$1" asset_path="$2" asset_name="$3"
  local expected actual

  [[ -f "${checksums_file}" ]] || return 1
  expected="$(awk -v n="${asset_name}" '$2==n || $2=="*" n {print $1}' "${checksums_file}" | head -n1)"
  [[ -n "${expected}" ]] || return 1
  actual="$(sha256sum "${asset_path}" | awk '{print $1}')"

  if [[ "${actual}" == "${expected}" ]]; then
    ok "Checksum verifiziert: ${asset_name}"
    return 0
  fi

  warn "Checksum für ${asset_name} stimmt nicht mit ${checksums_file} überein. Erwartet ${expected}, erhalten ${actual}. Archiv wurde bereits lokal validiert; Installation wird fortgesetzt."
  return 0
}

checksum_file_contains_asset() {
  local checksums_file="$1" asset_name="$2"
  [[ -f "${checksums_file}" ]] || return 1
  awk -v n="${asset_name}" '$2==n || $2=="*" n {found=1} END{exit found?0:1}' "${checksums_file}"
}



resolve_panel_release_tag() {
  local version="${1:-latest}" token="${2:-}"
  command -v jq >/dev/null 2>&1 || return 1

  local tmp api_url tag_candidate resolved_tag
  tmp="$(mktemp)"

  if [[ -n "${version}" && "${version}" != "latest" ]]; then
    for tag_candidate in "${version}" "v${version}"; do
      [[ "${tag_candidate}" == "vv"* ]] && continue
      api_url="https://api.github.com/repos/${EASYWI_GITHUB_REPO}/releases/tags/${tag_candidate}"
      if curl_github_api "${api_url}" "${tmp}" "${token}" 2>/dev/null; then
        resolved_tag="$(jq -r '.tag_name // empty' "${tmp}")"
        rm -f "${tmp}"
        [[ -n "${resolved_tag}" ]] || return 1
        printf '%s\n' "${resolved_tag}"
        return 0
      fi
    done
  else
    api_url="https://api.github.com/repos/${EASYWI_GITHUB_REPO}/releases/latest"
    if curl_github_api "${api_url}" "${tmp}" "${token}" 2>/dev/null; then
      resolved_tag="$(jq -r '.tag_name // empty' "${tmp}")"
      rm -f "${tmp}"
      [[ -n "${resolved_tag}" ]] || return 1
      printf '%s\n' "${resolved_tag}"
      return 0
    fi

    api_url="https://api.github.com/repos/${EASYWI_GITHUB_REPO}/releases?per_page=1"
    if curl_github_api "${api_url}" "${tmp}" "${token}" 2>/dev/null; then
      resolved_tag="$(jq -r '.[0].tag_name // empty' "${tmp}")"
      rm -f "${tmp}"
      [[ -n "${resolved_tag}" ]] || return 1
      printf '%s\n' "${resolved_tag}"
      return 0
    fi
  fi

  rm -f "${tmp}"
  return 1
}

list_panel_release_assets() {
  local version="${1:-latest}" token="${2:-}"
  command -v jq >/dev/null 2>&1 || return 1

  local tmp api_url tag_candidate
  tmp="$(mktemp)"

  if [[ -n "${version}" && "${version}" != "latest" ]]; then
    for tag_candidate in "${version}" "v${version}"; do
      [[ "${tag_candidate}" == "vv"* ]] && continue
      api_url="https://api.github.com/repos/${EASYWI_GITHUB_REPO}/releases/tags/${tag_candidate}"
      if curl_github_api "${api_url}" "${tmp}" "${token}" 2>/dev/null; then
        jq -r '.assets[]?.name // empty' "${tmp}"
        rm -f "${tmp}"
        return 0
      fi
    done
  else
    api_url="https://api.github.com/repos/${EASYWI_GITHUB_REPO}/releases/latest"
    if curl_github_api "${api_url}" "${tmp}" "${token}" 2>/dev/null; then
      jq -r '.assets[]?.name // empty' "${tmp}"
      rm -f "${tmp}"
      return 0
    fi

    api_url="https://api.github.com/repos/${EASYWI_GITHUB_REPO}/releases?per_page=1"
    if curl_github_api "${api_url}" "${tmp}" "${token}" 2>/dev/null; then
      jq -r '.[0].assets[]?.name // empty' "${tmp}"
      rm -f "${tmp}"
      return 0
    fi
  fi

  rm -f "${tmp}"
  return 1
}

asset_already_selected() {
  local needle="$1"; shift || true
  local existing
  for existing in "$@"; do
    [[ "${existing}" == "${needle}" ]] && return 0
  done
  return 1
}

select_panel_asset_candidates() {
  local version="${1:-latest}" token="${2:-}" version_plain="$3"
  local -a release_assets=() selected=()
  local asset candidate

  mapfile -t release_assets < <(list_panel_release_assets "${version}" "${token}" 2>/dev/null || true)

  for asset in "${release_assets[@]}"; do
    case "${asset}" in
      easywi-webinterface-update-*.zip) continue ;;
      easywi-webinterface-*.zip) selected+=("${asset}") ;;
    esac
  done

  for asset in "${release_assets[@]}"; do
    case "${asset}" in
      easywi-core-*.tar.gz) selected+=("${asset}") ;;
    esac
  done

  for asset in "${release_assets[@]}"; do
    [[ "${asset}" == "easywi-core.tar.gz" ]] && selected+=("${asset}")
  done

  if [[ -n "${version_plain}" && "${version_plain}" != "latest" ]]; then
    for candidate in \
      "easywi-webinterface-${version_plain}.zip" \
      "easywi-core-${version_plain}.tar.gz" \
      "easywi-core.tar.gz"; do
      if ! asset_already_selected "${candidate}" "${selected[@]}"; then
        selected+=("${candidate}")
      fi
    done
  else
    if ! asset_already_selected "easywi-core.tar.gz" "${selected[@]}"; then
      selected+=("easywi-core.tar.gz")
    fi
  fi

  printf '%s\n' "${selected[@]}"
}

format_asset_list_for_error() {
  local version="${1:-latest}" token="${2:-}"
  local -a release_assets=()
  mapfile -t release_assets < <(list_panel_release_assets "${version}" "${token}" 2>/dev/null || true)
  if [[ "${#release_assets[@]}" -eq 0 ]]; then
    printf 'keine Assets per GitHub-API ermittelbar'
    return 0
  fi
  printf '%s' "${release_assets[*]}"
}

validate_panel_archive() {
  local archive="$1" asset_name="$2"
  [[ -f "${archive}" ]] || fatal "Release-Asset ${asset_name} wurde nicht heruntergeladen: ${archive}"
  [[ -s "${archive}" ]] || fatal "Release-Asset ${asset_name} ist leer: ${archive}"

  case "${asset_name}" in
    *.zip)
      command -v unzip >/dev/null || fatal "unzip fehlt"
      run_or_fatal "ZIP-Validierung fehlgeschlagen: ${asset_name}" unzip -t "${archive}"
      ;;
    *.tar.gz|*.tgz)
      run_or_fatal "Tar-Gzip-Validierung fehlgeschlagen: ${asset_name}" tar -tzf "${archive}"
      ;;
    *)
      fatal "Unbekanntes Panel-Archivformat: ${asset_name}"
      ;;
  esac
}

download_checksums_for_asset() {
  local dest="$1" version="$2" token="$3"; shift 3
  local candidate
  for candidate in "$@"; do
    if download_optional_asset "${candidate}" "${dest}" "${version}" "${token}"; then
      printf '%s' "${candidate}"
      return 0
    fi
  done
  return 1
}

extract_panel_archive() {
  local archive="$1" extract_dir="$2" asset_name="$3"
  case "${asset_name}" in
    *.tar.gz|*.tgz) tar -xzf "${archive}" -C "${extract_dir}" ;;
    *.zip)          command -v unzip >/dev/null || fatal "unzip fehlt"; unzip -oq "${archive}" -d "${extract_dir}" ;;
    *)              fatal "Unbekanntes Panel-Archivformat: ${asset_name}" ;;
  esac
}

find_extracted_core_dir() {
  local extract_dir="$1"
  if [[ -f "${extract_dir}/core/bin/console" ]]; then
    printf '%s' "${extract_dir}/core"
    return 0
  fi
  if [[ -f "${extract_dir}/bin/console" ]]; then
    printf '%s' "${extract_dir}"
    return 0
  fi
  local found
  found="$(find "${extract_dir}" -maxdepth 3 -type f -path '*/bin/console' -print -quit 2>/dev/null || true)"
  [[ -n "${found}" ]] || return 1
  printf '%s' "${found%/bin/console}"
}

install_panel_release() {
  local install_dir="$1" version="${2:-latest}" github_token="${3:-}"
  local tmp archive checksums asset_name checksum_name version_plain source_dir core_dir found_assets download_version resolved_panel_version
  tmp="$(mktemp -d)"
  checksums="${tmp}/checksums.txt"
  version_plain="$(version_without_v "${version}")"
  core_dir="${install_dir}/core"

  step "Lade Panel/Webinterface-Release (${version})."

  download_version="${version}"
  if resolved_panel_version="$(resolve_panel_release_tag "${version}" "${github_token}" 2>/dev/null)"; then
    download_version="${resolved_panel_version}"
    [[ "${download_version}" == "${version}" ]] || log "Panel/Webinterface-Release aufgelöst: ${version} -> ${download_version}"
  fi

  local -a candidates=()
  mapfile -t candidates < <(select_panel_asset_candidates "${version}" "${github_token}" "${version_plain}")
  found_assets="$(format_asset_list_for_error "${version}" "${github_token}")"

  for asset_name in "${candidates[@]}"; do
    [[ -z "${asset_name}" || "${asset_name}" == *latest* ]] && continue
    case "${asset_name}" in
      easywi-webinterface-update-*.zip)
        warn "Überspringe Webinterface-Update-Archiv (nicht für Neuinstallation): ${asset_name}"
        continue
        ;;
      easywi-webinterface-*.zip)
        archive="${tmp}/easywi-webinterface.zip"
        ;;
      easywi-core-*.tar.gz|easywi-core.tar.gz)
        archive="${tmp}/easywi-core.tar.gz"
        ;;
      *)
        continue
        ;;
    esac

    rm -f "${archive}" "${checksums}"
    if ! download_optional_asset "${asset_name}" "${archive}" "${download_version}" "${github_token}"; then
      continue
    fi

    validate_panel_archive "${archive}" "${asset_name}"

    checksum_name=""
    for checksum_candidate in checksums-webinterface.txt checksums-core.txt checksums.txt checksums.sha256; do
      if download_optional_asset "${checksum_candidate}" "${checksums}" "${download_version}" "${github_token}" \
        && checksum_file_contains_asset "${checksums}" "${asset_name}"; then
        checksum_name="${checksum_candidate}"
        break
      fi
    done

    if [[ -n "${checksum_name}" ]]; then
      log "Checksums geladen: ${checksum_name}"
      verify_panel_release_checksum "${checksums}" "${archive}" "${asset_name}"
    else
      warn "Kein passender Checksum-Eintrag für ${asset_name} gefunden – Archiv wurde lokal validiert."
    fi

    break
  done

  if [[ -z "${asset_name:-}" || ! -f "${archive:-}" ]]; then
    fatal "Kein passendes Panel/Webinterface-Release-Asset gefunden. Erwartet easywi-webinterface-*.zip (ohne easywi-webinterface-update-*.zip), optional easywi-core-*.tar.gz/easywi-core.tar.gz. Gefundene Release-Assets: ${found_assets}. Versuchte Kandidaten: ${candidates[*]:-(keine)}."
  fi

  extract_panel_archive "${archive}" "${tmp}" "${asset_name}"
  source_dir="$(find_extracted_core_dir "${tmp}")" || fatal "Archiv ${asset_name} enthält kein Symfony-core/bin/console. Gefundene Release-Assets: ${found_assets}."

  mkdir -p "${install_dir}"
  if [[ -d "${core_dir}" && -n "$(find "${core_dir}" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ]]; then
    if prompt_yes_no "${core_dir} ist nicht leer. Inhalt durch Release ersetzen?" "no"; then
      (shopt -s dotglob nullglob; rm -rf "${core_dir:?}/"*)
    else
      fatal "Core-Verzeichnis nicht leer. Installation abgebrochen."
    fi
  fi
  mkdir -p "${core_dir}"
  (shopt -s dotglob nullglob; cp -a "${source_dir}/"* "${core_dir}/")
  printf '%s\n' "${asset_name}" > "${install_dir}/.easywi-release-asset"
  printf '%s\n' "${download_version}" > "${install_dir}/.easywi-release-version"
  rm -rf "${tmp}"
  ok "Panel/Webinterface-Release installiert: ${asset_name} -> ${core_dir}"
}

install_agent_binaries() {
  local arch="$1" version="${2:-latest}" github_token="${EASYWI_APP_GITHUB_TOKEN:-}"
  local tmp; tmp="$(mktemp -d)"

  step "Lade Agent-Binaries (${arch}, ${version})."

  local agent_dest="${tmp}/agent-raw"
  local agent_resolved="" asset_name="" resolved_version="${version}"
  local -a asset_candidates=()
  for suffix in ".tar.gz" ".zip" ""; do
    asset_candidates+=("easywi-agent-${arch}${suffix}")
  done

  for asset_name in "${asset_candidates[@]}"; do
    if download_optional_asset "${asset_name}" "${agent_dest}" "${resolved_version}" "${github_token}"; then
      if ! verify_release_checksum_candidates "${agent_dest}" "${asset_name}" "${resolved_version}" "${github_token}" \
        checksums-agent-linux.txt checksums-agent.txt checksums.txt checksums.sha256; then
        fatal "Keine Agent-Checksums-Datei im Release gefunden."
      fi
      agent_resolved="$(prepare_agent_binary_asset "${agent_dest}" "${asset_name}" "${tmp}" "${arch}")"
      break
    fi
  done

  if [[ -z "${agent_resolved}" && "${version}" == "latest" ]]; then
    if resolved_version="$(resolve_latest_release_with_assets "${github_token}" "${asset_candidates[@]}")"; then
      warn "Das GitHub-'latest'-Release enthält kein Agent-Binary für ${arch}; verwende Agent-Release ${resolved_version}."
      for asset_name in "${asset_candidates[@]}"; do
        if download_optional_asset "${asset_name}" "${agent_dest}" "${resolved_version}" "${github_token}"; then
          if ! verify_release_checksum_candidates "${agent_dest}" "${asset_name}" "${resolved_version}" "${github_token}" \
            checksums-agent-linux.txt checksums-agent.txt checksums.txt checksums.sha256; then
            fatal "Keine Agent-Checksums-Datei im Release ${resolved_version} gefunden."
          fi
          agent_resolved="$(prepare_agent_binary_asset "${agent_dest}" "${asset_name}" "${tmp}" "${arch}")"
          break
        fi
      done
    fi
  fi

  if [[ -z "${agent_resolved}" ]]; then
    if resolved_version="$(select_agent_release_option "${arch}" "${github_token}" "${asset_candidates[@]}")"; then
      warn "Verwende ausgewählte Agent-Version ${resolved_version}."
      for asset_name in "${asset_candidates[@]}"; do
        if download_optional_asset "${asset_name}" "${agent_dest}" "${resolved_version}" "${github_token}"; then
          if ! verify_release_checksum_candidates "${agent_dest}" "${asset_name}" "${resolved_version}" "${github_token}" \
            checksums-agent-linux.txt checksums-agent.txt checksums.txt checksums.sha256; then
            fatal "Keine Agent-Checksums-Datei im Release ${resolved_version} gefunden."
          fi
          agent_resolved="$(prepare_agent_binary_asset "${agent_dest}" "${asset_name}" "${tmp}" "${arch}")"
          break
        fi
      done
    fi
  fi

  [[ -n "${agent_resolved}" && -f "${agent_resolved}" ]] \
    || fatal "Kein Agent-Binary für ${arch} gefunden (angefordert: ${version}, zuletzt versucht: ${resolved_version})."

  install -m 0755 "${agent_resolved}" /usr/local/bin/easywi-agent
  rm -rf "${tmp}"
  ok "easywi-agent installiert: /usr/local/bin/easywi-agent"

  command -v easywi-agent >/dev/null || fatal "easywi-agent nicht im PATH nach Installation."

  if [[ "${arch}" == "amd64" ]]; then
    local github_token_inner="${EASYWI_APP_GITHUB_TOKEN:-}"
    install_optional_agent_binary "easywi-musicbot-linux-amd64"        /usr/local/bin/easywi-musicbot        "${resolved_version}" "${github_token_inner}"
    install_optional_agent_binary "easywi-teamspeak-bridge-linux-amd64" /usr/local/bin/easywi-teamspeak-bridge "${resolved_version}" "${github_token_inner}"
  fi
}

# install_optional_agent_binary downloads a single named agent binary from the
# release (tries <name>.zip first, then bare binary), verifies its SHA256
# checksum when available, and installs it atomically with mode 0755.
# Non-fatal: a missing or unverifiable asset only prints a warning.
install_optional_agent_binary() {
  local asset_name="$1" target_path="$2" version="${3:-latest}" github_token="${4:-}"
  local tmp; tmp="$(mktemp -d)"
  local tmp_zip="${tmp}/${asset_name}.zip"
  local tmp_bin="${tmp}/${asset_name}"
  local resolved_bin=""

  if download_optional_asset "${asset_name}.zip" "${tmp_zip}" "${version}" "${github_token}"; then
    command -v unzip >/dev/null 2>&1 || { warn "unzip fehlt – überspringe ${asset_name}.zip"; rm -rf "${tmp}"; return 0; }
    if ! unzip -oq "${tmp_zip}" -d "${tmp}" 2>/dev/null; then
      warn "Entpacken von ${asset_name}.zip fehlgeschlagen – überspringe."
      rm -rf "${tmp}"; return 0
    fi
    if [[ ! -f "${tmp}/${asset_name}" ]]; then
      warn "${asset_name} nicht in .zip gefunden – überspringe."
      rm -rf "${tmp}"; return 0
    fi
    resolved_bin="${tmp}/${asset_name}"
    verify_release_checksum_candidates "${tmp_zip}" "${asset_name}.zip" "${version}" "${github_token}" \
      checksums-agent.txt checksums-agent-linux.txt checksums.txt checksums.sha256 2>/dev/null \
      || verify_release_checksum_candidates "${resolved_bin}" "${asset_name}" "${version}" "${github_token}" \
        checksums-agent.txt checksums-agent-linux.txt checksums.txt checksums.sha256 2>/dev/null \
      || warn "Keine Prüfsumme für ${asset_name} gefunden – Checksum-Prüfung übersprungen."
  elif download_optional_asset "${asset_name}" "${tmp_bin}" "${version}" "${github_token}"; then
    resolved_bin="${tmp_bin}"
    verify_release_checksum_candidates "${resolved_bin}" "${asset_name}" "${version}" "${github_token}" \
      checksums-agent.txt checksums-agent-linux.txt checksums.txt checksums.sha256 2>/dev/null \
      || warn "Keine Prüfsumme für ${asset_name} gefunden – Checksum-Prüfung übersprungen."
  else
    warn "${asset_name} nicht im Release gefunden – überspringe."
    rm -rf "${tmp}"; return 0
  fi

  if [[ -n "${resolved_bin}" && -f "${resolved_bin}" ]]; then
    install -m 0755 "${resolved_bin}" "${target_path}"
    ok "${asset_name} installiert: ${target_path}"
  fi

  rm -rf "${tmp}"
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
  [[ -z "${primary}" ]] && primary="/var/www" && dirs=("/home" "/var/www")
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
    | awk '{print $4}' | cut -d/ -f1 | grep -v '^127\.' | paste -sd, - && return
  printf '\n'
}

# Returns the single primary IPv4 address of this server.
# Tries: default-route outbound IP → first global scope IP → hostname -I fallback.
detect_primary_ipv4() {
  local ip
  # Prefer the IP used for the default route (most likely the public interface).
  if command -v ip >/dev/null 2>&1; then
    ip="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '/src/{for(i=1;i<=NF;i++) if($i=="src") print $(i+1)}' | head -n1)"
    [[ -n "$ip" && "$ip" != "127."* ]] && { printf '%s' "$ip"; return; }

    # Fall back to first non-loopback global-scope address.
    ip="$(ip -o -4 addr show scope global 2>/dev/null | awk '{print $4}' | cut -d/ -f1 | grep -v '^127\.' | head -n1)"
    [[ -n "$ip" ]] && { printf '%s' "$ip"; return; }
  fi

  # Last resort: hostname -I (space-separated; take first non-loopback).
  ip="$(hostname -I 2>/dev/null | tr ' ' '\n' | grep -v '^127\.' | head -n1)"
  printf '%s' "${ip:-}"
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
  local poll_interval_seconds="${7:-30}" heartbeat_interval_seconds="${8:-60}" request_timeout_seconds="${9:-15}"

  mapfile -t bdc < <(build_agent_base_dir_config "${file_base_dir}")
  local primary="${bdc[0]}" all_dirs="${bdc[1]}"

  mkdir -p /etc/easywi
  cat > /etc/easywi/agent.conf <<CONF
agent_id=${agent_id}
secret=${secret}
api_url=${api_url}
service_listen=0.0.0.0:7456
poll_interval=${poll_interval_seconds}s
heartbeat_interval=${heartbeat_interval_seconds}s
request_timeout=${request_timeout_seconds}s
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
# poll_interval=30s
# heartbeat_interval=60s
# request_timeout=15s
# file_base_dir=/var/www
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
  local install_dir="$1" _repo_url="$2" release_version="$3"
  local db_driver="$4" db_system="$5" db_root_pw="$6"
  local db_host="$7" db_port="$8" db_name="$9" db_user="${10}" db_password="${11}"
  local php_version="${12}" web_hostname="${13}"
  local system_user="${14}" app_secret="${15}" enc_keys="${16}"
  local reg_token="${17}" github_token="${18}"
  local run_migrations="${19}" web_scheme="${20}" provision_db="${21}"
  local setup_ssl="${22:-false}" ssl_email="${23:-}"

  local family manager
  family="$(detect_os_family)"
  manager="$(detect_package_manager)"

  log "Erkanntes OS-Family: ${family} | Paketmanager: ${manager}"

  php_version="$(normalize_php_version_for_os "${php_version}" "${manager}" "${family}")"
  ensure_panel_php_version_supported "${php_version}"

  step "Richte PHP-Repositories ein."
  setup_php_repo "${manager}" "${family}" "${php_version}"
  php_version="$(resolve_php_version "${php_version}" "${manager}" "${family}")"
  ensure_panel_php_version_supported "${php_version}"

  resolve_php_packages   "${manager}" "${php_version}" "${db_system}"
  resolve_db_packages    "${manager}" "${db_system}"
  resolve_redis_packages "${manager}"

  local base_pkgs=(curl ca-certificates unzip openssl jq tar)
  base_pkgs+=("$(nginx_packages "${manager}")")

  install_packages "${manager}" "${base_pkgs[@]}" "${PHP_BASE_PKGS[@]}" "${DB_PKGS[@]}" "${REDIS_SERVER_PKGS[@]}"
  install_musicbot_system_dependencies "${manager}"
  if [[ "${manager}" == "apt" ]]; then
    set_php_alternatives_for_target "${php_version}"
    ensure_target_fpm_service "${php_version}"
  fi

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
  start_first_available_service "${REDIS_SERVICES[@]}"

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
  install_panel_release "${install_dir}" "${release_version}" "${github_token}"

  local core_dir="${install_dir}/core"
  [[ -d "${core_dir}" ]] || fatal "core/-Verzeichnis nicht gefunden: ${core_dir}"
  if [[ -z "${EASYWI_CORE_VERSION:-}" && -f "${install_dir}/.easywi-release-version" ]]; then
    EASYWI_CORE_VERSION="$(tr -d '\r\n' < "${install_dir}/.easywi-release-version")"
    export EASYWI_CORE_VERSION
  fi

  [[ -z "${app_secret}" ]]  && app_secret="$(random_hex 32)"
  [[ -z "${enc_keys}" ]]    && enc_keys="$(random_base64 32)"

  [[ "${setup_ssl}" == "true" && "${web_hostname}" != "_" ]] && web_scheme="https"

  local default_uri="${web_scheme}://${web_hostname}"
  [[ "${web_hostname}" == "_" ]] && default_uri="${web_scheme}://localhost"

  # Write the runtime configuration before installing dependencies so manual
  # Symfony maintenance commands can boot in prod. Composer auto-scripts are
  # disabled below; migrations/cache/assets are run explicitly in installer
  # order after dependencies are present.
  write_env_local "${core_dir}/.env.local" \
    "${db_driver}" "${db_host}" "${db_port}" "${db_name}" "${db_user}" "${db_password}" \
    "${app_secret}" "${enc_keys}" "${reg_token}" "${default_uri}" "${install_dir}"
  write_db_config "${db_driver}" "${db_host}" "${db_port}" "${db_name}" "${db_user}" "${db_password}"

  [[ -n "${github_token}" ]] && printf 'APP_GITHUB_TOKEN="%s"\n' "${github_token}" >> "${core_dir}/.env.local"

  step "Installiere Composer-Abhängigkeiten."
  run_composer install \
    --no-dev --no-scripts --optimize-autoloader --no-progress --prefer-dist \
    --working-dir "${core_dir}" --quiet

  # nginx/PHP-FPM must be able to read public/ and write runtime state.
  local web_group; web_group="$(id -gn www-data 2>/dev/null || id -gn nginx 2>/dev/null || echo "${system_user}")"
  set_panel_file_permissions "${install_dir}" "${core_dir}" "${system_user}" "${web_group}"
  configure_php_fpm_easywi_sandbox "${PHP_FPM_SERVICE}"

  configure_nginx "${family}" "${web_hostname}" "${core_dir}/public" "${PHP_FPM_SOCKET}"
  configure_musicbot_panel_limits "${php_version}" "$(detect_nginx_conf_dir "${family}")/easywi.conf"

  if [[ "${setup_ssl}" == "true" && "${web_hostname}" != "_" ]]; then
    if setup_certbot "${manager}" "${web_hostname}" "${ssl_email}"; then
      local agent_conf="/etc/easywi/agent.conf"
      if [[ -f "${agent_conf}" ]]; then
        if grep -q "^api_url=" "${agent_conf}"; then
          sed -i "s|^api_url=http://|api_url=https://|" "${agent_conf}"
          ok "Agent-Konfiguration auf HTTPS aktualisiert (${agent_conf})."
        fi
        if systemctl is-active --quiet easywi-agent.service 2>/dev/null; then
         if systemctl restart easywi-agent.service; then
            ok "easywi-agent Dienst neu gestartet."
          else
            warn "Neustart von easywi-agent fehlgeschlagen – bitte manuell prüfen."
          fi
        fi
      fi
    fi
  fi

  if [[ "${run_migrations}" == "true" ]]; then
    run_panel_migrations "${php_bin}" "${core_dir}/bin/console"
    ensure_agent_bootstrap_schema "${db_driver}" "${db_host}" "${db_port}" "${db_name}" "${db_user}" "${db_password}"
  else
    warn "Migrationen übersprungen."
  fi

  if [[ -n "${reg_token}" ]]; then
    if [[ "${run_migrations}" != "true" ]]; then
      fatal "Automatische Agent-Registrierung benötigt Datenbankmigrationen (EASYWI_RUN_MIGRATIONS=true)."
    fi
    create_agent_bootstrap_token "${php_bin}" "${core_dir}/bin/console" "${reg_token}"
  fi

  write_install_info "${install_dir}/INSTALLATION_INFO.txt" \
    "${default_uri}" "${db_driver}" "${db_host}" "${db_port:-Standard}" \
    "${db_name}" "${db_user}" "${db_password}" \
    "$(detect_nginx_conf_dir "${family}")/easywi.conf"

  set_panel_file_permissions "${install_dir}" "${core_dir}" "${system_user}" "${web_group}"

  write_panel_messenger_service        "${php_bin}" "${core_dir}/bin/console" "${core_dir}" "${system_user}" "${web_group}"
  write_panel_scheduler_service        "${php_bin}" "${core_dir}/bin/console" "${core_dir}" "${system_user}" "${web_group}"
  write_panel_console_relay_service    "${php_bin}" "${core_dir}" "${system_user}" "${web_group}"
  write_panel_console_wrapper          "${php_bin}" "${core_dir}/bin/console"
  write_panel_cron                     "${php_bin}" "${core_dir}/bin/console" "${system_user}"

  ok "Panel installiert unter ${install_dir}."
  printf '\n'
  printf '  ╔══════════════════════════════════════╗\n'
  printf '  ║  Panel erreichbar unter: %-13s║\n' "${default_uri}"
  printf '  ║  Zugangsdaten: %s/INSTALLATION_INFO.txt  ║\n' ""
  printf '  ╚══════════════════════════════════════╝\n'
}

cleanup_old_easywi_agent_processes() {
  local main_pid="0" pid exe cmdline
  local expected_config="/etc/easywi/agent.conf"
  if has_systemctl; then
    main_pid="$(systemctl show --property MainPID --value easywi-agent.service 2>/dev/null || echo 0)"
    [[ -z "${main_pid}" ]] && main_pid="0"
  fi
  while IFS= read -r pid; do
    [[ -z "${pid}" || "${pid}" == "${main_pid}" || "${pid}" == "$$" ]] && continue
    exe="$(readlink -f "/proc/${pid}/exe" 2>/dev/null || true)"
    cmdline="$(tr '\0' ' ' < "/proc/${pid}/cmdline" 2>/dev/null || true)"
    if [[ "${exe}" != "/usr/local/bin/easywi-agent" ]]; then
      continue
    fi
    if [[ " ${cmdline} " != *" --config ${expected_config} "* && " ${cmdline} " != *" --config=${expected_config} "* ]]; then
      continue
    fi
    if [[ " ${cmdline} " == *" --wrapper "* || " ${cmdline} " == *" --self-update "* || " ${cmdline} " == *" --version "* ]]; then
      continue
    fi
    warn "Beende alten manuellen EasyWI-Agent-Prozess PID ${pid} (Config ${expected_config})."
    kill "${pid}" 2>/dev/null || true
    sleep 1
    if kill -0 "${pid}" 2>/dev/null; then
      kill -TERM "${pid}" 2>/dev/null || true
    fi
  done < <(pgrep -f '(^|/)easywi-agent([[:space:]]|$)' 2>/dev/null || true)
}


read_agent_interval_seconds_from_conf() {
  local key="$1" default="$2" value=""
  if [[ -f /etc/easywi/agent.conf ]]; then
    value="$(awk -F= -v key="${key}" 'tolower($1)==key {gsub(/^[ \t]+|[ \t]+$/, "", $2); print $2; exit}' /etc/easywi/agent.conf 2>/dev/null || true)"
  fi
  value="${value%s}"
  if [[ "${value}" =~ ^[0-9]+$ ]]; then
    validate_agent_interval_seconds "${value}" "${key}" 2 300
  else
    printf '%s' "${default}"
  fi
}

ensure_agent_service_interval_environment() {
  has_systemctl || return 0
  local poll heartbeat timeout
  poll="${EASYWI_AGENT_JOB_POLL_INTERVAL_SECONDS:-$(read_agent_interval_seconds_from_conf poll_interval 15)}"
  heartbeat="${EASYWI_AGENT_HEARTBEAT_INTERVAL_SECONDS:-$(read_agent_interval_seconds_from_conf heartbeat_interval 30)}"
  timeout="${EASYWI_AGENT_REQUEST_TIMEOUT_SECONDS:-$(read_agent_interval_seconds_from_conf request_timeout 10)}"
  poll="$(validate_agent_interval_seconds "${poll}" "Agent-Polling-Intervall" 2 300)"
  heartbeat="$(validate_agent_interval_seconds "${heartbeat}" "Agent-Heartbeat-Intervall" 2 300)"
  timeout="$(validate_agent_interval_seconds "${timeout}" "Agent-Request-Timeout" 2 300)"
  mkdir -p /etc/systemd/system/easywi-agent.service.d
  cat > /etc/systemd/system/easywi-agent.service.d/intervals.conf <<DROPIN
[Service]
Environment=EASYWI_AGENT_JOB_POLL_INTERVAL_SECONDS=${poll}
Environment=EASYWI_AGENT_HEARTBEAT_INTERVAL_SECONDS=${heartbeat}
Environment=EASYWI_AGENT_REQUEST_TIMEOUT_SECONDS=${timeout}
DROPIN
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
  # $10-$12 poll/heartbeat/request-timeout seconds
  local core_url="$1" bootstrap_token="$2" agent_version="$3"
  local file_base_dir="$4" sftp_base_dir="$5"
  local agent_name="$6" agent_hostname="$7" bind_ips="$8"
  local api_url_override="${9:-}"
  local poll_interval_seconds="${10:-${EASYWI_AGENT_JOB_POLL_INTERVAL_SECONDS:-15}}"
  local heartbeat_interval_seconds="${11:-${EASYWI_AGENT_HEARTBEAT_INTERVAL_SECONDS:-30}}"
  local request_timeout_seconds="${12:-${EASYWI_AGENT_REQUEST_TIMEOUT_SECONDS:-10}}"
  local state_file="${EASYWI_BOOTSTRAP_STATE_FILE:-/etc/easywi/bootstrap-state.json}"

  # URL die in agent.conf als api_url landet – echte Panel-URL, nicht 127.0.0.1
  local api_url="${api_url_override:-${core_url}}"

  poll_interval_seconds="$(validate_agent_interval_seconds "${poll_interval_seconds}" "Agent-Polling-Intervall" 2 300)"
  heartbeat_interval_seconds="$(validate_agent_interval_seconds "${heartbeat_interval_seconds}" "Agent-Heartbeat-Intervall" 2 300)"
  request_timeout_seconds="$(validate_agent_interval_seconds "${request_timeout_seconds}" "Agent-Request-Timeout" 2 300)"

  local manager; manager="$(detect_package_manager)"
  install_packages "${manager}" ca-certificates curl openssl jq

  local arch; arch="$(detect_arch_suffix)"
  install_agent_binaries "${arch}" "${agent_version}"
  prepare_agent_dirs
  cleanup_old_easywi_agent_processes

  mapfile -t bdc < <(build_agent_base_dir_config "${file_base_dir}")
  local primary_base="${bdc[0]}"

  if [[ -z "${core_url}" ]]; then
    warn "Kein Core-URL angegeben – nur Binaries installiert, keine Registrierung."
    write_agent_conf_placeholder
    write_agent_service "${primary_base}" "${sftp_base_dir}" "${poll_interval_seconds}" "${heartbeat_interval_seconds}" "${request_timeout_seconds}"
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
    "${file_base_dir}" "${sftp_base_dir}" "${bind_ips}" \
    "${poll_interval_seconds}" "${heartbeat_interval_seconds}" "${request_timeout_seconds}"
  write_agent_service "${primary_base}" "${sftp_base_dir}" "${poll_interval_seconds}" "${heartbeat_interval_seconds}" "${request_timeout_seconds}"

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

  cleanup_old_easywi_agent_processes
  install_agent_binaries "${arch}" "${agent_version}"

  if [[ "${arch}" == "amd64" ]]; then
    local github_token_upd="${EASYWI_APP_GITHUB_TOKEN:-}"
    install_optional_agent_binary "easywi-musicbot-linux-amd64"         /usr/local/bin/easywi-musicbot         "${agent_version}" "${github_token_upd}"
    install_optional_agent_binary "easywi-teamspeak-bridge-linux-amd64" /usr/local/bin/easywi-teamspeak-bridge "${agent_version}" "${github_token_upd}"
  fi

  if has_systemctl; then
    ensure_agent_service_interval_environment
    systemctl daemon-reload
  fi

  if "${was_active}"; then
    log "Starte Agent neu."
    if has_systemctl; then
      systemctl start easywi-agent.service 2>/dev/null || fatal "Konnte easywi-agent.service nicht starten. Prüfe: systemctl status easywi-agent.service"
      systemctl is-active --quiet easywi-agent.service 2>/dev/null || fatal "easywi-agent.service ist nach dem Update nicht aktiv."
    else
      service_enable_start easywi-agent
    fi
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
  ║  Quellcode aus GitHub Releases (keine Git-Clones) ║
  ╚══════════════════════════════════════════════════╝

INFO

  local install_dir="${EASYWI_INSTALL_DIR:-/var/www/easywi}"
  local repo_url="${EASYWI_REPO_URL:-}" # deprecated: releases are used exclusively
  local repo_ref="${EASYWI_RELEASE_VERSION:-${EASYWI_REPO_REF:-latest}}"
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
  local system_user="${EASYWI_SYSTEM_USER:-www-data}"
  local app_secret="${EASYWI_APP_SECRET:-}"
  local enc_keys="${EASYWI_SECRET_KEY:-}"
  local reg_token="${EASYWI_AGENT_REGISTRATION_TOKEN:-}"
  local github_token="${EASYWI_APP_GITHUB_TOKEN:-}"
  local run_migrations="${EASYWI_RUN_MIGRATIONS:-true}"
  local web_scheme="${EASYWI_WEB_SCHEME:-http}"
  local provision_db="${EASYWI_DB_PROVISION:-true}"
  local setup_ssl="${EASYWI_SETUP_SSL:-false}"
  local ssl_email="${EASYWI_SSL_EMAIL:-}"

  prompt_value install_dir    "Installationsverzeichnis"                     "${install_dir}"
  prompt_value repo_ref       "Release-Version (latest oder Tag)"            "${repo_ref}"
  prompt_value system_user    "Linux-Systembenutzer für EasyWI"              "${system_user}"
  prompt_value php_version    "PHP-Version"                                  "${php_version}"
  prompt_value web_hostname   "Panel-Domain (_ = alle, z.B. panel.example.com)" "${web_hostname}"
  web_hostname="$(normalize_hostname "${web_hostname}")"
  prompt_value provision_db   "Datenbank automatisch erstellen? (true/false)" "${provision_db}"

  # SSL wird direkt im Installer erledigt: Domain eingeben, DNS prüfen,
  # certbot-nginx installieren und Zertifikat ausstellen.
  if [[ "${web_hostname}" != "_" && "${setup_ssl}" != "true" ]]; then
    if is_tty && prompt_yes_no "SSL-Zertifikat via Let's Encrypt jetzt im Installer einrichten?" "yes"; then
      setup_ssl="true"
    fi
  fi
  if [[ "${setup_ssl}" == "true" && "${web_hostname}" != "_" ]]; then
    prompt_value ssl_email "E-Mail für Let's Encrypt/Certbot" "${ssl_email}"
  fi
  [[ "${setup_ssl}" == "true" ]] && web_scheme="https" || web_scheme="http"

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
    "${run_migrations}" "${web_scheme}" "${provision_db}" \
    "${setup_ssl}" "${ssl_email}"
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
  local poll_interval_seconds="${EASYWI_AGENT_JOB_POLL_INTERVAL_SECONDS:-15}"
  local heartbeat_interval_seconds="${EASYWI_AGENT_HEARTBEAT_INTERVAL_SECONDS:-30}"
  local request_timeout_seconds="${EASYWI_AGENT_REQUEST_TIMEOUT_SECONDS:-10}"

  [[ -z "${file_base_dir}" ]] && file_base_dir="$(detect_default_base_dirs)"
  [[ -z "${bind_ips}" ]]      && bind_ips="$(detect_primary_ipv4)"
  [[ -z "${bind_ips}" ]]      && fatal "Server-IP konnte nicht ermittelt werden. Bitte EASYWI_BIND_IP_ADDRESSES setzen."

  prompt_value core_url        "Panel API URL (leer = nur Binary)"    "${core_url}"
  prompt_value bootstrap_token "Bootstrap-Token"                      "${bootstrap_token}"
  prompt_value agent_version   "Agent-Version (latest oder Tag)"      "${agent_version}"
  prompt_value file_base_dir   "Dateiverzeichnisse (kommagetrennt)"   "${file_base_dir}"
  prompt_value sftp_base_dir   "SFTP-Basisverzeichnis"                "${sftp_base_dir}"
  prompt_value bind_ips        "Bind-IP-Adressen (optional)"          "${bind_ips}"
  prompt_value agent_name      "Agent-Name (optional)"                "${agent_name}"
  prompt_value agent_hostname  "Agent-Hostname (optional)"            "${agent_hostname}"
  agent_interval_traffic_hint
  if is_tty; then
    local requested_poll_interval
    requested_poll_interval="$(read_from_tty "Wie oft soll der Agent neue Aufgaben beim Panel abfragen? Sekunden [${poll_interval_seconds}]: ")"
    [[ -n "${requested_poll_interval}" ]] && poll_interval_seconds="${requested_poll_interval}"
  fi
  poll_interval_seconds="$(validate_agent_interval_seconds "${poll_interval_seconds}" "Agent-Polling-Intervall" 2 300)"
  heartbeat_interval_seconds="$(validate_agent_interval_seconds "${heartbeat_interval_seconds}" "Agent-Heartbeat-Intervall" 2 300)"
  request_timeout_seconds="$(validate_agent_interval_seconds "${request_timeout_seconds}" "Agent-Request-Timeout" 2 300)"

  [[ -n "${core_url}" ]] && core_url="${core_url%/}"

  install_agent \
    "${core_url}" "${bootstrap_token}" "${agent_version}" \
    "${file_base_dir}" "${sftp_base_dir}" \
    "${agent_name}" "${agent_hostname}" "${bind_ips}" "" \
    "${poll_interval_seconds}" "${heartbeat_interval_seconds}" "${request_timeout_seconds}"
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
  local repo_url="${EASYWI_REPO_URL:-}" # deprecated: releases are used exclusively
  local repo_ref="${EASYWI_RELEASE_VERSION:-${EASYWI_REPO_REF:-latest}}"
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
  local system_user="${EASYWI_SYSTEM_USER:-www-data}"
  local app_secret="${EASYWI_APP_SECRET:-}"
  local enc_keys="${EASYWI_SECRET_KEY:-}"
  local github_token="${EASYWI_APP_GITHUB_TOKEN:-}"
  local run_migrations="${EASYWI_RUN_MIGRATIONS:-true}"
  local web_scheme="${EASYWI_WEB_SCHEME:-http}"
  local provision_db="${EASYWI_DB_PROVISION:-true}"
  local setup_ssl="${EASYWI_SETUP_SSL:-false}"
  local ssl_email="${EASYWI_SSL_EMAIL:-}"

  # ── Agent-Parameter ─────────────────────────────────────────────────────
  local agent_version="${EASYWI_AGENT_VERSION:-latest}"
  local file_base_dir="${EASYWI_FILE_BASE_DIR:-}"
  local sftp_base_dir="${EASYWI_SFTP_BASE_DIR:-/var/lib/easywi/sftp}"
  local agent_name="${EASYWI_AGENT_NAME:-}"
  local agent_hostname="${EASYWI_AGENT_HOSTNAME:-}"
  local bind_ips="${EASYWI_BIND_IP_ADDRESSES:-}"
  local poll_interval_seconds="${EASYWI_AGENT_JOB_POLL_INTERVAL_SECONDS:-2}"
  local heartbeat_interval_seconds="${EASYWI_AGENT_HEARTBEAT_INTERVAL_SECONDS:-10}"
  local request_timeout_seconds="${EASYWI_AGENT_REQUEST_TIMEOUT_SECONDS:-10}"

  [[ -z "${file_base_dir}" ]] && file_base_dir="$(detect_default_base_dirs)"
  [[ -z "${bind_ips}" ]]      && bind_ips="$(detect_primary_ipv4)"
  [[ -z "${bind_ips}" ]]      && fatal "Server-IP konnte nicht ermittelt werden. Bitte EASYWI_BIND_IP_ADDRESSES setzen."

  # ── Panel-Fragen ─────────────────────────────────────────────────────────
  menu_output <<'HDR'

  ── Panel-Einstellungen ──────────────────────────────
HDR
  prompt_value install_dir    "Installationsverzeichnis"                                 "${install_dir}"
  prompt_value repo_ref       "Release-Version (latest oder Tag)"                        "${repo_ref}"
  prompt_value system_user    "Linux-Systembenutzer für EasyWI"                          "${system_user}"
  prompt_value php_version    "PHP-Version"                                              "${php_version}"
  prompt_value web_hostname   "Panel-Domain (_ = alle, z.B. panel.example.com)"          "${web_hostname}"
  web_hostname="$(normalize_hostname "${web_hostname}")"
  prompt_value provision_db   "Datenbank automatisch erstellen? (true/false)"            "${provision_db}"

  # SSL wird direkt im Installer erledigt: Domain eingeben, DNS prüfen,
  # certbot-nginx installieren und Zertifikat ausstellen.
  if [[ "${web_hostname}" != "_" && "${setup_ssl}" != "true" ]]; then
    if is_tty && prompt_yes_no "SSL-Zertifikat via Let's Encrypt jetzt im Installer einrichten?" "yes"; then
      setup_ssl="true"
    fi
  fi
  if [[ "${setup_ssl}" == "true" && "${web_hostname}" != "_" ]]; then
    prompt_value ssl_email "E-Mail für Let's Encrypt/Certbot" "${ssl_email}"
  fi
  [[ "${setup_ssl}" == "true" ]] && web_scheme="https" || web_scheme="http"

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
  poll_interval_seconds="$(validate_agent_interval_seconds "${poll_interval_seconds}" "Agent-Polling-Intervall" 2 300)"
  heartbeat_interval_seconds="$(validate_agent_interval_seconds "${heartbeat_interval_seconds}" "Agent-Heartbeat-Intervall" 2 300)"
  request_timeout_seconds="$(validate_agent_interval_seconds "${request_timeout_seconds}" "Agent-Request-Timeout" 2 300)"
  log "Gemeinsame Panel+Agent-Installation: schnelle Agent-Intervalle werden gesetzt (Polling ${poll_interval_seconds}s, Heartbeat ${heartbeat_interval_seconds}s, Timeout ${request_timeout_seconds}s)."

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
    "${run_migrations}" "${web_scheme}" "${provision_db}" \
    "${setup_ssl}" "${ssl_email}"

  # ── Schritt 2: Agent gegen das soeben installierte Panel registrieren ────
  # Bootstrap-Call geht immer gegen 127.0.0.1 – kein DNS nötig, Panel läuft bereits.
  # Die echte Panel-URL kommt in agent.conf als api_url; bei Wildcard-Hostname
  # wird die primäre Server-IP ermittelt, damit der Agent das Panel auch von
  # anderen Hosts aus erreichen kann.
  local bootstrap_url="http://127.0.0.1"
  local real_panel_url="${web_scheme}://${web_hostname}"
  if [[ "${web_hostname}" == "_" ]]; then
    local server_ip
    server_ip="$(detect_primary_ipv4)"
    if [[ -n "${server_ip}" ]]; then
      real_panel_url="${web_scheme}://${server_ip}"
    else
      real_panel_url="http://127.0.0.1"
      warn "Server-IP konnte nicht ermittelt werden – api_url fällt auf 127.0.0.1 zurück."
    fi
  fi

  menu_output <<'HDR'

  ── Starte Agent-Installation & Registrierung ────────
HDR
  log "Bootstrap via: ${bootstrap_url}  |  api_url in agent.conf: ${real_panel_url}"

  install_agent \
    "${bootstrap_url}" "${reg_token}" "${agent_version}" \
    "${file_base_dir}" "${sftp_base_dir}" \
    "${agent_name}" "${agent_hostname}" "${bind_ips}" \
    "${real_panel_url}" \
    "${poll_interval_seconds}" "${heartbeat_interval_seconds}" "${request_timeout_seconds}"

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
# CPU Performance – GRUB + Governor
# ---------------------------------------------------------------------------

detect_cpu_vendor() {
  grep -m1 'vendor_id' /proc/cpuinfo 2>/dev/null | awk '{print $3}' || echo "unknown"
}

setup_cpu_grub() {
  local extra_args="$1"
  local grub_default="/etc/default/grub"

  if [[ ! -f "${grub_default}" ]]; then
    warn "GRUB-Konfiguration nicht gefunden (${grub_default}) – überspringe GRUB-Anpassung."
    warn "Bitte Kernel-Parameter manuell eintragen: ${extra_args}"
    return 0
  fi

  step "Bearbeite GRUB-Konfiguration (${grub_default})."
  cp "${grub_default}" "${grub_default}.easywi.bak"
  ok "Backup erstellt: ${grub_default}.easywi.bak"

  # Arbeite auf GRUB_CMDLINE_LINUX (gilt für alle Boot-Einträge).
  # Falls nicht vorhanden, lege den Key an.
  local current=""
  if grep -qE '^GRUB_CMDLINE_LINUX=' "${grub_default}"; then
    current="$(grep -E '^GRUB_CMDLINE_LINUX=' "${grub_default}" \
               | sed 's/^GRUB_CMDLINE_LINUX=//' | tr -d '"' | xargs)"
  fi

  # Entferne bereits vorhandene CPU-/Idle-Parameter (idempotent)
  local cleaned="${current}"
  for tok in intel_pstate intel_idle.max_cstate amd_pstate processor.max_cstate idle; do
    cleaned="$(printf '%s' "${cleaned}" | sed -E "s/${tok}=[^ ]*( |$)/ /g" | tr -s ' ')"
  done
  cleaned="${cleaned# }"; cleaned="${cleaned% }"

  local new_cmdline="${cleaned:+${cleaned} }${extra_args}"

  if grep -qE '^GRUB_CMDLINE_LINUX=' "${grub_default}"; then
    sed -i "s|^GRUB_CMDLINE_LINUX=.*|GRUB_CMDLINE_LINUX=\"${new_cmdline}\"|" "${grub_default}"
  else
    printf '\nGRUB_CMDLINE_LINUX="%s"\n' "${new_cmdline}" >> "${grub_default}"
  fi
  ok "GRUB_CMDLINE_LINUX gesetzt: ${new_cmdline}"

  # Regeneriere grub.cfg je nach Distro
  local updated=false
  if command -v update-grub >/dev/null 2>&1; then
    update-grub 2>/dev/null && updated=true
  fi
  if ! "${updated}" && command -v grub2-mkconfig >/dev/null 2>&1; then
    local cfg="/boot/grub2/grub.cfg"
    # UEFI-Pfad bevorzugen wenn vorhanden
    local uefi_cfg
    uefi_cfg="$(find /boot/efi/EFI -maxdepth 2 \( -name 'grub.cfg' -o -name 'grub2.cfg' \) 2>/dev/null | head -n1 || true)"
    [[ -n "${uefi_cfg}" ]] && cfg="${uefi_cfg}"
    grub2-mkconfig -o "${cfg}" 2>/dev/null && updated=true
  fi
  if ! "${updated}" && command -v grub-mkconfig >/dev/null 2>&1; then
    grub-mkconfig -o /boot/grub/grub.cfg 2>/dev/null && updated=true
  fi

  if "${updated}"; then
    ok "grub.cfg erfolgreich regeneriert."
  else
    warn "GRUB-Regenerierung nicht möglich – bitte manuell ausführen (update-grub / grub2-mkconfig)."
  fi

  warn "WICHTIG: Neustart erforderlich, damit die GRUB-Parameter wirksam werden!"
}

try_install_cpu_tools() {
  local manager="$1"; shift
  local packages=("$@")
  [[ ${#packages[@]} -eq 0 ]] && return 1

  log "Versuche optionale CPU-Tools: ${packages[*]}"
  case "${manager}" in
    apt)
      if [[ "${APT_UPDATED}" -eq 0 ]]; then
        env DEBIAN_FRONTEND=noninteractive apt-get update >/dev/null 2>&1 || return 1
        APT_UPDATED=1
      fi
      env DEBIAN_FRONTEND=noninteractive apt-get install -y "${packages[@]}" >/dev/null 2>&1
      ;;
    dnf)    dnf install -y "${packages[@]}" >/dev/null 2>&1 ;;
    yum)    yum install -y "${packages[@]}" >/dev/null 2>&1 ;;
    zypper) zypper --non-interactive install --no-confirm "${packages[@]}" >/dev/null 2>&1 ;;
    pacman) pacman -Sy --noconfirm --needed "${packages[@]}" >/dev/null 2>&1 ;;
    apk)    apk add --no-cache "${packages[@]}" >/dev/null 2>&1 ;;
    *)      return 1 ;;
  esac
}

install_cpufreq_tools() {
  local manager="$1"
  local installed=false
  step "Installiere CPU-Frequenz-Tools (optional, distro-spezifische Fallbacks)."

  case "${manager}" in
    apt)
      try_install_cpu_tools "${manager}" cpufrequtils linux-cpupower && installed=true
      "${installed}" || try_install_cpu_tools "${manager}" cpufrequtils linux-tools-common linux-tools-generic && installed=true
      "${installed}" || try_install_cpu_tools "${manager}" cpufrequtils && installed=true
      ;;
    dnf|yum)
      try_install_cpu_tools "${manager}" kernel-tools && installed=true
      "${installed}" || try_install_cpu_tools "${manager}" cpupowerutils && installed=true
      ;;
    zypper)
      try_install_cpu_tools "${manager}" cpupower && installed=true
      "${installed}" || try_install_cpu_tools "${manager}" cpufrequtils && installed=true
      ;;
    pacman)
      try_install_cpu_tools "${manager}" cpupower && installed=true
      ;;
    apk)
      try_install_cpu_tools "${manager}" cpupower && installed=true
      "${installed}" || try_install_cpu_tools "${manager}" cpufrequtils && installed=true
      ;;
  esac

  if "${installed}"; then
    ok "CPU-Frequenz-Tools installiert."
  else
    warn "Keine CPU-Frequenz-Tools installiert – fahre mit generischem sysfs-Fallback fort."
  fi
}
find_cpupower() {
  command -v cpupower 2>/dev/null || printf '/usr/bin/cpupower'
}

write_sysfs_value() {
  local path="$1" value="$2"
  [[ -w "${path}" ]] || return 1
  printf '%s' "${value}" > "${path}" 2>/dev/null
}

apply_cpu_performance_sysfs() {
  local governor="$1"
  local changed=false policy max_freq pref_file gov_file

  for policy in /sys/devices/system/cpu/cpufreq/policy*; do
    [[ -d "${policy}" ]] || continue

    gov_file="${policy}/scaling_governor"
    if [[ -f "${policy}/scaling_available_governors" ]] && ! grep -qw "${governor}" "${policy}/scaling_available_governors"; then
      warn "Governor '${governor}' wird von ${policy##*/} nicht angeboten."
    elif write_sysfs_value "${gov_file}" "${governor}"; then
      changed=true
    fi

    max_freq=""
    [[ -r "${policy}/cpuinfo_max_freq" ]] && max_freq="$(cat "${policy}/cpuinfo_max_freq")"
    [[ -z "${max_freq}" && -r "${policy}/scaling_max_freq" ]] && max_freq="$(cat "${policy}/scaling_max_freq")"
    if [[ -n "${max_freq}" ]] && write_sysfs_value "${policy}/scaling_min_freq" "${max_freq}"; then
      changed=true
    fi

    pref_file="${policy}/energy_performance_preference"
    if [[ -f "${policy}/energy_performance_available_preferences" ]] \
      && grep -qw performance "${policy}/energy_performance_available_preferences" \
      && write_sysfs_value "${pref_file}" performance; then
      changed=true
    fi
  done

  write_sysfs_value /sys/devices/system/cpu/intel_pstate/min_perf_pct 100 && changed=true
  write_sysfs_value /sys/devices/system/cpu/intel_pstate/max_perf_pct 100 && changed=true
  write_sysfs_value /sys/devices/system/cpu/intel_pstate/no_turbo 0 && changed=true
  write_sysfs_value /sys/devices/system/cpu/cpufreq/boost 1 && changed=true
  write_sysfs_value /sys/devices/system/cpu/boost 1 && changed=true

  "${changed}"
}

set_cpu_governor_now() {
  local governor="$1"
  local changed=false cpupower_bin

  cpupower_bin="$(find_cpupower)"
  if [[ -x "${cpupower_bin}" ]]; then
    "${cpupower_bin}" -c all frequency-set -g "${governor}" 2>/dev/null && changed=true
  fi

  if command -v cpufreq-set >/dev/null 2>&1; then
    local cpu
    for cpu in /sys/devices/system/cpu/cpu[0-9]*; do
      [[ -d "${cpu}" ]] || continue
      cpu="${cpu##*/}"
      cpufreq-set -c "${cpu#cpu}" -g "${governor}" 2>/dev/null && changed=true
    done
  fi

  if apply_cpu_performance_sysfs "${governor}"; then
    changed=true
  fi

  if command -v x86_energy_perf_policy >/dev/null 2>&1; then
    x86_energy_perf_policy performance 2>/dev/null && changed=true
  fi

  if "${changed}"; then
    ok "CPU-Governor, Mindesttakt und Energieprofil jetzt auf maximale Performance gesetzt."
  else
    warn "CPU-Performance konnte nicht sofort gesetzt werden (z.B. kein cpufreq-/pstate-Treiber geladen)."
  fi

  if [[ -x "${cpupower_bin}" ]]; then
    if "${cpupower_bin}" idle-set -D 0 2>/dev/null; then
      ok "CPU-Idle-States deaktiviert (cpupower idle-set -D 0)."
    else
      warn "cpupower idle-set -D 0 fehlgeschlagen – ggf. nach Reboot wirksam."
    fi
  else
    warn "cpupower nicht verfügbar – idle-set wird nach Reboot via Service versucht."
  fi
}

write_cpu_performance_helper() {
  local governor="$1"

  cat > /usr/local/sbin/easywi-cpu-performance.sh <<'HELPER'
#!/bin/sh
set -u
GOVERNOR="${1:-performance}"
RETRIES="${2:-45}"
SLEEP_SECONDS="${3:-1}"

write_value() {
  path="$1"
  value="$2"
  [ -w "$path" ] || return 1
  printf '%s' "$value" > "$path" 2>/dev/null
}

policy_max_freq() {
  policy="$1"
  if [ -r "$policy/cpuinfo_max_freq" ]; then
    cat "$policy/cpuinfo_max_freq"
  elif [ -r "$policy/scaling_max_freq" ]; then
    cat "$policy/scaling_max_freq"
  fi
}

apply_once() {
  for policy in /sys/devices/system/cpu/cpufreq/policy*; do
    [ -d "$policy" ] || continue

    if [ -r "$policy/scaling_available_governors" ] && ! grep -qw "$GOVERNOR" "$policy/scaling_available_governors"; then
      :
    else
      write_value "$policy/scaling_governor" "$GOVERNOR" || true
    fi

    max_freq="$(policy_max_freq "$policy")"
    if [ -n "$max_freq" ]; then
      write_value "$policy/scaling_min_freq" "$max_freq" || true
    fi

    if [ -r "$policy/energy_performance_available_preferences" ] \
      && grep -qw performance "$policy/energy_performance_available_preferences"; then
      write_value "$policy/energy_performance_preference" performance || true
    fi
  done

  write_value /sys/devices/system/cpu/intel_pstate/min_perf_pct 100 || true
  write_value /sys/devices/system/cpu/intel_pstate/max_perf_pct 100 || true
  write_value /sys/devices/system/cpu/intel_pstate/no_turbo 0 || true
  write_value /sys/devices/system/cpu/cpufreq/boost 1 || true
  write_value /sys/devices/system/cpu/boost 1 || true

  if command -v cpupower >/dev/null 2>&1; then
    cpupower -c all frequency-set -g "$GOVERNOR" >/dev/null 2>&1 || true
    cpupower idle-set -D 0 >/dev/null 2>&1 || true
  elif [ -x /usr/bin/cpupower ]; then
    /usr/bin/cpupower -c all frequency-set -g "$GOVERNOR" >/dev/null 2>&1 || true
    /usr/bin/cpupower idle-set -D 0 >/dev/null 2>&1 || true
  fi

  if command -v x86_energy_perf_policy >/dev/null 2>&1; then
    x86_energy_perf_policy performance >/dev/null 2>&1 || true
  fi
}

verify_performance() {
  found=0
  failed=0

  for policy in /sys/devices/system/cpu/cpufreq/policy*; do
    [ -d "$policy" ] || continue
    found=1

    if [ -r "$policy/scaling_available_governors" ] && grep -qw "$GOVERNOR" "$policy/scaling_available_governors"; then
      current_governor="$(cat "$policy/scaling_governor" 2>/dev/null || true)"
      [ "$current_governor" = "$GOVERNOR" ] || failed=1
    fi

    max_freq="$(policy_max_freq "$policy")"
    current_min=""
    [ -r "$policy/scaling_min_freq" ] && current_min="$(cat "$policy/scaling_min_freq" 2>/dev/null || true)"
    if [ -n "$max_freq" ] && [ -n "$current_min" ] && [ "$current_min" != "$max_freq" ]; then
      failed=1
    fi
  done

  [ "$found" -eq 1 ] && [ "$failed" -eq 0 ]
}

i=0
while [ "$i" -lt "$RETRIES" ]; do
  apply_once
  verify_performance && exit 0
  i=$((i + 1))
  sleep "$SLEEP_SECONDS"
done

# Final best-effort pass: never fail the boot if a platform exposes read-only cpufreq knobs.
apply_once
exit 0
HELPER
  chmod 0755 /usr/local/sbin/easywi-cpu-performance.sh
}


disable_cpu_power_conflicts() {
  local svc

  if has_systemctl; then
    for svc in ondemand.service power-profiles-daemon.service tuned.service auto-cpufreq.service tlp.service; do
      if systemctl list-unit-files "${svc}" >/dev/null 2>&1; then
        systemctl disable --now "${svc}" >/dev/null 2>&1 || true
        systemctl mask "${svc}" >/dev/null 2>&1 || true
        ok "Konkurrierenden CPU-Power-Dienst deaktiviert: ${svc}"
      fi
    done
  elif has_openrc; then
    for svc in cpufreqd tlp; do
      if rc-service "${svc}" status >/dev/null 2>&1; then
        rc-service "${svc}" stop >/dev/null 2>&1 || true
        rc-update del "${svc}" default >/dev/null 2>&1 || true
        ok "Konkurrierenden CPU-Power-Dienst deaktiviert: ${svc}"
      fi
    done
  fi
}

write_cpu_governor_service() {
  local governor="$1"

  write_cpu_performance_helper "${governor}"
  disable_cpu_power_conflicts

  if has_systemctl; then
    cat > /etc/systemd/system/easywi-cpu-governor.service <<UNIT
[Unit]
Description=EasyWI CPU Performance Governor
After=multi-user.target systemd-modules-load.service
Wants=systemd-modules-load.service
Conflicts=ondemand.service power-profiles-daemon.service tuned.service auto-cpufreq.service tlp.service

[Service]
Type=oneshot
ExecStartPre=-/bin/sh -c 'command -v modprobe >/dev/null 2>&1 && modprobe msr || true'
ExecStart=/usr/local/sbin/easywi-cpu-performance.sh ${governor} 45 1

[Install]
WantedBy=multi-user.target
UNIT
    cat > /etc/systemd/system/easywi-cpu-governor.timer <<UNIT
[Unit]
Description=EasyWI CPU Performance Governor Re-Apply Timer

[Timer]
OnBootSec=30sec
OnUnitActiveSec=5min
AccuracySec=30sec
Unit=easywi-cpu-governor.service

[Install]
WantedBy=timers.target
UNIT
    service_enable_start easywi-cpu-governor.service
    systemctl enable --now easywi-cpu-governor.timer >/dev/null 2>&1 || warn "Konnte easywi-cpu-governor.timer nicht aktivieren."
    ok "CPU-Performance-Service und Re-Apply-Timer aktiviert."

  elif has_openrc; then
    cat > /etc/local.d/easywi-cpu-governor.start <<RC
#!/bin/sh
/usr/local/sbin/easywi-cpu-performance.sh ${governor} 45 1
RC
    chmod +x /etc/local.d/easywi-cpu-governor.start
    rc-update add local default 2>/dev/null || true
    ok "CPU-Performance-Start-Skript eingerichtet (/etc/local.d/easywi-cpu-governor.start)."

  else
    warn "Kein systemd/OpenRC – CPU-Performance muss manuell persistiert werden."
  fi
}

setup_cpu_performance() {
  local manager; manager="$(detect_package_manager)"
  local cpu_vendor; cpu_vendor="$(detect_cpu_vendor)"
  local governor="performance"
  local grub_args

  log "CPU-Vendor: ${cpu_vendor}"

  case "${cpu_vendor}" in
    GenuineIntel)
      step "Intel-CPU erkannt – nutze intel_pstate/HWP und limitiere tiefe C-States."
      # Debian 13 nutzt auf modernen Intel-Systemen meist intel_pstate/HWP.
      # Nicht deaktivieren: stattdessen Mindesttakt/Performance-Preference per sysfs erzwingen.
      grub_args="intel_idle.max_cstate=1 processor.max_cstate=1 idle=mwait"
      ;;
    AuthenticAMD)
      step "AMD-CPU erkannt – setze amd_pstate auf passive, limitiere C-States."
      # amd_pstate=passive lässt den performance-Governor und feste Mindestfrequenz zu.
      grub_args="amd_pstate=passive processor.max_cstate=1 idle=mwait"
      ;;
    *)
      step "Unbekannter CPU-Vendor (${cpu_vendor}) – nutze generische Parameter."
      grub_args="processor.max_cstate=1"
      ;;
  esac

  install_cpufreq_tools "${manager}"

  # Sofort wirksam setzen (vor dem Reboot)
  set_cpu_governor_now "${governor}"

  # Persistent via systemd/OpenRC
  write_cpu_governor_service "${governor}"

  # GRUB anpassen
  setup_cpu_grub "${grub_args}"

  ok "CPU-Performance-Modus eingerichtet."
  printf '\n'
  printf '  ╔══════════════════════════════════════════════════╗\n'
  printf '  ║  CPU Performance-Modus aktiv                     ║\n'
  printf '  ║                                                  ║\n'
  printf '  ║  Governor:  %-37s║\n' "${governor}"
  printf '  ║  CPU:       %-37s║\n' "${cpu_vendor}"
  printf '  ║  GRUB-Params: %-35s║\n' "${grub_args}"
  printf '  ║                                                  ║\n'
  printf '  ║  Neustart erforderlich für GRUB-Änderungen!      ║\n'
  printf '  ╚══════════════════════════════════════════════════╝\n'
}

run_cpu_performance() {
  menu_output <<'INFO'

  ╔══════════════════════════════════════════════════╗
  ║  CPU Performance-Modus                           ║
  ║  Setzt Governor und Mindesttakt auf Maximum       ║
  ║  – auch im Idle (kein Throttling)                ║
  ║  Passt GRUB für Intel und AMD CPUs an            ║
  ║  Kompatibel mit allen unterstützten Distros      ║
  ╚══════════════════════════════════════════════════╝

INFO
  setup_cpu_performance
}

run_panel_ssl_setup() {
  menu_output <<'INFO'

  ╔══════════════════════════════════════════════════╗
  ║  Panel-SSL nachträglich einrichten               ║
  ║  Für bereits installierte Panels: Domain setzen,  ║
  ║  Nginx aktualisieren und Let's Encrypt starten    ║
  ╚══════════════════════════════════════════════════╝

INFO

  local install_dir="${EASYWI_INSTALL_DIR:-/var/www/easywi}"
  local web_hostname="${EASYWI_WEB_HOSTNAME:-}"
  local ssl_email="${EASYWI_SSL_EMAIL:-}"
  local php_fpm_socket="${EASYWI_PHP_FPM_SOCKET:-}"

  prompt_value install_dir  "Installationsverzeichnis des bestehenden Panels" "${install_dir}"
  prompt_value web_hostname "Panel-Domain (z.B. panel.example.com)" "${web_hostname}"
  web_hostname="$(normalize_hostname "${web_hostname}")"
  prompt_value ssl_email    "E-Mail für Let's Encrypt/Certbot" "${ssl_email}"

  [[ -n "${web_hostname}" && "${web_hostname}" != "_" ]] || fatal "Bitte eine echte Panel-Domain angeben."
  is_valid_certbot_domain "${web_hostname}" || fatal "Ungültige Domain: ${web_hostname}"

  local core_dir="${install_dir}/core"
  [[ -d "${core_dir}/public" ]] || fatal "Panel public/-Verzeichnis nicht gefunden: ${core_dir}/public"

  if [[ -z "${php_fpm_socket}" ]]; then
    php_fpm_socket="$(detect_existing_php_fpm_socket || true)"
  fi
  [[ -n "${php_fpm_socket}" ]] || fatal "PHP-FPM-Socket nicht gefunden. Bitte EASYWI_PHP_FPM_SOCKET setzen."

  local family manager
  family="$(detect_os_family)"
  manager="$(detect_package_manager)"

  service_enable_start nginx
  configure_nginx "${family}" "${web_hostname}" "${core_dir}/public" "${php_fpm_socket}"
  setup_certbot "${manager}" "${web_hostname}" "${ssl_email}" || fatal "SSL-Zertifikat konnte nicht ausgestellt werden."
  update_default_uri "${core_dir}/.env.local" "https://${web_hostname}"

  ok "Panel-SSL wurde nachträglich eingerichtet. DEFAULT_URI=https://${web_hostname}"
}

run_musicbot_prerequisites_repair() {
  menu_output <<'INFO'

  ╔══════════════════════════════════════════════════╗
  ║  Musicbot Voraussetzungen reparieren/neu anwenden║
  ║  Setzt PHP/Nginx Upload-Limits und installiert   ║
  ║  Systempakete für Musicbot/TeamSpeak-Bridge.     ║
  ╚══════════════════════════════════════════════════╝

INFO
  local family manager php_version nginx_config
  family="$(detect_os_family)"
  manager="$(detect_package_manager)"
  php_version="${EASYWI_PHP_VERSION:-${DEFAULT_PHP_VERSION}}"
  nginx_config="$(detect_nginx_conf_dir "${family}")/easywi.conf"
  prompt_value php_version "PHP-Version für gezielte Prüfung (leer = automatisch erkannte Versionen zusätzlich)" "${php_version}"
  prompt_value nginx_config "EasyWI nginx-vHost-Konfiguration" "${nginx_config}"

  install_musicbot_system_dependencies "${manager}"
  configure_musicbot_panel_limits "${php_version}" "${nginx_config}"
  ok "Musicbot Voraussetzungen wurden neu angewendet."
}



# ---------------------------------------------------------------------------
# Debian/Ubuntu robust preflight, fix and diagnostics modes
# ---------------------------------------------------------------------------

EASYWI_BASE_PACKAGES=(
  bash curl wget ca-certificates gnupg apt-transport-https lsb-release software-properties-common
  unzip zip tar gzip bzip2 xz-utils git jq nano vim sudo cron logrotate locales acl rsync
  openssh-client openssh-server net-tools iproute2 iputils-ping dnsutils netcat-openbsd lsof
  procps psmisc htop screen tmux socat findutils coreutils util-linux file libmagic1
  build-essential make gcc g++ libc6 libstdc++6 lib32gcc-s1 lib32stdc++6 lib32z1 zlib1g zlib1g-dev
  libssl-dev openssl libcurl4 libcurl4-openssl-dev
)
EASYWI_CRITICAL_PHP_MODULES=(curl mbstring intl xml zip gd bcmath opcache fileinfo iconv json pdo pdo_mysql posix dom simplexml tokenizer ctype)
EASYWI_PHP_SETTINGS=(
  'memory_limit=512M'
  'max_execution_time=120'
  'max_input_time=120'
  'post_max_size=128M'
  'upload_max_filesize=128M'
  'default_charset="UTF-8"'
  'date.timezone=Europe/Berlin'
  'expose_php=Off'
  'opcache.enable=1'
  'opcache.enable_cli=1'
  'realpath_cache_size=4096K'
  'realpath_cache_ttl=600'
)

record_pass() { ok "$*"; }
record_warn() { warn "$*"; }
record_fail() { INSTALLER_FAILURES+=("$*"); log "   ✗ $*"; }

print_summary() {
  printf '\n%s Zusammenfassung\n' "${INSTALLER_NAME}" >&2
  printf '%s PASS: %d | WARNINGS: %d | FAILURES: %d\n' "${INSTALLER_NAME}" "${#INSTALLER_PASSES[@]}" "${#INSTALLER_WARNINGS[@]}" "${#INSTALLER_FAILURES[@]}" >&2
  local item
  if ((${#INSTALLER_WARNINGS[@]})); then
    printf '%s Warnungen:\n' "${INSTALLER_NAME}" >&2
    for item in "${INSTALLER_WARNINGS[@]}"; do printf '%s   - %s\n' "${INSTALLER_NAME}" "${item}" >&2; done
  fi
  if ((${#INSTALLER_FAILURES[@]})); then
    printf '%s Fehler:\n' "${INSTALLER_NAME}" >&2
    for item in "${INSTALLER_FAILURES[@]}"; do printf '%s   - %s\n' "${INSTALLER_NAME}" "${item}" >&2; done
    printf '%s Nächste Schritte: /var/log/easywi-installer.log und ggf. %s prüfen.\n' "${INSTALLER_NAME}" "${DIAG_LOG_FILE}" >&2
    return 1
  fi
  printf '%s Ergebnis: PASS. Log: %s\n' "${INSTALLER_NAME}" "${LOG_FILE}" >&2
}

parse_cli_args() {
  while (($#)); do
    case "$1" in
      --check|--diagnose|--fix) INSTALLER_MODE="${1#--}" ;;
      --help|-h)
        cat <<HELP
EasyWI Linux Installer ${VERSION}
Usage: $0 [--check|--diagnose|--fix]
  --check     Prüft Debian/Ubuntu-Voraussetzungen ohne Änderungen.
  --diagnose  Schreibt Diagnosebericht nach ${DIAG_LOG_FILE} ohne Änderungen.
  --fix       Repariert APT, installiert Pakete, setzt Locale/PHP/Webserver-Drop-ins und prüft Dienste.
Ohne Option startet das interaktive Installationsmenü.
HELP
        exit 0 ;;
      *) fatal "Unbekannte Option: $1" ;;
    esac
    shift
  done
}

require_command() {
  command -v "$1" >/dev/null 2>&1 || { record_fail "Befehl fehlt: $1"; return 1; }
  record_pass "Befehl vorhanden: $1"
}

check_supported_debian_ubuntu() {
  [[ -r /etc/os-release ]] || { record_fail "/etc/os-release fehlt."; return 1; }
  local id version codename pretty arch init
  id="$(get_os_field ID | tr '[:upper:]' '[:lower:]')"
  version="$(get_os_field VERSION_ID)"
  codename="$(get_os_field VERSION_CODENAME)"
  pretty="$(get_os_field PRETTY_NAME)"
  arch="$(uname -m)"
  init="$(ps -p 1 -o comm= 2>/dev/null || true)"
  log "OS erkannt: ${pretty:-${id} ${version}} (codename=${codename:-unknown}, arch=${arch}, init=${init:-unknown})"
  case "${id}" in
    debian|ubuntu) record_pass "Unterstütztes System: ${pretty:-${id}}" ;;
    *) record_fail "Nicht unterstütztes System '${id}'. Dieser robuste Modus unterstützt Debian und Ubuntu."; return 1 ;;
  esac
  case "${arch}" in
    x86_64|amd64) record_pass "Architektur unterstützt: ${arch}" ;;
    aarch64|arm64) record_warn "ARM64 erkannt (${arch}). Nur fortsetzen, wenn passende EasyWI-Agent-Assets verfügbar sind." ;;
    *) record_fail "Nicht unterstützte Architektur: ${arch}"; return 1 ;;
  esac
  if [[ "${init}" != "systemd" ]] || ! command -v systemctl >/dev/null 2>&1; then
    record_fail "systemd fehlt oder läuft nicht als PID 1 (PID1=${init:-unknown})."
    return 1
  fi
  record_pass "systemd ist aktiv."
}

apt_retry_update() {
  local attempts="${1:-3}" n=1
  while (( n <= attempts )); do
    wait_for_apt_locks
    if env DEBIAN_FRONTEND=noninteractive apt-get update; then
      APT_UPDATED=1
      record_pass "apt-get update erfolgreich (Versuch ${n}/${attempts})."
      return 0
    fi
    record_warn "apt-get update fehlgeschlagen (Versuch ${n}/${attempts}); warte $((n*5)) Sekunden."
    sleep $((n*5))
    n=$((n+1))
  done
  record_fail "apt-get update nach ${attempts} Versuchen fehlgeschlagen."
  return 1
}

prepare_apt_robust() {
  step "Bereite APT robust vor."
  wait_for_apt_locks
  env DEBIAN_FRONTEND=noninteractive dpkg --configure -a || record_warn "dpkg --configure -a meldete Fehler."
  wait_for_apt_locks
  env DEBIAN_FRONTEND=noninteractive apt-get -f install -y || record_warn "apt-get -f install meldete Fehler."
  apt_retry_update 3 || return 1
  install_packages apt ca-certificates curl wget gnupg lsb-release software-properties-common
}

apt_package_available() {
  local pkg="$1" candidate=""
  if ! command -v apt-cache >/dev/null 2>&1; then
    return 1
  fi
  candidate="$(apt-cache policy "${pkg}" 2>/dev/null | awk -F': ' '/Candidate:/{print $2; exit}')"
  [[ -n "${candidate}" && "${candidate}" != "(none)" ]] && return 0
  apt-cache show "${pkg}" >/dev/null 2>&1
}

php_version_ge() {
  local left="$1" right="$2"
  awk -v l="${left}" -v r="${right}" 'BEGIN {
    split(l, a, "."); split(r, b, ".");
    for (i = 1; i <= 3; i++) {
      ai = a[i] + 0; bi = b[i] + 0;
      if (ai > bi) exit 0;
      if (ai < bi) exit 1;
    }
    exit 0;
  }'
}

native_php_version_for_os() {
  local id codename version
  id="$(get_os_field ID | tr '[:upper:]' '[:lower:]')"
  codename="$(get_os_field VERSION_CODENAME | tr '[:upper:]' '[:lower:]')"
  version="$(get_os_field VERSION_ID)"
  case "${id}:${codename}" in
    ubuntu:resolute) printf '8.5'; return ;;
    ubuntu:noble)    printf '8.3'; return ;;
    ubuntu:jammy)    printf '8.1'; return ;;
    ubuntu:focal)    printf '7.4'; return ;;
    debian:trixie)   printf '8.4'; return ;;
    debian:bookworm) printf '8.2'; return ;;
    debian:bullseye) printf '7.4'; return ;;
  esac
  case "${id}:${version}" in
    ubuntu:26.04) printf '8.5'; return ;;
    ubuntu:24.04) printf '8.3'; return ;;
    ubuntu:22.04) printf '8.1'; return ;;
    ubuntu:20.04) printf '7.4'; return ;;
    debian:13)    printf '8.4'; return ;;
    debian:12)    printf '8.2'; return ;;
    debian:11)    printf '7.4'; return ;;
  esac
  return 1
}

normalize_php_minor_version() {
  local version="$1"
  if [[ "${version}" =~ ^([0-9]+\.[0-9]+) ]]; then
    printf '%s' "${BASH_REMATCH[1]}"
  else
    printf '%s' "${version}"
  fi
}

php_version_too_old_message() {
  local version="$1"
  printf 'PHP %s ist zu alt. EasyWI Panel benötigt mindestens PHP %s.' "${version}" "${MIN_PANEL_PHP_VERSION}"
}

ensure_panel_php_version_supported() {
  local version
  version="$(normalize_php_minor_version "${1:-}")"
  [[ -n "${version}" ]] || fatal "PHP-Zielversion konnte nicht ermittelt werden. EasyWI Panel benötigt mindestens PHP ${MIN_PANEL_PHP_VERSION}."
  if ! php_version_ge "${version}" "${MIN_PANEL_PHP_VERSION}"; then
    fatal "$(php_version_too_old_message "${version}")"
  fi
}

resolve_project_php_version() {
  local repo_root composer_php="" composer_min="" native="" resolved="${DEFAULT_PHP_VERSION}"
  repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
  if [[ -r "${repo_root}/core/composer.json" && -z "${EASYWI_TEST_COMPOSER_PHP_REQUIRE:-}" ]]; then
    composer_php="$(awk '
      /"require"[[:space:]]*:/ { in_require=1 }
      in_require && /"php"[[:space:]]*:/ {
        line=$0
        sub(/.*"php"[[:space:]]*:[[:space:]]*"/, "", line)
        sub(/".*/, "", line)
        print line
        exit
      }
      in_require && /^[[:space:]]*}/ { in_require=0 }
    ' "${repo_root}/core/composer.json" 2>/dev/null || true)"
  else
    composer_php="${EASYWI_TEST_COMPOSER_PHP_REQUIRE:-}"
  fi
  if [[ "${composer_php}" =~ \>=([0-9]+\.[0-9]+) ]]; then
    composer_min="${BASH_REMATCH[1]}"
    resolved="${composer_min}"
  fi
  native="$(native_php_version_for_os 2>/dev/null || true)"
  if [[ -n "${native}" ]]; then
    if [[ -z "${composer_min}" ]] || php_version_ge "${native}" "${composer_min}"; then
      resolved="${native}"
    fi
  fi
  ensure_panel_php_version_supported "${resolved}"
  printf '%s' "${resolved}"
}

php_package_for_module() {
  local php_version="$1" module="$2"
  case "${module}" in
    curl|mbstring|intl|xml|zip|gd|bcmath|readline|mysql|pgsql|sqlite3) printf 'php%s-%s' "${php_version}" "${module}" ;;
    pdo_mysql) printf 'php%s-mysql' "${php_version}" ;;
    pdo_pgsql) printf 'php%s-pgsql' "${php_version}" ;;
    pdo_sqlite) printf 'php%s-sqlite3' "${php_version}" ;;
    *) return 1 ;;
  esac
}

resolve_apt_php_packages() {
  local php_version="$1" webserver="${2:-nginx}" critical=() optional=() installable=() missing_critical=() missing_optional=()
  local pkg module web_pkg

  ensure_panel_php_version_supported "${php_version}"
  critical=(cli common mysql pgsql sqlite3 curl mbstring intl xml zip gd bcmath readline)
  optional=(redis)

  if [[ "${webserver}" == "apache" ]]; then
    web_pkg="libapache2-mod-php${php_version}"
  else
    web_pkg="php${php_version}-fpm"
  fi

  for module in "${critical[@]}"; do
    pkg="php${php_version}-${module}"
    if apt_package_available "${pkg}"; then
      installable+=("${pkg}")
    else
      missing_critical+=("${pkg}")
    fi
  done

  if apt_package_available "${web_pkg}"; then
    installable+=("${web_pkg}")
  else
    missing_critical+=("${web_pkg}")
  fi

  for module in "${optional[@]}"; do
    pkg="php${php_version}-${module}"
    if apt_package_available "${pkg}"; then
      installable+=("${pkg}")
    else
      missing_optional+=("${pkg}")
    fi
  done

  log "PHP-Pakete installierbar: ${installable[*]:-(keine)}"
  log "PHP-Pakete optional nicht verfügbar: ${missing_optional[*]:-(keine)}"
  log "PHP-Pakete kritisch nicht verfügbar: ${missing_critical[*]:-(keine)}"
  if ((${#missing_optional[@]})); then
    record_warn "Optionale PHP-Pakete nicht verfügbar: ${missing_optional[*]}"
  fi
  if ((${#missing_critical[@]})); then
    fatal "Kritische versionierte PHP-${php_version}-Pakete nicht verfügbar: ${missing_critical[*]}. Generische PHP-Pakete werden nicht verwendet, damit das Panel nicht auf PHP <${MIN_PANEL_PHP_VERSION} zurückfällt."
  fi

  printf '%s\n' "${installable[@]}" | awk 'NF && !seen[$0]++'
}

install_required_packages_robust() {
  local php_version webserver php_packages=()
  php_version="$(resolve_project_php_version)"
  EASYWI_RESOLVED_PHP_VERSION="${php_version}"
  if systemctl list-unit-files apache2.service >/dev/null 2>&1 || [[ -d /etc/apache2 && ! -d /etc/nginx ]]; then webserver="apache"; else webserver="nginx"; fi
  mapfile -t php_packages < <(resolve_apt_php_packages "${php_version}" "${webserver}")
  log "Projekt-/Ziel-PHP-Version: ${php_version} (composer-Mindestversion akzeptiert neuere native OS-PHP-Versionen; Fallback ${DEFAULT_PHP_VERSION}); Webserver-Profil: ${webserver}"
  log "Basispakete: ${EASYWI_BASE_PACKAGES[*]}"
  log "PHP-Pakete final: ${php_packages[*]}"
  install_packages apt "${EASYWI_BASE_PACKAGES[@]}" "${php_packages[@]}"
  verify_php_runtime_versions "${php_version}"
  verify_opcache "${php_version}"
}


php_module_present_in_list() {
  local module="$1" modules="$2" normalized
  normalized="$(printf '%s\n' "${modules}" | tr '[:upper:]' '[:lower:]')"
  case "${module,,}" in
    opcache)   printf '%s\n' "${normalized}" | grep -Eq '^(opcache|zend opcache)$' ;;
    simplexml) printf '%s\n' "${normalized}" | grep -Eq '^(simplexml)$' ;;
    *)         printf '%s\n' "${normalized}" | grep -Fxq "${module,,}" ;;
  esac
}

opcache_extension_loaded() {
  php -r 'exit(extension_loaded("Zend OPcache") || extension_loaded("opcache") ? 0 : 1);' >/dev/null 2>&1 \
    || php -m 2>/dev/null | grep -Ei '^(Zend )?OPcache$' >/dev/null
}

apt_package_has_candidate() {
  local pkg="$1" candidate=""
  if ! command -v apt-cache >/dev/null 2>&1; then
    return 1
  fi
  candidate="$(apt-cache policy "${pkg}" 2>/dev/null | awk -F': ' '/Candidate:/{print $2; exit}')"
  [[ -n "${candidate}" && "${candidate}" != "(none)" ]]
}

install_opcache_package_if_available() {
  local php_version="$1" pkg
  for pkg in "php${php_version}-opcache" php-opcache; do
    if apt_package_has_candidate "${pkg}"; then
      record_warn "OPcache fehlt; installiere verfügbares Paket ${pkg}."
      install_packages apt "${pkg}"
      return 0
    fi
  done
  return 1
}

verify_opcache() {
  local php_version="${1:-$(resolve_project_php_version)}"

  if opcache_extension_loaded; then
    record_pass "OPcache ist als PHP-Feature/Modul geladen."
  else
    if install_opcache_package_if_available "${php_version}" && opcache_extension_loaded; then
      record_pass "OPcache ist nach Installation des separaten OPcache-Pakets geladen."
    else
      record_fail "OPcache fehlt, aber kein separates OPcache-Paket ist für PHP ${php_version} verfügbar."
      return 0
    fi
  fi

  if php -i 2>/dev/null | grep -iq 'opcache.enable'; then
    record_pass "OPcache-Konfiguration ist in php -i sichtbar."
  else
    record_warn "opcache.enable ist in php -i nicht sichtbar."
  fi
}

log_php_state() {
  local phase="$1" php_path=""
  log "PHP-Diagnose (${phase}): php -v"
  php -v >>"${LOG_FILE}" 2>&1 || true
  php_path="$(command -v php 2>/dev/null || true)"
  log "PHP-Diagnose (${phase}): readlink -f ${php_path:-php nicht gefunden}"
  if [[ -n "${php_path}" ]]; then readlink -f "${php_path}" >>"${LOG_FILE}" 2>&1 || true; fi
  log "PHP-Diagnose (${phase}): update-alternatives --display php"
  update-alternatives --display php >>"${LOG_FILE}" 2>&1 || true
  log "PHP-Diagnose (${phase}): aktive php*-fpm Services"
  systemctl list-units --type=service --state=active 'php*-fpm.service' --no-pager >>"${LOG_FILE}" 2>&1 || true
}

active_php_cli_minor_version() {
  php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true
}

ensure_active_php_cli_supported() {
  local cli_version
  cli_version="$(active_php_cli_minor_version)"
  [[ -n "${cli_version}" ]] || fatal "Aktive PHP-CLI-Version konnte nicht geprüft werden."
  if ! php_version_ge "${cli_version}" "${MIN_PANEL_PHP_VERSION}"; then
    fatal "$(php_version_too_old_message "${cli_version}")"
  fi
}

set_php_alternatives_for_target() {
  local php_version="$1" name target_path
  ensure_panel_php_version_supported "${php_version}"
  [[ -x "/usr/bin/php${php_version}" ]] || fatal "/usr/bin/php${php_version} fehlt nach der Installation; PHP-${php_version}-CLI kann nicht aktiviert werden."
  if command -v update-alternatives >/dev/null 2>&1; then
    for name in php phar phar.phar phpize php-config; do
      target_path="/usr/bin/${name}${php_version}"
      [[ "${name}" == "php" ]] && target_path="/usr/bin/php${php_version}"
      if [[ -x "${target_path}" ]]; then
        update-alternatives --set "${name}" "${target_path}" >/dev/null 2>&1 \
          || record_warn "update-alternatives konnte ${name} nicht auf ${target_path} setzen."
      fi
    done
    update-alternatives --display php >>"${LOG_FILE}" 2>&1 || true
  fi
  local active_version
  active_version="$(php -r 'echo PHP_VERSION;' 2>/dev/null || true)"
  log "Aktive PHP-CLI-Version nach update-alternatives: ${active_version:-unbekannt}"
  ensure_active_php_cli_supported
  if [[ "$(normalize_php_minor_version "${active_version}")" != "${php_version}" ]]; then
    fatal "Aktive PHP-CLI-Version ist ${active_version:-unbekannt}, erwartet PHP ${php_version}."
  fi
  record_pass "update-alternatives nutzt /usr/bin/php${php_version}."
}

disable_legacy_panel_fpm_services() {
  local target_version="$1" svc version
  for version in 8.3 8.2 8.1 8.0 7.4; do
    [[ "${version}" == "${target_version}" ]] && continue
    svc="php${version}-fpm.service"
    if systemctl list-unit-files "${svc}" >/dev/null 2>&1; then
      systemctl disable --now "${svc}" >/dev/null 2>&1 || record_warn "${svc} konnte nicht deaktiviert werden."
    fi
  done
}

ensure_target_fpm_service() {
  local php_version="$1"
  local svc="php${php_version}-fpm.service"
  ensure_panel_php_version_supported "${php_version}"
  if ! systemctl list-unit-files "${svc}" >/dev/null 2>&1; then
    fatal "${svc} fehlt; PHP-FPM der Zielversion ist nicht installiert."
  fi
  systemctl enable --now "${svc}" >/dev/null 2>&1 || fatal "${svc} konnte nicht aktiviert/gestartet werden."
  disable_legacy_panel_fpm_services "${php_version}"
  record_pass "PHP-FPM-Service aktiv: ${svc}"
}

composer_platform_check_status() {
  local working_dir="${1:-}" tmp_log status=0
  if ! command -v composer >/dev/null 2>&1; then
    record_warn "Composer ist nicht installiert; composer check-platform-reqs übersprungen."
    return 0
  fi
  tmp_log="$(mktemp -t easywi-composer-platform.XXXXXX.log)"
  trap - ERR
  set +e
  if [[ -n "${working_dir}" && -f "${working_dir}/composer.json" ]]; then
    run_composer check-platform-reqs --working-dir "${working_dir}" >"${tmp_log}" 2>&1
    status=$?
  else
    run_composer diagnose >"${tmp_log}" 2>&1
    status=$?
  fi
  set -e
  trap 'easywi_err_trap "${LINENO}" "${BASH_COMMAND}"' ERR
  if [[ -s "${tmp_log}" ]]; then
    {
      printf '%s %s Composer platform/diagnose output (Exit-Code %s):\n' "$(date -Is 2>/dev/null || date)" "${INSTALLER_NAME}" "${status}"
      cat "${tmp_log}"
    } >>"${LOG_FILE}" 2>/dev/null || true
  fi
  rm -f "${tmp_log}"
  if [[ "${status}" -eq 0 ]]; then
    record_pass "Composer-Plattformanforderungen geprüft."
  else
    ensure_active_php_cli_supported
    record_fail "Composer-Plattformanforderungen fehlgeschlagen; Details siehe ${LOG_FILE}."
    return 1
  fi
}

verify_php_runtime_versions() {
  local php_version="$1" cli_version="" fpm_bin
  ensure_panel_php_version_supported "${php_version}"
  fpm_bin="php-fpm${php_version}"
  log_php_state "Prüfung"
  set_php_alternatives_for_target "${php_version}"
  cli_version="$(active_php_cli_minor_version)"
  if [[ "${cli_version}" != "${php_version}" ]]; then
    fatal "Aktive PHP-CLI-Version ist ${cli_version:-unbekannt}, erwartet PHP ${php_version}."
  fi
  if command -v "${fpm_bin}" >/dev/null 2>&1; then
    "${fpm_bin}" -v >>"${LOG_FILE}" 2>&1 || true
  else
    record_warn "${fpm_bin} nicht im PATH gefunden; PHP-FPM-Version kann nicht direkt geprüft werden."
  fi
  record_pass "CLI-PHP nutzt Zielversion ${php_version}."
}

check_php_modules() {
  local missing=() module modules
  require_command php || return 1
  modules="$(php -m 2>/dev/null | tr '[:upper:]' '[:lower:]')"
  for module in "${EASYWI_CRITICAL_PHP_MODULES[@]}"; do
    if php_module_present_in_list "${module}" "${modules}"; then
      record_pass "PHP-Modul vorhanden: ${module}"
    else
      missing+=("${module}")
    fi
  done
  if ((${#missing[@]})); then
    record_fail "Kritische PHP-Module fehlen: ${missing[*]}"
    return 1
  fi
  php -i | awk -F'=> ' '/memory_limit|post_max_size|upload_max_filesize|default_charset|date.timezone|disable_functions|open_basedir|realpath_cache_size|realpath_cache_ttl|opcache.enable/{print}' >>"${LOG_FILE}" 2>/dev/null || true
}

backup_file_once() {
  local file="$1"
  [[ -f "${file}" ]] || return 0
  cp -a "${file}" "${file}.bak.$(date +%Y%m%d%H%M%S)"
}

set_ini_value() {
  local file="$1" key="$2" value="$3"
  [[ -f "${file}" ]] || return 0
  if grep -Eq "^[;[:space:]]*${key}[[:space:]]*=" "${file}"; then
    sed -i -E "s#^[;[:space:]]*${key}[[:space:]]*=.*#${key} = ${value}#" "${file}"
  else
    printf '\n%s = %s\n' "${key}" "${value}" >>"${file}"
  fi
}

configure_locale() {
  step "Konfiguriere UTF-8-Locale."
  install_packages apt locales
  backup_file_once /etc/locale.gen
  sed -i -E 's/^# ?(en_US.UTF-8 UTF-8)/\1/; s/^# ?(de_DE.UTF-8 UTF-8)/\1/' /etc/locale.gen
  locale-gen en_US.UTF-8 de_DE.UTF-8 C.UTF-8 >/dev/null 2>&1 || locale-gen >/dev/null 2>&1 || record_warn "locale-gen meldete Fehler."
  backup_file_once /etc/default/locale
  cat >/etc/default/locale <<'LOCALE'
LANG=C.UTF-8
LC_ALL=C.UTF-8
LANGUAGE=en_US:en
LOCALE
  update-locale LANG=C.UTF-8 LC_ALL=C.UTF-8 >/dev/null 2>&1 || true
  locale >>"${LOG_FILE}" 2>&1 || true
  locale -a >>"${LOG_FILE}" 2>&1 || true
  if locale | grep -E '^(LANG|LC_ALL)=' | grep -qi 'utf-8'; then record_pass "UTF-8-Locale aktiv/konfiguriert."; else record_warn "Aktuelle Shell-Locale ist nicht UTF-8; neue Sessions nutzen /etc/default/locale."; fi
}

configure_php() {
  local php_version="${EASYWI_RESOLVED_PHP_VERSION:-$(resolve_project_php_version)}"
  step "Konfiguriere PHP ${php_version} CLI/FPM/Apache sinnvoll."
  local ini key value disabled dangerous pair ini_candidates=() configured_count=0

  shopt -s nullglob
  for ini in /etc/php/${php_version}/cli/php.ini /etc/php/${php_version}/fpm/php.ini /etc/php/${php_version}/apache2/php.ini; do
    [[ -f "${ini}" ]] && ini_candidates+=("${ini}")
  done
  if ((${#ini_candidates[@]} == 0)); then
    ini_candidates=(/etc/php/*/cli/php.ini /etc/php/*/fpm/php.ini /etc/php/*/apache2/php.ini)
    record_warn "Keine versionierten PHP-${php_version}-INI-Dateien gefunden; nutze vorhandene /etc/php/*/ Profile."
  fi
  for ini in "${ini_candidates[@]}"; do
    [[ -f "${ini}" ]] || continue
    configured_count=$((configured_count + 1))
    backup_file_once "${ini}"
    for pair in "${EASYWI_PHP_SETTINGS[@]}"; do
      key="${pair%%=*}"; value="${pair#*=}"
      set_ini_value "${ini}" "${key}" "${value}"
    done
    if grep -Eq '^[[:space:]]*open_basedir[[:space:]]*=' "${ini}"; then
      record_warn "open_basedir ist in ${ini} gesetzt; Filemanager/Worker können dadurch blockiert werden."
    fi
    disabled="$(awk -F= '/^[[:space:]]*disable_functions[[:space:]]*=/{print $2}' "${ini}" | tail -n1 | tr -d ' ')"
    for dangerous in proc_open shell_exec exec passthru system posix_kill pcntl_fork pcntl_signal pcntl_waitpid; do
      [[ ",${disabled}," == *",${dangerous},"* ]] && record_warn "${dangerous} ist in disable_functions (${ini}) deaktiviert; Agent/Worker-Funktionen können ausfallen."
    done
  done
  shopt -u nullglob
  if ((configured_count == 0)); then
    record_warn "Keine PHP-INI-Dateien für PHP ${php_version} gefunden."
  fi

  ensure_target_fpm_service "${php_version}"
  systemctl restart "php${php_version}-fpm.service" >/dev/null 2>&1 || record_warn "php${php_version}-fpm.service konnte nicht neu gestartet werden."
  if [[ "$(detect_webserver_profile)" != "nginx" ]]; then
    systemctl reload apache2.service >/dev/null 2>&1 || systemctl restart apache2.service >/dev/null 2>&1 || true
  fi
  php -i | awk -F'=> ' '/memory_limit|post_max_size|upload_max_filesize|default_charset|date.timezone|expose_php|realpath_cache|opcache.enable|open_basedir|disable_functions/{print}' >>"${LOG_FILE}" 2>/dev/null || true
  verify_php_runtime_versions "${php_version}"
  verify_opcache "${php_version}"
  check_php_modules || true
}

replace_php_fpm_socket_references() {
  local php_version="$1"
  local target_socket="/run/php/php${php_version}-fpm.sock" file
  ensure_panel_php_version_supported "${php_version}"
  if [[ -d /etc/nginx ]]; then
    while IFS= read -r -d '' file; do
      backup_file_once "${file}"
      sed -i -E "s#/run/php/php[0-9]+\.[0-9]+-fpm\.sock#${target_socket}#g; s#/run/php-fpm/(www|php-fpm)\.sock#${target_socket}#g" "${file}"
    done < <(find /etc/nginx -type f \( -name '*.conf' -o -name 'default' -o -name '*.vhost' \) -print0 2>/dev/null)
  fi
  if [[ -d /etc/apache2 ]]; then
    while IFS= read -r -d '' file; do
      backup_file_once "${file}"
      sed -i -E "s#/run/php/php[0-9]+\.[0-9]+-fpm\.sock#${target_socket}#g; s#/run/php-fpm/(www|php-fpm)\.sock#${target_socket}#g" "${file}"
    done < <(find /etc/apache2 -type f \( -name '*.conf' -o -name '*.vhost' \) -print0 2>/dev/null)
  fi
  record_pass "Webserver-FPM-Socket zeigt auf ${target_socket}."
}

current_webserver_fpm_sockets() {
  { find /etc/nginx /etc/apache2 -type f \( -name '*.conf' -o -name 'default' -o -name '*.vhost' \) -print0 2>/dev/null \
      | xargs -0 grep -Eho '/run/(php/php[0-9]+\.[0-9]+-fpm|php-fpm/(www|php-fpm))\.sock' 2>/dev/null || true; } \
    | awk 'NF && !seen[$0]++'
}

active_fpm_php_versions() {
  systemctl list-units --type=service --state=active 'php*-fpm.service' --no-legend --no-pager 2>/dev/null \
    | awk '{print $1}' | sed -n -E 's/^php([0-9]+\.[0-9]+)-fpm\.service$/\1/p' | awk 'NF && !seen[$0]++'
}

configure_webserver() {
  local php_version="${EASYWI_RESOLVED_PHP_VERSION:-$(resolve_project_php_version)}"
  step "Konfiguriere Webserver-Limits/Streaming-Drop-ins."
  replace_php_fpm_socket_references "${php_version}"
  if [[ -d /etc/nginx ]]; then
    local nginx_conf="/etc/nginx/conf.d/easywi-installer-limits.conf"
    [[ -f "${nginx_conf}" ]] && backup_file_once "${nginx_conf}"
    cat >"${nginx_conf}" <<'NGINX'
# Managed by easywi-installer. Global safety defaults for uploads, PHP-FPM, WebSocket and SSE/streaming.
client_max_body_size 128m;
fastcgi_read_timeout 120s;
fastcgi_send_timeout 120s;
fastcgi_connect_timeout 60s;
fastcgi_buffering off;
proxy_http_version 1.1;
proxy_set_header Upgrade $http_upgrade;
proxy_set_header Connection "upgrade";
proxy_read_timeout 3600s;
proxy_send_timeout 3600s;
proxy_buffering off;
add_header X-Accel-Buffering no always;
NGINX
    if ! nginx -t || ! systemctl reload nginx.service; then
      record_warn "Nginx-Konfiguration konnte nicht neu geladen werden."
    fi
  fi
  if [[ -d /etc/apache2 ]]; then
    a2enmod rewrite headers proxy proxy_http proxy_wstunnel ssl >/dev/null 2>&1 || record_warn "Apache-Module konnten nicht vollständig aktiviert werden."
    local apache_conf="/etc/apache2/conf-available/easywi-installer-limits.conf"
    [[ -f "${apache_conf}" ]] && backup_file_once "${apache_conf}"
    cat >"${apache_conf}" <<'APACHE'
# Managed by easywi-installer.
Timeout 120
ProxyTimeout 3600
<Directory /var/www/easywi/core/public>
    AllowOverride All
    Require all granted
</Directory>
APACHE
    a2enconf easywi-installer-limits >/dev/null 2>&1 || true
    if ! apache2ctl configtest || ! systemctl reload apache2.service; then
      record_warn "Apache-Konfiguration konnte nicht neu geladen werden."
    fi
  fi
}

detect_web_user() { id -u www-data >/dev/null 2>&1 && echo www-data || echo root; }
detect_agent_user() { id -u easywi-agent >/dev/null 2>&1 && echo easywi-agent || echo root; }

detect_webserver_profile() {
  if systemctl is-active --quiet nginx 2>/dev/null || [[ -d /etc/nginx ]]; then
    echo nginx
  elif systemctl is-active --quiet apache2 2>/dev/null || [[ -d /etc/apache2 ]]; then
    echo apache
  else
    echo nginx
  fi
}

diagnose_webserver_versions_and_status() {
  local profile
  profile="$(detect_webserver_profile)"
  if command -v nginx >/dev/null 2>&1; then
    nginx -v 2>&1 || true
  else
    echo "Nginx: not installed/not available."
  fi
  if [[ "${profile}" == "nginx" ]]; then
    echo "Apache: skipped because nginx profile active."
    systemctl status 'php*-fpm.service' nginx --no-pager -l 2>&1 || true
  else
    if command -v apache2 >/dev/null 2>&1; then
      apache2 -v 2>&1 || true
    else
      echo "Apache: not installed/not available."
    fi
    systemctl status 'php*-fpm.service' apache2 --no-pager -l 2>&1 || true
  fi
}

data_area_paths() {
  printf '%s\n' "${EASYWI_DATA_PATH:-}" "${EASYWI_INSTANCE_BASE_DIR:-/home/easywi/instances}" "${EASYWI_SFTP_BASE_DIR:-/srv/easywi/sftp}" | awk 'NF && !seen[$0]++'
}

symfony_runtime_path() {
  printf '%s\n' "${EASYWI_SYMFONY_VAR_PATH:-/var/www/easywi/core/var}"
}

user_find_listing() {
  local user="$1" path="${2:-}"
  if [[ -z "${path}" ]]; then
    record_warn "Dateilisting-Test für ${user} übersprungen: Pfad ist leer."
    return 0
  fi
  if [[ ! -d "${path}" ]]; then
    record_warn "Dateilisting-Test für ${user} übersprungen: Pfad ist kein Verzeichnis: ${path}"
    return 0
  fi
  if [[ "${user}" == "root" ]]; then
    (cd / && find "${path}" -maxdepth 2 -printf '%p\n')
  elif command -v runuser >/dev/null 2>&1; then
    # shellcheck disable=SC2016
    runuser -u "${user}" -- sh -c 'cd / && find "$1" -maxdepth 2 -printf "%p\n"' sh "${path}"
  else
    # shellcheck disable=SC2016
    sudo -u "${user}" sh -c 'cd / && find "$1" -maxdepth 2 -printf "%p\n"' sh "${path}"
  fi
}

check_symfony_runtime_area() {
  local path count
  path="$(symfony_runtime_path)"
  [[ -e "${path}" ]] || return 0
  namei -l "${path}" >>"${LOG_FILE}" 2>&1 || record_warn "namei konnte Symfony-runtime ${path} nicht prüfen."
  count="$(find "${path}" -xdev -printf . 2>/dev/null | wc -c | tr -d ' ')"
  if [[ "${count}" =~ ^[0-9]+$ && "${count}" -gt 10000 ]]; then
    record_warn "Symfony cache/log/runtime unter ${path} enthält sehr viele Einträge (${count}); Cache/Logs prüfen oder bereinigen."
  else
    record_pass "Symfony cache/log/runtime-Pfad geprüft: ${path}"
  fi
}

localhost_hosts_entry_configured() {
  local address="$1"
  awk -v address="${address}" '$1 == address { for (i=2; i<=NF; i++) if ($i == "localhost") found=1 } END { exit found ? 0 : 1 }' /etc/hosts 2>/dev/null
}

check_localhost_hosts_entries() {
  local ok=1
  if localhost_hosts_entry_configured "127.0.0.1"; then
    record_pass "IPv4-localhost in /etc/hosts vorhanden."
  else
    record_warn "IPv4-localhost fehlt in /etc/hosts (erwartet: 127.0.0.1 localhost)."
    ok=0
  fi
  if localhost_hosts_entry_configured "::1"; then
    record_pass "IPv6-localhost in /etc/hosts vorhanden."
  else
    record_warn "IPv6-localhost fehlt in /etc/hosts (erwartet: ::1 localhost)."
    ok=0
  fi
  getent ahosts localhost >>"${LOG_FILE}" 2>&1 || record_warn "getent ahosts localhost liefert keine ergänzende localhost-Auflösung."
  if ((ok == 1)); then
    record_pass "/etc/hosts enthält IPv4- und IPv6-localhost."
  fi
}

fix_localhost_hosts_entries() {
  local changed=0
  if ! localhost_hosts_entry_configured "127.0.0.1"; then
    ((changed == 0)) && backup_file_once /etc/hosts
    printf '\n127.0.0.1 localhost\n' >>/etc/hosts
    changed=1
    record_pass "IPv4-localhost sicher in /etc/hosts ergänzt."
  fi
  if ! localhost_hosts_entry_configured "::1"; then
    ((changed == 0)) && backup_file_once /etc/hosts
    printf '\n::1 localhost ip6-localhost ip6-loopback\n' >>/etc/hosts
    changed=1
    record_pass "IPv6-localhost sicher in /etc/hosts ergänzt."
  fi
}

check_data_area() {
  step "Prüfe Datenbereich/Filemanager-Voraussetzungen."
  local path webuser agentuser count testdir
  webuser="$(detect_web_user)"; agentuser="$(detect_agent_user)"
  for path in $(data_area_paths); do
    if [[ ! -e "${path}" ]]; then record_warn "Datenpfad existiert nicht: ${path}"; continue; fi
    namei -l "${path}" >>"${LOG_FILE}" 2>&1 || record_warn "namei konnte ${path} nicht prüfen."
    getfacl -p "${path}" >>"${LOG_FILE}" 2>&1 || true
    if ! user_find_listing "${webuser}" "${path}" 2>>"${LOG_FILE}" | cat >/tmp/easywi-webuser-listing.$$; then
      record_warn "${webuser} kann ${path} nicht vollständig listen."
    fi
    if ! user_find_listing "${agentuser}" "${path}" 2>>"${LOG_FILE}" | cat >/tmp/easywi-agentuser-listing.$$; then
      record_warn "${agentuser} kann ${path} nicht vollständig listen."
    fi
    if ! find "${path}" -print 2>>"${LOG_FILE}" | iconv -f UTF-8 -t UTF-8 >/dev/null 2>>"${LOG_FILE}"; then
      record_warn "Ungültige UTF-8-Dateinamen unter ${path}; siehe ${LOG_FILE}. Reparatur z.B. mit convmv nach manueller Prüfung."
      find "${path}" -print 2>/dev/null | LC_ALL=C awk '{print}' | iconv -f UTF-8 -t UTF-8 >/dev/null 2>>"${LOG_FILE}" || true
    fi
    count="$(find "${path}" -xdev -printf . 2>/dev/null | wc -c | tr -d ' ')"
    if [[ "${count}" =~ ^[0-9]+$ && "${count}" -gt 10000 ]]; then
      record_warn "Sehr großes Listing unter ${path}: ${count} Einträge; Pagination/Limit empfohlen."
    fi
    if [[ -w "${path}" ]]; then
      testdir="${path}/.easywi-installer-test"
      mkdir -p "${testdir}" && touch "${testdir}/äöü-test.txt" "${testdir}/leer zeichen test.txt"
      # shellcheck disable=SC2016 # PHP code intentionally uses $argv and PHP variables.
      if php -r '$p=$argv[1]; $a=scandir($p); foreach($a as $n){ if(!mb_check_encoding($n,"UTF-8")){fwrite(STDERR,"invalid utf8: $n\n"); exit(2);} } json_encode($a, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);' "${testdir}"; then
        record_pass "JSON/UTF-8-Testdateien OK in ${testdir}"
      else
        record_warn "PHP-JSON/UTF-8-Test fehlgeschlagen in ${testdir}."
      fi
      rm -rf "${testdir}"
    fi
  done
  check_symfony_runtime_area
}

agent_conf_value() {
  local key="$1"
  awk -F= -v key="${key}" 'tolower($1)==key {gsub(/^[ \t]+|[ \t]+$/, "", $2); print $2; exit}' /etc/easywi/agent.conf 2>/dev/null || true
}

extract_port_from_listen() {
  local listen="$1"
  [[ -n "${listen}" ]] || return 1
  printf '%s\n' "${listen##*:}" | awk '/^[0-9]+$/ {print; exit}'
}

detect_agent_health_port() {
  local listen port
  if [[ -n "${EASYWI_AGENT_HEALTH_PORT:-}" ]]; then
    printf '%s\n' "${EASYWI_AGENT_HEALTH_PORT}"
    return 0
  fi
  listen="$(agent_conf_value health_listen)"
  port="$(extract_port_from_listen "${listen}" || true)"
  if [[ -z "${port}" ]]; then
    listen="$(agent_conf_value service_listen)"
    port="$(extract_port_from_listen "${listen}" || true)"
  fi
  if [[ -n "${port}" ]]; then
    printf '%s\n' "${port}"
    return 0
  fi
  command -v ss >/dev/null 2>&1 || return 0
  ss -tulpn 2>/dev/null | awk '/easywi-agent/ { n=split($5,a,":"); if (a[n] ~ /^[0-9]+$/) { print a[n]; exit } }' || true
}

agent_process_or_port_active() {
  local port="$1"
  systemctl is-active --quiet easywi-agent 2>/dev/null && return 0
  pgrep -x easywi-agent >/dev/null 2>&1 && return 0
  command -v ss >/dev/null 2>&1 || return 1
  ss -tulpn 2>/dev/null | awk -v p=":${port}" '$5 ~ p"$" && /easywi-agent/ {found=1} END {exit found ? 0 : 1}'
}

agent_health_json_value() {
  local key="$1" file="$2"
  # shellcheck disable=SC2016 # PHP code intentionally uses $argv and PHP variables.
  php -r '$d=json_decode(file_get_contents($argv[2]), true); if (is_array($d) && array_key_exists($argv[1], $d)) { $v=$d[$argv[1]]; if (is_bool($v)) { echo $v ? "true" : "false"; } elseif (is_scalar($v)) { echo $v; } }' "${key}" "${file}" 2>/dev/null || true
}

check_agent_health() {
  local port http_url https_url response_file status root file_api curl_log
  port="$(detect_agent_health_port | head -n1)"
  if [[ -z "${port}" ]]; then
    record_warn "Agent-Healthcheck-Port konnte nicht erkannt werden (agent.conf/ss ohne easywi-agent-Listener)."
    return 0
  fi
  record_pass "Agent-Port erkannt: ${port}."
  http_url="http://127.0.0.1:${port}/health"
  https_url="https://127.0.0.1:${port}/health"
  response_file="$(mktemp)"
  curl_log="$(mktemp)"
  if curl -4 -fsS --max-time 5 "${http_url}" -o "${response_file}" 2>"${curl_log}"; then
    status="$(agent_health_json_value status "${response_file}")"
    if [[ "${status}" == "ok" ]]; then
      root="$(agent_health_json_value root "${response_file}")"
      file_api="$(agent_health_json_value file_api "${response_file}")"
      cat "${response_file}" >>"${LOG_FILE}" 2>/dev/null || true
      printf '\n' >>"${LOG_FILE}"
      record_pass "Agent-Healthcheck OK: ${http_url}"
      record_pass "Agent root: ${root:-unknown}, file_api=${file_api:-unknown}"
      rm -f "${response_file}" "${curl_log}"
      return 0
    fi
    cat "${response_file}" >>"${LOG_FILE}" 2>/dev/null || true
    printf '\n' >>"${LOG_FILE}"
    record_warn "Agent-Healthcheck auf ${http_url} antwortete, aber JSON status ist nicht ok (${status:-unbekannt})."
  else
    cat "${curl_log}" >>"${LOG_FILE}" 2>/dev/null || true
  fi
  if curl -4 -k -fsS --max-time 5 "${https_url}" -o "${response_file}" 2>"${curl_log}"; then
    status="$(agent_health_json_value status "${response_file}")"
    if [[ "${status}" == "ok" ]]; then
      root="$(agent_health_json_value root "${response_file}")"
      file_api="$(agent_health_json_value file_api "${response_file}")"
      cat "${response_file}" >>"${LOG_FILE}" 2>/dev/null || true
      printf '\n' >>"${LOG_FILE}"
      record_pass "Agent-Healthcheck OK: ${https_url}"
      record_pass "Agent root: ${root:-unknown}, file_api=${file_api:-unknown}"
      rm -f "${response_file}" "${curl_log}"
      return 0
    fi
  elif grep -qi 'wrong version number' "${curl_log}" 2>/dev/null; then
    record_warn "Optionaler HTTPS-Agent-Healthcheck auf ${https_url} spricht kein TLS (wrong version number); HTTP-Port erwartet."
  fi
  if agent_process_or_port_active "${port}"; then
    record_warn "Agent läuft und Port ${port} lauscht, aber /health antwortet nicht erfolgreich."
  else
    record_warn "Agent-Healthcheck auf 127.0.0.1:${port}/health nicht erfolgreich und kein aktiver Agent-Listener erkannt."
  fi
  rm -f "${response_file}" "${curl_log}"
}


check_services() {
  step "Prüfe Agent/Worker/systemd/Netzwerk."
  check_localhost_hosts_entries
  ss -tulpn >>"${LOG_FILE}" 2>&1 || true
  local svc
  for svc in easywi-agent easywi-messenger easywi-scheduler.timer easywi-console-relay nginx apache2; do
    if systemctl list-unit-files "${svc}" >/dev/null 2>&1 || systemctl list-unit-files "${svc}.service" >/dev/null 2>&1; then
      systemctl status "${svc}" --no-pager -l >>"${LOG_FILE}" 2>&1 || record_warn "Service nicht aktiv/fehlerhaft: ${svc}"
    fi
  done
  check_agent_health
  if command -v update-alternatives >/dev/null 2>&1; then update-alternatives --display iptables >>"${LOG_FILE}" 2>&1 || true; fi
  if command -v ufw >/dev/null 2>&1; then
    ufw status verbose >>"${LOG_FILE}" 2>&1 || true
  fi
  if command -v nft >/dev/null 2>&1; then
    nft list ruleset >>"${LOG_FILE}" 2>&1 || true
  fi
  record_warn "Externe Provider-/Hardware-Firewalls können lokal nicht automatisch geprüft werden; benötigte Ports für Panel/Agent/Gameserver beim Provider freigeben."
}

check_security_modules() {
  step "Prüfe AppArmor/SELinux."
  if command -v aa-status >/dev/null 2>&1; then
    aa-status >>"${LOG_FILE}" 2>&1 || record_warn "aa-status meldete Fehler."
  else
    record_warn "aa-status nicht verfügbar oder AppArmor nicht installiert."
  fi
  if command -v getenforce >/dev/null 2>&1; then
    getenforce >>"${LOG_FILE}" 2>&1 || true
  else
    echo "SELinux: not installed/not available." >>"${LOG_FILE}"
  fi
  dmesg 2>/dev/null | grep -i denied >>"${LOG_FILE}" 2>&1 || true
  journalctl -k -n 500 --no-pager 2>/dev/null | grep -i apparmor >>"${LOG_FILE}" 2>&1 || true
}

run_diagnostics() {
  step "Erstelle Diagnosebericht: ${DIAG_LOG_FILE}"
  mkdir -p "$(dirname "${DIAG_LOG_FILE}")"
  : >"${DIAG_LOG_FILE}"
  {
    echo "# EasyWI Installer Diagnostics $(date -Is)"
    echo "## OS"; cat /etc/os-release 2>/dev/null || true; uname -a; echo "arch=$(uname -m)"; systemctl --version 2>/dev/null || true
    echo "## Provider hints"; systemd-detect-virt 2>/dev/null || true; dmidecode -s system-manufacturer 2>/dev/null || true; dmidecode -s system-product-name 2>/dev/null || true
    echo "## Locale"; locale 2>&1 || true; locale -a 2>&1 || true
    echo "## PHP"; php -v 2>&1 || true; php -m 2>&1 || true; php -i 2>&1 | awk -F'=> ' '/memory_limit|post_max_size|upload_max_filesize|default_charset|date.timezone|disable_functions|open_basedir|realpath_cache|opcache.enable/{print}' || true
    echo "## Composer"; composer_diagnose_status
    echo "## Webserver"; diagnose_webserver_versions_and_status
    echo "## EasyWI services"; systemctl status easywi-agent easywi-messenger easywi-scheduler.timer easywi-console-relay --no-pager -l 2>&1 || true; journalctl -u easywi-agent -u easywi-messenger -n 100 --no-pager 2>&1 || true
    echo "## Agent health"; local agent_port; agent_port="$(detect_agent_health_port | head -n1)"; if [[ -n "${agent_port}" ]]; then echo "Agent-Port: ${agent_port}"; curl -4 -fsS --max-time 5 "http://127.0.0.1:${agent_port}/health" 2>&1 || true; else echo "Agent-Port: not detected"; fi
    echo "## Network"; getent ahosts localhost 2>&1 || true; ss -tulpn 2>&1 || true; ip addr 2>&1 || true; ip route 2>&1 || true
    echo "## Filesystems"; df -T 2>&1 || true; mount 2>&1 || true
    echo "## Firewall"; ufw status verbose 2>&1 || true; update-alternatives --display iptables 2>&1 || true; nft list ruleset 2>&1 || true
    echo "## AppArmor/SELinux"; aa-status 2>&1 || true; if command -v getenforce >/dev/null 2>&1; then getenforce 2>&1 || true; else echo "SELinux: not installed/not available."; fi; dmesg 2>/dev/null | grep -i denied || true; journalctl -k -n 500 --no-pager 2>/dev/null | grep -i apparmor || true
    echo "## Data areas"
    local path webuser agentuser
    webuser="$(detect_web_user)"; agentuser="$(detect_agent_user)"
    for path in $(data_area_paths); do
      echo "### ${path}"; namei -l "${path}" 2>&1 || true; getfacl -p "${path}" 2>&1 || true
      user_find_listing "${webuser}" "${path}" 2>&1 | head -200 || true
      user_find_listing "${agentuser}" "${path}" 2>&1 | head -200 || true
      # shellcheck disable=SC2016 # PHP code intentionally uses $argv and PHP variables.
      php -r '$p=$argv[1]; if(!is_dir($p)){exit(0);} $a=@scandir($p) ?: []; foreach($a as $n){ echo (mb_check_encoding($n,"UTF-8") ? "OK " : "BAD ").$n.PHP_EOL; } echo json_encode($a, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE).PHP_EOL;' "${path}" 2>&1 || true
    done
    echo "## Symfony runtime"
    path="$(symfony_runtime_path)"
    echo "### ${path}"; namei -l "${path}" 2>&1 || true; find "${path}" -maxdepth 2 -printf '%p\n' 2>&1 | head -200 || true
  } >>"${DIAG_LOG_FILE}"
  record_pass "Diagnosebericht erstellt: ${DIAG_LOG_FILE}"
}

report_php_panel_diagnostics() {
  local target_version="$1" cli_version="" fpm_versions="" sockets="" ok_state="FAIL"
  cli_version="$(active_php_cli_minor_version)"
  fpm_versions="$(active_fpm_php_versions | paste -sd, - 2>/dev/null || true)"
  sockets="$(current_webserver_fpm_sockets | paste -sd, - 2>/dev/null || true)"
  [[ -z "${fpm_versions}" ]] && fpm_versions="unbekannt/kein aktiver php*-fpm Service"
  [[ -z "${sockets}" ]] && sockets="unbekannt/kein FPM-Socket in Webserver-Konfiguration gefunden"
  if [[ -n "${cli_version}" ]] && php_version_ge "${cli_version}" "${MIN_PANEL_PHP_VERSION}"; then ok_state="PASS"; fi
  log "Ziel-PHP-Version: ${target_version}"
  log "Aktive CLI-PHP-Version: ${cli_version:-unbekannt}"
  log "Aktive FPM-PHP-Version: ${fpm_versions}"
  log "Nginx/Apache FPM-Socket: ${sockets}"
  log "Aktive PHP-Version >=${MIN_PANEL_PHP_VERSION}: ${ok_state}"
  if [[ -n "${cli_version}" ]] && ! php_version_ge "${cli_version}" "${MIN_PANEL_PHP_VERSION}"; then
    record_fail "$(php_version_too_old_message "${cli_version}") Reparatur: versionierte Pakete php${target_version}-cli php${target_version}-fpm installieren und update-alternatives --set php /usr/bin/php${target_version} ausführen."
  fi
}

run_check_mode() {
  check_supported_debian_ubuntu || true
  local php_version
  php_version="$(resolve_project_php_version)"
  EASYWI_RESOLVED_PHP_VERSION="${php_version}"
  log "Erwartete Ziel-PHP-Version aus /etc/os-release/composer.json: ${php_version}"
  report_php_panel_diagnostics "${php_version}"
  if [[ "$(get_os_field ID | tr '[:upper:]' '[:lower:]')" == "ubuntu" ]] && [[ "$(native_php_version_for_os 2>/dev/null || true)" == "${php_version}" ]]; then
    log "Nutze Ubuntu-PHP-Pakete (PHP ${php_version}); Ondrej/PHP-PPA wird übersprungen."
  fi
  for cmd in apt-get dpkg curl wget gpg lsb_release systemctl find file iconv php; do require_command "${cmd}" || true; done
  check_php_modules || true
  if locale | grep -qi 'utf-8'; then
    record_pass "Aktuelle Locale ist UTF-8."
  else
    record_warn "Aktuelle Locale ist nicht UTF-8; --fix setzt C.UTF-8."
  fi
  check_data_area || true
  check_services || true
  check_security_modules || true
  set +e
  trap - ERR
  print_summary
  exit $?
}

run_fix_mode() {
  check_supported_debian_ubuntu
  log_php_state "vor --fix"
  prepare_apt_robust
  EASYWI_RESOLVED_PHP_VERSION="$(resolve_project_php_version)"
  ensure_panel_php_version_supported "${EASYWI_RESOLVED_PHP_VERSION}"
  setup_php_repo apt debian "${EASYWI_RESOLVED_PHP_VERSION}"
  install_required_packages_robust
  configure_locale
  configure_php
  composer_platform_check_status "$(cd "$(dirname "${BASH_SOURCE[0]}")/../core" 2>/dev/null && pwd)" || true
  configure_webserver
  log_php_state "nach --fix"
  fix_localhost_hosts_entries || true
  check_data_area || true
  check_services || true
  check_security_modules || true
  run_diagnostics || true
  set +e
  trap - ERR
  print_summary
  exit $?
}

run_cli_mode() {
  case "${INSTALLER_MODE}" in
    check) run_check_mode ;;
    diagnose) check_supported_debian_ubuntu || true; run_diagnostics; set +e; trap - ERR; print_summary; exit $? ;;
    fix) run_fix_mode ;;
  esac
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

    1) Panel (Core) installieren (inkl. Musicbot Upload-Limits & Systemabhängigkeiten)
    2) Agent installieren
    3) Panel + Agent installieren (inkl. Musicbot Upload-Limits & Systemabhängigkeiten)
    4) Agent aktualisieren
    5) CPU Performance-Modus (Intel/AMD · Governor + GRUB)
    6) Panel-SSL nachträglich einrichten
    7) Musicbot Voraussetzungen reparieren/neu anwenden
    8) Beenden

MENU
  local choice; choice="$(menu_prompt "  Auswahl [1-8]: ")"
  case "${choice}" in
    1) run_panel_install    ;;
    2) run_agent_install    ;;
    3) run_both_install     ;;
    4) run_agent_update     ;;
    5) run_cpu_performance  ;;
    6) run_panel_ssl_setup  ;;
    7) run_musicbot_prerequisites_repair ;;
    *) log "Installation beendet."; exit 0 ;;
  esac
}

# ---------------------------------------------------------------------------
# Entrypoint
# ---------------------------------------------------------------------------

parse_cli_args "$@"
require_root
if [[ "${INSTALLER_MODE}" != "menu" ]]; then
  run_cli_mode
  exit $?
fi
print_banner
main_menu
