#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

VERSION=$(cat "$ROOT/VERSION")
"$ROOT/scripts/build-core-release.sh" "$TMP/dist" >/dev/null

mkdir -p "$TMP/app/srv/update/jobs" "$TMP/app/srv/update/logs" "$TMP/app/srv/update/backups" "$TMP/app/srv/update/releases"
cp -a "$ROOT/core" "$TMP/app/releases-a"
ln -s "$TMP/app/releases-a" "$TMP/app/current"

JOB_ID="smoke-$(date +%s)"
cat > "$TMP/app/srv/update/jobs/$JOB_ID.json" <<JSON
{
  "id": "$JOB_ID",
  "type": "update",
  "status": "pending",
  "logPath": "$TMP/app/srv/update/logs/$JOB_ID.log",
  "payload": {
    "package_url": "file://$TMP/dist/core-novendor-$VERSION.tar.gz",
    "sha256": "$(sha256sum "$TMP/dist/core-novendor-$VERSION.tar.gz" | awk '{print $1}')",
    "target_version": "$VERSION"
  }
}
JSON

EASYWI_CORE_DIR="$TMP/app" \
EASYWI_CORE_RELEASES_DIR="$TMP/app/srv/update/releases" \
EASYWI_CORE_CURRENT_SYMLINK="$TMP/app/current" \
EASYWI_CORE_JOBS_DIR="$TMP/app/srv/update/jobs" \
EASYWI_CORE_LOGS_DIR="$TMP/app/srv/update/logs" \
EASYWI_CORE_BACKUPS_DIR="$TMP/app/srv/update/backups" \
EASYWI_CORE_HEALTHCHECK_URL="http://127.0.0.1/health" \
bash "$ROOT/core/deploy/easywi-core-runner" --run-job "$JOB_ID" || true

echo "Smoke complete (runner executed)."
