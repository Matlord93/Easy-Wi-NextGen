#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP_DIR="${APP_DIR:-$ROOT_DIR/core}"
CONSOLE="$APP_DIR/bin/console"
PHP_BIN="${PHP_BIN:-php}"

MAX_MESSAGES="${MAX_MESSAGES:-20}"
FAILED_ONLY="${FAILED_ONLY:-0}"

usage() {
  cat <<USAGE
Usage: $(basename "$0") [--max <n>] [--failed-only]

Inspect Symfony Messenger queue health with focus on failed/dead-letter messages.

Options:
  --max <n>         Number of failed messages to print (default: ${MAX_MESSAGES})
  --failed-only     Skip transport stats and only print failed messages
  -h, --help        Show this help

Environment:
  APP_DIR           Symfony app directory containing bin/console (default: <repo>/core)
  PHP_BIN           PHP binary to use (default: php)
  MAX_MESSAGES      Default for --max
USAGE
}

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    echo "[FAIL] Required command missing: $1" >&2
    exit 1
  }
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --max)
      shift
      [[ $# -gt 0 ]] || {
        echo "[FAIL] Missing value for --max" >&2
        exit 1
      }
      MAX_MESSAGES="$1"
      ;;
    --failed-only)
      FAILED_ONLY=1
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "[FAIL] Unknown option: $1" >&2
      usage >&2
      exit 1
      ;;
  esac
  shift
done

[[ "$MAX_MESSAGES" =~ ^[0-9]+$ ]] || {
  echo "[FAIL] --max must be a non-negative integer" >&2
  exit 1
}

require_cmd "$PHP_BIN"
[[ -x "$CONSOLE" ]] || {
  echo "[FAIL] Symfony console not found/executable: $CONSOLE" >&2
  exit 1
}

cd "$APP_DIR"

if [[ "$FAILED_ONLY" != "1" ]]; then
  echo "== Messenger transport stats =="
  "$PHP_BIN" bin/console messenger:stats --format=txt
  echo
fi

echo "== Failed messages (max ${MAX_MESSAGES}) =="
"$PHP_BIN" bin/console messenger:failed:show --max="$MAX_MESSAGES"
