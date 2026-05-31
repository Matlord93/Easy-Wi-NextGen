#!/usr/bin/env bash
set -euo pipefail

CORE_DIR="${EASYWI_CORE_DIR:-/var/www/easywi/core}"
LOG_PREFIX="[post-deploy]"

fix_permissions() {
  local runtime_user="${EASYWI_RUNTIME_USER:-}"
  local runtime_group="${EASYWI_RUNTIME_GROUP:-}"

  if [[ -z "$runtime_user" ]]; then
    if id -u www-data >/dev/null 2>&1; then
      runtime_user="www-data"
    elif id -u nginx >/dev/null 2>&1; then
      runtime_user="nginx"
    fi
  fi

  if [[ -z "$runtime_group" ]]; then
    if [[ -n "$runtime_user" ]]; then
      runtime_group="$(id -gn "$runtime_user" 2>/dev/null || true)"
    elif getent group www-data >/dev/null 2>&1; then
      runtime_group="www-data"
    elif getent group nginx >/dev/null 2>&1; then
      runtime_group="nginx"
    fi
  fi

  mkdir -p "$CORE_DIR/var/cache/prod/twig" "$CORE_DIR/var/log" "$CORE_DIR/srv"

  if [[ -n "$runtime_user" && -n "$runtime_group" ]]; then
    chown -R "${runtime_user}:${runtime_group}" "$CORE_DIR/var" "$CORE_DIR/srv" || true
  elif [[ -n "$runtime_group" ]]; then
    chgrp -R "$runtime_group" "$CORE_DIR/var" "$CORE_DIR/srv" || true
  fi

  find "$CORE_DIR/var" "$CORE_DIR/srv" -type d -exec chmod 775 {} \; || true
  find "$CORE_DIR/var" "$CORE_DIR/srv" -type f -exec chmod 664 {} \; || true
  chmod -R g+rwX "$CORE_DIR/var" "$CORE_DIR/srv" || true
}

echo "$LOG_PREFIX Preparing runtime directories."
fix_permissions

echo "$LOG_PREFIX Installing composer dependencies."
(cd "$CORE_DIR" && composer install --no-dev --no-interaction --optimize-autoloader)

echo "$LOG_PREFIX Clearing cache."
(cd "$CORE_DIR" && php bin/console cache:clear --env=prod)

echo "$LOG_PREFIX Running migrations."
(cd "$CORE_DIR" && php bin/console doctrine:migrations:migrate --no-interaction)

echo "$LOG_PREFIX Importing Minecraft versions catalog."
(cd "$CORE_DIR" && php bin/console app:minecraft:versions:import --deactivate-missing)

echo "$LOG_PREFIX Warming cache."
(cd "$CORE_DIR" && php bin/console cache:warmup --env=prod)

echo "$LOG_PREFIX Fixing permissions."
fix_permissions

echo "$LOG_PREFIX Done."
