#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP_DIR="$ROOT_DIR/core"

if [[ ! -f "$APP_DIR/bin/console" ]]; then
  echo "[FAIL] bin/console not found at $APP_DIR/bin/console"
  exit 1
fi

cd "$APP_DIR"
php bin/console app:setup:manual-update --no-interaction "$@"
