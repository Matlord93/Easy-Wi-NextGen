#!/usr/bin/env bash
set -euo pipefail

CORE_DIR="${EASYWI_CORE_DIR:-/var/www/easywi/core}"
LOG_PREFIX="[post-deploy]"

echo "$LOG_PREFIX Installing composer dependencies."
(cd "$CORE_DIR" && composer install --no-dev --no-interaction --optimize-autoloader)

echo "$LOG_PREFIX Clearing cache."
(cd "$CORE_DIR" && php bin/console cache:clear --env=prod)

echo "$LOG_PREFIX Running migrations."
(cd "$CORE_DIR" && php bin/console doctrine:migrations:migrate --no-interaction)

echo "$LOG_PREFIX Warming cache."
(cd "$CORE_DIR" && php bin/console cache:warmup --env=prod)

echo "$LOG_PREFIX Fixing permissions."
chmod -R g+w "$CORE_DIR/var" "$CORE_DIR/srv" || true

echo "$LOG_PREFIX Done."
