#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="${1:-$ROOT/dist/core-release}"
VERSION="${VERSION:-$(cat "$ROOT/VERSION")}"
TAG="v${VERSION}"
BUILD_DATE="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
GIT_SHA="$(git -C "$ROOT" rev-parse HEAD)"

rm -rf "$DIST" && mkdir -p "$DIST/work"

prepare_tree(){
  local mode="$1"
  local target="$DIST/work/$mode"
  mkdir -p "$target"
  rsync -a --delete \
    --exclude 'var/cache/' --exclude 'var/log/' --exclude '.env.local' --exclude '.env.*.local' --exclude 'secrets/' \
    "$ROOT/core/" "$target/"
  mkdir -p "$target/var/cache" "$target/var/log"
  cp "$ROOT/VERSION" "$target/VERSION"
  if [[ "$mode" == "novendor" ]]; then rm -rf "$target/vendor"; fi
}

pack(){
  local mode="$1"
  local base="core-${mode}-${VERSION}"
  (cd "$DIST/work/$mode" && zip -qr "$DIST/${base}.zip" .)
  (cd "$DIST/work/$mode" && tar -czf "$DIST/${base}.tar.gz" .)
}

prepare_tree full
prepare_tree novendor
pack full
pack novendor

(
 cd "$DIST"
 sha256sum core-*.zip core-*.tar.gz > checksums.sha256
)

cat > "$DIST/manifest.json" <<JSON
{
  "version": "$VERSION",
  "tag": "$TAG",
  "git_sha": "$GIT_SHA",
  "build_date": "$BUILD_DATE",
  "migrations": true,
  "artifacts": [
    "core-full-$VERSION.zip",
    "core-full-$VERSION.tar.gz",
    "core-novendor-$VERSION.zip",
    "core-novendor-$VERSION.tar.gz"
  ]
}
JSON

python3 - <<PY
import json, pathlib, hashlib, os
root=pathlib.Path('$DIST')
version='$VERSION'
repo=os.environ.get('GITHUB_REPOSITORY','example/repo')
tag='v'+version
release={"version":version,"date":"$BUILD_DATE"[:10],"channel":"stable","migrations":True,"min_php":"8.3","min_db":{"mysql":"8.0"},"artifacts":{},"changelog":"See release notes."}
for name,key in [
 (f'core-full-{version}.zip','core_full_zip'),
 (f'core-full-{version}.tar.gz','core_full_targz'),
 (f'core-novendor-{version}.zip','core_novendor_zip'),
 (f'core-novendor-{version}.tar.gz','core_novendor_targz')]:
 p=root/name
 release['artifacts'][key]={"url":f"https://github.com/{repo}/releases/download/{tag}/{name}","sha256":hashlib.sha256(p.read_bytes()).hexdigest(),"size":p.stat().st_size}
feed={"latest":version,"releases":[release]}
(root/'feed.json').write_text(json.dumps(feed,indent=2)+"\n")
PY

echo "Artifacts created in $DIST"
