#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

usage() {
  cat >&2 <<'USAGE'
Usage: scripts/ci-dependency-bootstrap.sh composer|go

Preflights and installs CI dependencies with cache-friendly, token-safe defaults.
Secrets are read only from COMPOSER_GITHUB_TOKEN or GITHUB_TOKEN and are never printed.
USAGE
}

mask_secret() {
  local value="${1:-}"
  [[ -z "$value" ]] && return 0
  if [[ -n "${GITHUB_ACTIONS:-}" ]]; then
    printf '::add-mask::%s\n' "$value"
  fi
}

section() { printf '\n==> %s\n' "$*"; }

require_cmd() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Required command missing: $1" >&2
    exit 127
  fi
}

network_probe() {
  local label="$1" url="$2" method="${3:-HEAD}"
  require_cmd curl
  local curl_args=(-fsS --connect-timeout 10 --max-time 30 --output /dev/null)
  if [[ "$method" == "HEAD" ]]; then
    curl_args+=(--head)
  fi
  if curl "${curl_args[@]}" "$url"; then
    echo "OK: $label reachable"
  else
    local rc=$?
    cat >&2 <<MSG
Dependency network preflight failed: $label is not reachable (curl exit $rc).
This is an environment/network policy issue for the dependency gate, not a Musicbot code failure.
Allow outbound HTTPS CONNECT to GitHub/Packagist/Go module proxy or configure an approved mirror.
MSG
    return "$rc"
  fi
}

setup_composer_auth() {
  local token="${COMPOSER_GITHUB_TOKEN:-${GITHUB_TOKEN:-}}"
  [[ -z "$token" ]] && return 0
  mask_secret "$token"
  export COMPOSER_AUTH
  COMPOSER_AUTH="$(TOKEN="$token" php -r 'echo json_encode(["github-oauth" => ["github.com" => getenv("TOKEN")]], JSON_UNESCAPED_SLASHES);')"
  echo "Composer GitHub OAuth token configured from environment (masked)."
}

composer_bootstrap() {
  local install_args=("${@:1}")
  require_cmd php
  require_cmd composer
  section "Composer diagnostics"
  composer --version
  if ! composer diagnose; then
    echo "Composer diagnose reported connectivity or configuration warnings; dependency preflight will perform explicit checks next." >&2
  fi

  section "Composer network preflight"
  network_probe "Packagist metadata" "https://repo.packagist.org/packages.json"
  # GitHub's rate-limit endpoint returns 404 to HEAD requests, so probe it with GET.
  network_probe "GitHub API" "https://api.github.com/rate_limit" "GET"
  network_probe "GitHub codeload" "https://codeload.github.com/symfony/console/zip/refs/tags/v8.0.0"

  section "Composer install"
  setup_composer_auth
  composer config --global cache-files-ttl 604800
  composer config --global cache-files-maxsize 1GiB
  composer install --working-dir="$REPO_ROOT/core" --no-interaction --prefer-dist --no-progress "${install_args[@]}"
}

go_bootstrap() {
  require_cmd go
  require_cmd curl
  # The module's go directive is the source of truth. Some self-hosted runners
  # configure GOTOOLCHAIN=local globally, which prevents Go from selecting the
  # newer toolchain requested by go.mod even after actions/setup-go has run.
  # Override that runner policy for this dependency bootstrap so Go can fetch
  # the required toolchain through the configured module proxy.
  export GOTOOLCHAIN="${EASYWI_GOTOOLCHAIN:-auto}"
  section "Go environment"
  (cd "$REPO_ROOT/agent" && go version && go env GOTOOLCHAIN GOPROXY GOMODCACHE GOSUMDB)

  section "Go module network preflight"
  local proxy_ok=0 direct_ok=0
  network_probe "proxy.golang.org" "https://proxy.golang.org/github.com/gorilla/websocket/@v/v1.5.3.info" || proxy_ok=$?
  network_probe "direct GitHub module access" "https://github.com/gorilla/websocket" || direct_ok=$?

  local proxy="${EASYWI_GOPROXY_MIRROR:-https://proxy.golang.org,direct}"
  if [[ -z "${EASYWI_GOPROXY_MIRROR:-}" && $proxy_ok -ne 0 && $direct_ok -eq 0 ]]; then
    echo "proxy.golang.org is blocked on this runner; falling back to direct GitHub module download." >&2
    proxy="direct"
  elif [[ $proxy_ok -ne 0 && $direct_ok -ne 0 ]]; then
    cat >&2 <<MSG
Neither proxy.golang.org nor direct GitHub module access is reachable. Configure EASYWI_GOPROXY_MIRROR with an approved internal Go module mirror.
MSG
    exit 1
  fi
  export GOPROXY="$proxy"
  export GONOSUMDB="${GONOSUMDB:-}"
  echo "Using GOPROXY=$GOPROXY"
  if ! (cd "$REPO_ROOT/agent" && go mod download); then
    cat >&2 <<MSG
Go module download failed with GOPROXY=$GOPROXY.
This is an environment/network policy issue for the dependency gate, not a Musicbot code failure.
If proxy.golang.org or direct GitHub is blocked, set EASYWI_GOPROXY_MIRROR to an approved internal Go module mirror that contains github.com/gorilla/websocket@v1.5.3 and the remaining agent modules.
MSG
    exit 1
  fi
  (cd "$REPO_ROOT/agent" && go mod verify)
}

case "${1:-}" in
  composer) shift; composer_bootstrap "$@" ;;
  go) go_bootstrap ;;
  -h|--help|help) usage ;;
  *) usage; exit 2 ;;
esac
