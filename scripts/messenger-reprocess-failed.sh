#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP_DIR="${APP_DIR:-$ROOT_DIR/core}"
CONSOLE="$APP_DIR/bin/console"
PHP_BIN="${PHP_BIN:-php}"

MODE="retry"
TARGET="all"
FORCE=0

usage() {
  cat <<USAGE
Usage: $(basename "$0") [--id <message-id>|--all] [--remove] [--force]

Reprocess failed Symfony Messenger messages from the failed/dead-letter transport.

Options:
  --id <message-id> Retry/remove a single failed message by id.
  --all             Retry/remove all failed messages (default target).
  --remove          Remove failed messages instead of retrying them.
  --force           Run non-interactively without confirmation.
  -h, --help        Show this help.

Environment:
  APP_DIR           Symfony app directory containing bin/console (default: <repo>/core)
  PHP_BIN           PHP binary to use (default: php)
USAGE
}

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    echo "[FAIL] Required command missing: $1" >&2
    exit 1
  }
}

confirm() {
  local prompt="$1"

  if [[ "$FORCE" == "1" ]]; then
    return 0
  fi

  read -r -p "$prompt [y/N]: " answer
  [[ "$answer" =~ ^[Yy]$ ]]
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --id)
      shift
      [[ $# -gt 0 ]] || {
        echo "[FAIL] Missing value for --id" >&2
        exit 1
      }
      TARGET="$1"
      ;;
    --all)
      TARGET="all"
      ;;
    --remove)
      MODE="remove"
      ;;
    --force)
      FORCE=1
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

if [[ "$TARGET" != "all" && ! "$TARGET" =~ ^[0-9]+$ ]]; then
  echo "[FAIL] --id must be a numeric failed-message id" >&2
  exit 1
fi

require_cmd "$PHP_BIN"
[[ -x "$CONSOLE" ]] || {
  echo "[FAIL] Symfony console not found/executable: $CONSOLE" >&2
  exit 1
}

cd "$APP_DIR"

if [[ "$MODE" == "remove" ]]; then
  if [[ "$TARGET" == "all" ]]; then
    confirm "This will remove ALL failed messages. Continue?" || {
      echo "[ABORT] No changes made."
      exit 1
    }
    "$PHP_BIN" bin/console messenger:failed:remove --all --force
  else
    confirm "Remove failed message id=$TARGET?" || {
      echo "[ABORT] No changes made."
      exit 1
    }
    "$PHP_BIN" bin/console messenger:failed:remove "$TARGET" --force
  fi
  exit 0
fi

if [[ "$TARGET" == "all" ]]; then
  confirm "Retry ALL failed messages now?" || {
    echo "[ABORT] No changes made."
    exit 1
  }
  "$PHP_BIN" bin/console messenger:failed:retry --force
else
  confirm "Retry failed message id=$TARGET now?" || {
    echo "[ABORT] No changes made."
    exit 1
  }
  "$PHP_BIN" bin/console messenger:failed:retry "$TARGET" --force
fi
