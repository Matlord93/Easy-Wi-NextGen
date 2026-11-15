#!/usr/bin/env bash
# Self-contained entry point shipped in normal core releases.
set -euo pipefail
if [[ $(id -u) != 0 || $# -lt 1 || $# -gt 6 ]]; then
  echo "Usage (root): $0 /var/www/easywi/core [https://panel.example.net] [panel-internal-origin] [agent-binary] [nginx-vhost] [php-binary]" >&2
  exit 1
fi
vm_panel_dir="${1%/}"
vm_public_origin="${2:-}"
vm_panel_origin="${3:-}"
vm_agent_binary="${4:-/usr/local/bin/easywi-agent}"
vm_vhost="${5:-}"
vm_php="${6:-$(command -v php8.5 || command -v php)}"
vm_helper_root="$(cd "$(dirname "$0")" && pwd)"
[[ -f "$vm_panel_dir/bin/console" && -x "$vm_agent_binary" ]] || { echo 'Panel or updated agent binary missing.' >&2; exit 1; }
command -v python3 >/dev/null
command -v systemctl >/dev/null
command -v nginx >/dev/null
command -v runuser >/dev/null
[[ -x "$vm_php" ]] || { echo 'A supported PHP CLI binary is required.' >&2; exit 1; }
vm_binary_help="$("$vm_agent_binary" --help 2>&1 || true)"
[[ "$vm_binary_help" == *vm-console-gateway* ]] || { echo 'Update the agent binary first; it must support --vm-console-gateway.' >&2; exit 1; }
if [[ -z "$vm_vhost" ]]; then
  for vm_candidate in /etc/nginx/sites-available/easywi.conf /etc/nginx/conf.d/easywi.conf; do
    if [[ -f "$vm_candidate" ]]; then vm_vhost="$vm_candidate"; break; fi
  done
fi
[[ -f "$vm_vhost" ]] || { echo 'Panel Nginx vhost missing; supply its path as the fifth argument.' >&2; exit 1; }
[[ ! -L "$vm_panel_dir/.env.local.php" ]] || { echo 'Rebuild a symlinked compiled environment through its secret manager.' >&2; exit 1; }
if [[ -f "$vm_panel_dir/.env.local.php" ]]; then
  command -v composer >/dev/null || { echo 'Composer is required to rebuild the existing compiled production environment.' >&2; exit 1; }
fi
vm_composer_command=(composer)
if [[ -f "$vm_panel_dir/.env.local.php" ]]; then
  vm_composer_binary="$(command -v composer)"
  # Standard Composer PHARs/scripts use the selected supported PHP version.
  # Existing shell wrappers retain their own documented interpreter choice.
  if [[ -f "$vm_composer_binary" ]] && "$vm_php" -r '$prefix = file_get_contents($argv[1], false, null, 0, 1024); exit(is_string($prefix) && str_contains($prefix, "<?php") ? 0 : 1);' "$vm_composer_binary"; then
    vm_composer_command=("$vm_php" "$vm_composer_binary")
  fi
fi
vm_web_user="${EASYWI_VM_CONSOLE_WEB_USER:-}"
if [[ -z "$vm_web_user" ]]; then
  vm_web_user="$(stat -c '%U' "$vm_panel_dir/var/cache" 2>/dev/null || stat -c '%U' "$vm_panel_dir/var" 2>/dev/null || true)"
  if [[ -z "$vm_web_user" || "$vm_web_user" == root ]]; then
    vm_web_user="$(id -u www-data >/dev/null 2>&1 && echo www-data || echo nginx)"
  fi
fi
id "$vm_web_user" >/dev/null 2>&1 || { echo 'Set EASYWI_VM_CONSOLE_WEB_USER to the panel runtime user.' >&2; exit 1; }
vm_log="$(mktemp /tmp/easywi-vm-console-setup.XXXXXX)"
chmod 0600 "$vm_log"
trap 'rm -f "$vm_log"' EXIT
vm_config_args=(--core "$vm_panel_dir" --agent-binary "$vm_agent_binary" --vhost "$vm_vhost")
[[ -z "$vm_public_origin" ]] || vm_config_args+=(--origin "$vm_public_origin")
[[ -z "$vm_panel_origin" ]] || vm_config_args+=(--panel-origin "$vm_panel_origin")
python3 "$vm_helper_root/configure-vm-console.py" "${vm_config_args[@]}"
# Updating only .env.local does not affect a previously dumped .env.local.php.
if [[ -f "$vm_panel_dir/.env.local.php" ]]; then
  if ! COMPOSER_ALLOW_SUPERUSER=1 "${vm_composer_command[@]}" --working-dir "$vm_panel_dir" dump-env prod --no-interaction >"$vm_log" 2>&1; then
    echo 'Production environment rebuild failed. Review the environment configuration and retry; no service has been reloaded.' >&2
    exit 1
  fi
fi
if ! runuser -u "$vm_web_user" -- "$vm_php" "$vm_panel_dir/bin/console" cache:clear --env=prod --no-debug --no-interaction >"$vm_log" 2>&1; then
  echo 'Panel cache rebuild failed. Check runtime-user permissions; no service has been reloaded.' >&2
  exit 1
fi
nginx -t
systemctl daemon-reload
systemctl enable easywi-vm-console.service
systemctl restart easywi-vm-console.service
systemctl is-active --quiet easywi-vm-console.service || { echo 'VM console gateway did not start; inspect its service journal.' >&2; exit 1; }
systemctl reload nginx
# Refresh FPM's OPcache, including installations with timestamp checks disabled.
vm_php_version="$("$vm_php" -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')"
for vm_fpm in "php${vm_php_version}-fpm.service" php-fpm.service; do
  if systemctl is-active --quiet "$vm_fpm"; then
    systemctl reload "$vm_fpm"
    break
  fi
done
printf '%s\n' 'VM console gateway and Nginx route are configured. Reopen the customer VM page; no VM was stopped or changed.'
