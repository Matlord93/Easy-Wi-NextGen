#!/usr/bin/env python3
"""Build the Webinterface update ZIP and deletion list from a Git comparison."""

import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import zipfile


EXCLUDED_PREFIXES = (
    ".git/", "dist/", "var/", "storage/", "uploads/", "core/var/",
    "core/storage/", "core/uploads/", "core/srv/", "core/node_modules/", "core/vendor/",
)
EXCLUDED_FILES = {"core/.env.local", "core/.env.local.php"}


def changes(base, head):
    # A rename needs two independent operations, including across an excluded boundary.
    raw = subprocess.check_output(["git", "diff", "--name-status", "--no-renames", "-z", base, head, "--"])
    fields = raw.decode("utf-8").split("\0")[:-1]
    include, delete = [], []
    for status, path in zip(fields[::2], fields[1::2], strict=True):
        if path in EXCLUDED_FILES or path.startswith(EXCLUDED_PREFIXES):
            continue
        (delete if status == "D" else include).append(path)
    return include, delete


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base", required=True)
    parser.add_argument("--head", default="HEAD")
    parser.add_argument("--previous-version", required=True)
    parser.add_argument("--version", required=True)
    parser.add_argument("--include", action="append", default=[], help="Generated file to include even if no Git path changed")
    parser.add_argument("--dist", type=Path, default=Path("dist"))
    parser.add_argument("--github-env", type=Path)
    args = parser.parse_args()

    include, delete = changes(args.base, args.head)
    include = sorted(set(include + args.include) - {"VERSION"})
    delete = sorted(set(delete) - set(include) - {"VERSION"})
    if not include and not delete and args.previous_version == args.version:
        return

    args.dist.mkdir(parents=True, exist_ok=True)
    archive = f"easywi-webinterface-update-{args.previous_version}-to-{args.version}.zip"
    archive_path = args.dist / archive
    with zipfile.ZipFile(archive_path, "w", zipfile.ZIP_DEFLATED) as bundle:
        # The full release writes the release tag's version even if VERSION did not change in Git.
        bundle.writestr("VERSION", f"{args.version}\n")
        for name in include:
            file = Path(name)
            if file.is_symlink() or not file.is_file():
                raise ValueError(f"Expected a regular file in the release checkout: {name!r}")
            bundle.write(file, arcname=name)

    delete_file = args.dist / "delta-delete.json"
    delete_file.write_text(json.dumps(delete, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    with archive_path.open("rb") as handle:
        digest = hashlib.file_digest(handle, "sha256").hexdigest()
    if args.github_env:
        with args.github_env.open("a", encoding="utf-8") as handle:
            handle.write(f"DELTA_ARCHIVE={archive}\nDELTA_SHA256={digest}\nDELTA_DELETE_FILE={delete_file}\nDELTA_FROM={args.previous_version}\n")


if __name__ == "__main__":
    main()
