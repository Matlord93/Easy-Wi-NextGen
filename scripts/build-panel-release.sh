#!/usr/bin/env bash
set -euo pipefail

tag="${1:?Expected release tag}"
base_tag="${2-}"
base_sha="${3-}"
dist="${4:?Expected destination directory}"
notes="${5:?Expected release notes}"
version="${tag#v}"

mkdir -p "$dist"
scratch="$(mktemp -d)"
trap 'rm -rf "$scratch"' EXIT

bash scripts/prepare-minecraft-catalog.sh core
catalog_files=(core/resources/minecraft/{minecraft-server,paper-versions,bedrock-server}.json)
console_files=(core/tools/console/configure-live-console.py core/tools/console/live-console.conf.template core/public/js/gameserver/console-live.js)
archive="$dist/easywi-webinterface-$version.zip"

# Take Git-tracked files from the checked-out commit and add only generated release files.
git archive --format=zip --output="$archive" HEAD -- . \
  ':(exclude,glob)dist/**' ':(exclude,glob)var/**' ':(exclude,glob)storage/**' ':(exclude,glob)uploads/**' \
  ':(exclude,glob)core/var/**' ':(exclude,glob)core/storage/**' ':(exclude,glob)core/uploads/**' \
  ':(exclude,glob)core/srv/**' ':(exclude,glob)core/node_modules/**' ':(exclude,glob)core/vendor/**' \
  ':(exclude)core/.env.local' ':(exclude)core/.env.local.php'
printf '%s\n' "$version" > "$scratch/VERSION"
zip -q -j "$archive" "$scratch/VERSION"
zip -q "$archive" "${catalog_files[@]}"
for path in VERSION core/.env "${console_files[@]}" "${catalog_files[@]}"; do
  unzip -l "$archive" "$path" >/dev/null
done

if [[ -n "$base_tag" && -n "$base_sha" ]]; then
  extra_files=()
  for path in "${catalog_files[@]}"; do
    extra_files+=(--include "$path")
  done
  python3 scripts/build-webinterface-delta.py --base "$base_sha" --previous-version "${base_tag#v}" \
    --version "$version" --dist "$scratch" "${extra_files[@]}"
  mv "$scratch/easywi-webinterface-update-${base_tag#v}-to-$version.zip" "$dist/"
fi

python3 - "$tag" "$base_tag" "$dist" "$notes" "$scratch" <<'PY'
import hashlib
import json
import os
from pathlib import Path
import sys

tag, base_tag, dist, notes, scratch = sys.argv[1:]
dist, notes, scratch = Path(dist), Path(notes), Path(scratch)
version = tag.removeprefix("v")
release = f"https://github.com/{os.environ['GITHUB_REPOSITORY']}/releases/download/{tag}"


def asset(name):
    with (dist / name).open("rb") as handle:
        digest = hashlib.file_digest(handle, "sha256").hexdigest()
    return {"asset_url": f"{release}/{name}", "sha256": digest}


def write(name, payload):
    (dist / name).write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")


common = {"latest": version, "notes": notes.read_text(encoding="utf-8").strip()}
payload = common | asset(f"easywi-webinterface-{version}.zip")
if base_tag:
    previous = base_tag.removeprefix("v")
    payload["delta"] = asset(f"easywi-webinterface-update-{previous}-to-{version}.zip") | {
        "from": previous,
        "delete": json.loads((scratch / "delta-delete.json").read_text(encoding="utf-8")),
    }
write("manifest.json", payload)
write("manifest-core.json", common | asset(f"easywi-core-{version}.tar.gz"))
PY
