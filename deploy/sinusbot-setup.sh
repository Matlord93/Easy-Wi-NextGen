#!/usr/bin/env bash
# sinusbot-setup.sh
# Prepares a Linux server for Easy-Wi Sinusbot multi-instance hosting.
# Run once as root before the first customer instance is created.
#
# Usage:
#   sudo bash sinusbot-setup.sh
#   sudo bash sinusbot-setup.sh --install-dir /opt/sinusbot --instance-root /var/lib/sinusbot-instances

set -euo pipefail

INSTALL_DIR="/opt/sinusbot"
INSTANCE_ROOT="/var/lib/sinusbot-instances"
SERVICE_USER="sinusbot"
WEB_PORT_BASE="8087"

usage() {
    cat <<EOF
Usage: $0 [options]
Options:
  --install-dir DIR       Sinusbot binary directory  (default: $INSTALL_DIR)
  --instance-root DIR     Customer instance root dir  (default: $INSTANCE_ROOT)
  --service-user USER     Unix user for instances    (default: $SERVICE_USER)
  --port-base PORT        First web-UI port          (default: $WEB_PORT_BASE)
  -h, --help
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --install-dir)   INSTALL_DIR="$2";    shift 2 ;;
        --instance-root) INSTANCE_ROOT="$2";  shift 2 ;;
        --service-user)  SERVICE_USER="$2";   shift 2 ;;
        --port-base)     WEB_PORT_BASE="$2";  shift 2 ;;
        -h|--help)       usage; exit 0 ;;
        *) echo "Unknown option: $1"; usage; exit 1 ;;
    esac
done

require_root() {
    if [[ $EUID -ne 0 ]]; then
        echo "ERROR: This script must be run as root." >&2
        exit 1
    fi
}

log()  { echo "[setup] $*"; }
warn() { echo "[setup] WARN: $*" >&2; }
die()  { echo "[setup] ERROR: $*" >&2; exit 1; }

require_root

# -----------------------------------------------------------------------
# 1. Install required packages
# -----------------------------------------------------------------------
log "Installing required packages ..."
if command -v apt-get &>/dev/null; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    # TeamSpeak 3 client libraries needed by Sinusbot
    apt-get install -y -qq \
        libglib2.0-0 \
        libpulse0 \
        libssl-dev \
        libstdc++6 \
        ca-certificates \
        curl \
        tar \
        bzip2 \
        2>/dev/null || warn "Some packages could not be installed"
elif command -v yum &>/dev/null; then
    yum install -y -q \
        glib2 \
        pulseaudio-libs \
        openssl-devel \
        libstdc++ \
        ca-certificates \
        curl \
        tar \
        bzip2 \
        2>/dev/null || warn "Some packages could not be installed"
fi

# -----------------------------------------------------------------------
# 2. Create service user
# -----------------------------------------------------------------------
if ! id -u "$SERVICE_USER" &>/dev/null; then
    log "Creating system user '$SERVICE_USER' ..."
    useradd \
        --system \
        --no-create-home \
        --shell /usr/sbin/nologin \
        --home-dir "$INSTALL_DIR" \
        "$SERVICE_USER"
    log "User '$SERVICE_USER' created."
else
    log "User '$SERVICE_USER' already exists, skipping."
fi

# -----------------------------------------------------------------------
# 3. Create installation directory (base binary)
# -----------------------------------------------------------------------
if [[ ! -d "$INSTALL_DIR" ]]; then
    log "Creating install directory $INSTALL_DIR ..."
    mkdir -p "$INSTALL_DIR"
fi
chown -R "$SERVICE_USER:$SERVICE_USER" "$INSTALL_DIR"
chmod 0755 "$INSTALL_DIR"

# -----------------------------------------------------------------------
# 4. Create instance root directory
# -----------------------------------------------------------------------
log "Creating instance root $INSTANCE_ROOT ..."
mkdir -p "$INSTANCE_ROOT"
chown -R "$SERVICE_USER:$SERVICE_USER" "$INSTANCE_ROOT"
chmod 0750 "$INSTANCE_ROOT"

# -----------------------------------------------------------------------
# 5. Check for existing solo Sinusbot installation
# -----------------------------------------------------------------------
BINARY="$INSTALL_DIR/sinusbot"
if [[ -f "$BINARY" ]]; then
    log "Found existing Sinusbot binary at $BINARY."
    # Check if solo instance is using the base port
    if ss -tlnp 2>/dev/null | grep -q ":$WEB_PORT_BASE "; then
        warn "Port $WEB_PORT_BASE is already in use. The first customer instance will be allocated the next free port."
        warn "To avoid conflicts, consider setting --port-base to a different value (e.g. $((WEB_PORT_BASE + 10)))."
    fi
else
    log "Sinusbot binary not found at $BINARY."
    log "Install Sinusbot to $INSTALL_DIR before creating customer instances."
    log "  cd $INSTALL_DIR && curl -O <SINUSBOT_DOWNLOAD_URL> && tar xjf sinusbot.tar.bz2"
fi

# -----------------------------------------------------------------------
# 6. Print agent.conf snippet
# -----------------------------------------------------------------------
cat <<EOF

--------------------------------------------------------------------------
Add the following lines to your Easy-Wi agent configuration file
(typically /etc/easywi/agent.conf):

  sinusbot_install_dir   = $INSTALL_DIR
  sinusbot_instance_root = $INSTANCE_ROOT
  sinusbot_service_user  = $SERVICE_USER
  sinusbot_web_port_base = $WEB_PORT_BASE

Then restart the agent:
  systemctl restart easywi-agent

--------------------------------------------------------------------------
In the Easy-Wi Admin Panel, configure the Sinusbot node with:
  Install Path:    $INSTALL_DIR
  Instance Root:   $INSTANCE_ROOT
  Web Bind IP:     <your public IP, e.g. 185.248.141.106>
  Web Port Base:   $WEB_PORT_BASE

--------------------------------------------------------------------------
EOF

log "Setup complete."
