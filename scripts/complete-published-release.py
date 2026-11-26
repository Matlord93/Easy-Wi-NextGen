#!/usr/bin/env python3
"""Attach staged files to a published release without replacing existing assets."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
from urllib.parse import quote


def release_state(tag, repository):
    result = subprocess.run(
        ["gh", "release", "view", tag, "--repo", repository, "--json", "isDraft,assets"],
        check=False, capture_output=True, text=True,
    )
    if result.returncode and "release not found" in result.stderr.lower():
        return "new"
    if result.returncode:
        raise ValueError(result.stderr.strip() or "Could not check the GitHub release")
    details = json.loads(result.stdout)
    if not isinstance(details.get("isDraft"), bool) or not isinstance(details.get("assets"), list):
        raise ValueError("Unexpected GitHub release state")
    if details["isDraft"]:
        return "draft"
    return "with-assets" if details["assets"] else "empty"


def upload_order(path):
    # Expose update metadata after the archives and detached signatures are available.
    if path.name in ("manifest.json", "manifest-core.json"):
        return 2, path.name
    if path.name.startswith("checksums-") and path.suffix == ".txt":
        return 1, path.name
    return 0, path.name


def attach(tag, repository, staged, notes_path):
    output = subprocess.check_output(
        ["gh", "api", f"repos/{repository}/releases/tags/{quote(tag, safe='')}"], text=True,
    )
    release = json.loads(output)
    if release.get("tag_name") != tag or release.get("draft") is not False or release.get("immutable"):
        raise ValueError("Expected an existing, published and editable release")

    files = {path.name: path for path in staged.iterdir() if path.is_file()}
    if not {"CHANGELOG.md", "manifest.json", "checksums-core.txt"} <= files.keys():
        raise ValueError("Incomplete staged release")

    existing = set()
    for asset in release["assets"]:
        name = asset["name"]
        if name not in files:
            raise ValueError(f"The release already contains an unrelated asset: {name}")
        with files[name].open("rb") as handle:
            digest = "sha256:" + hashlib.file_digest(handle, "sha256").hexdigest()
        if asset.get("digest") != digest or asset.get("state") != "uploaded":
            raise ValueError(f"Existing release asset does not match the staged file: {name}")
        existing.add(name)

    notes = notes_path.read_text(encoding="utf-8").strip()
    original = (release.get("body") or "").strip()
    for path in sorted(files.values(), key=upload_order):
        if path.name not in existing:
            subprocess.run(["gh", "release", "upload", tag, str(path), "--repo", repository], check=True)

    if notes and original != notes and not original.startswith(notes + "\n\n"):
        with tempfile.NamedTemporaryFile(mode="w", encoding="utf-8") as handle:
            handle.write(notes + ("\n\n" + original if original else "") + "\n")
            handle.flush()
            subprocess.run(["gh", "release", "edit", tag, "--notes-file", handle.name, "--repo", repository], check=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    subcommands = parser.add_subparsers(dest="command", required=True)
    subcommands.add_parser("state").add_argument("tag")
    upload = subcommands.add_parser("upload")
    upload.add_argument("tag")
    upload.add_argument("directory", type=Path)
    upload.add_argument("notes", type=Path)
    args = parser.parse_args()
    repository = os.environ["GITHUB_REPOSITORY"]

    try:
        if args.command == "state":
            print(release_state(args.tag, repository))
        else:
            attach(args.tag, repository, args.directory, args.notes)
    except (KeyError, OSError, ValueError, subprocess.CalledProcessError) as error:
        parser.exit(1, f"{error}\n")


if __name__ == "__main__":
    main()
