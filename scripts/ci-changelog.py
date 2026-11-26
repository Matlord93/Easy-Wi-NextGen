#!/usr/bin/env python3
"""Validate a hand-written changelog and prepare it for CI and releases."""

import argparse
from dataclasses import dataclass
from datetime import date, datetime, time, timezone
import json
import os
from pathlib import Path
import re
import subprocess


TAG = re.compile(r"(agent-v|v)([0-9]+\.[0-9]+\.[0-9]+)(?:-([A-Za-z0-9.-]+))?(?:\+[A-Za-z0-9.-]+)?$")
HEADING = re.compile(r"## ([0-9]{4}-[0-9]{2}-[0-9]{2}) · (.+)")
REPOSITORY = re.compile(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+")


def git(*args, data=None):
    return subprocess.run(
        ["git", *args], input=data, stdout=subprocess.PIPE, check=True,
    ).stdout


def commit_ref(ref):
    if not ref or not ref.strip("0"):
        return None
    result = subprocess.run(
        ["git", "rev-parse", "--verify", "--quiet", "--end-of-options", f"{ref}^{{commit}}"],
        stdout=subprocess.PIPE, check=False,
    )
    return result.stdout.decode().strip() if result.returncode == 0 else None


def release_channel(tag):
    suffix = (TAG.fullmatch(tag)[3] or "").lower()
    if re.match(r"(predev|dev|alpha|snapshot|nightly)(?:[.\d-]|$)", suffix):
        return "dev"
    if re.match(r"(beta|preview|rc)(?:[.\d-]|$)", suffix):
        return "beta"
    return "stable"


def release_order(tag):
    match = TAG.fullmatch(tag)
    version = tuple(int(part) for part in match[2].split("."))
    prerelease = match[3]
    identifiers = tuple(
        (0, int(part)) if part.isdigit() else (1, part)
        for part in (prerelease or "").split(".")
    )
    return version, prerelease is None, identifiers


def published_releases(repository, releases_file, check_releases):
    if releases_file:
        rows = json.loads(Path(releases_file).read_text(encoding="utf-8"))
    elif check_releases:
        output = subprocess.check_output([
            "gh", "api", "--paginate", f"repos/{repository}/releases?per_page=100", "--jq",
            '.[] | select(.draft == false and .published_at != null) | {tag_name, published_at, assets: [.assets[] | {name, state}]} | @json',
        ], text=True)
        rows = [json.loads(line) for line in output.splitlines()]
    else:
        return None, set()
    dates = {}
    with_webinterface = set()
    for row in rows:
        tag = row["tag_name"]
        if row.get("draft") or not row.get("published_at") or not TAG.fullmatch(tag):
            continue
        dates[tag] = datetime.fromisoformat(row["published_at"].replace("Z", "+00:00")).astimezone(timezone.utc)
        assets = {asset["name"] for asset in row.get("assets", []) if asset["state"] == "uploaded"}
        if {f"easywi-webinterface-{tag[1:]}.zip", "checksums-core.txt", "manifest.json"} <= assets:
            with_webinterface.add(tag)
    return dates, with_webinterface


def previous_tag(releases, tag, channel):
    family = TAG.fullmatch(tag)[1]
    candidates = [
        candidate for candidate in releases
        if TAG.fullmatch(candidate)[1] == family and release_order(candidate) < release_order(tag)
    ]
    candidates.sort(key=lambda candidate: (releases[candidate], release_order(candidate)), reverse=True)
    for compatible_channel in dict.fromkeys((channel, "stable")):
        match = next((candidate for candidate in candidates if release_channel(candidate) == compatible_channel), None)
        if match is not None:
            return match
    return None


@dataclass
class Entry:
    day: date
    version: str
    content: str


def read_changelog(path):
    source = Path(path).read_bytes().decode("utf-8")
    if not source.startswith("# Changelog\n") and not source.startswith("# Changelog\r\n"):
        raise ValueError("Expected '# Changelog' as the first line")

    headings = list(re.finditer(r"^## [^\r\n]+", source, re.MULTILINE))
    if not headings:
        raise ValueError("Expected a dated section such as '## 2026-11-26 · Unveröffentlicht'")

    entries = []
    versions = set()
    for index, heading in enumerate(headings):
        match = HEADING.fullmatch(heading[0])
        if match is None:
            raise ValueError(f"Invalid dated changelog heading: {heading[0]}")
        day = date.fromisoformat(match[1])
        version = match[2]
        if version != "Unveröffentlicht":
            if not TAG.fullmatch(version) or version in versions:
                raise ValueError(f"Invalid or repeated release tag in changelog: {version}")
            versions.add(version)
        if entries and day > entries[-1].day:
            raise ValueError("Changelog dates must be ordered newest first")
        end = headings[index + 1].start() if index + 1 < len(headings) else len(source)
        entries.append(Entry(day, version, source[heading.start():end]))

    return source[:headings[0].start()], entries


def release_reference(tag, published_at, repository):
    if repository:
        tag = f"[`{tag}`](https://github.com/{repository}/releases/tag/{tag.replace('+', '%2B')})"
    return f"{tag} vom {published_at.date().isoformat()}"


def write(path, content):
    target = Path(path)
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_bytes(content.encode("utf-8"))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", default="CHANGELOG.md", help="Hand-written Markdown changelog")
    parser.add_argument("--head", default="HEAD", help="Git commit for choosing the previous release")
    parser.add_argument("--tag", help="Release tag, including for a new or manually built release")
    parser.add_argument("--channel", choices=("stable", "beta", "dev"))
    parser.add_argument("--date", type=date.fromisoformat, help="Report through this UTC day (YYYY-MM-DD); default: current time")
    parser.add_argument("--repository", default=os.getenv("GITHUB_REPOSITORY", ""))
    release_source = parser.add_mutually_exclusive_group()
    release_source.add_argument("--check-releases", action="store_true", help="Check published GitHub releases using the gh CLI")
    release_source.add_argument("--releases-file", help="Read a JSON array of GitHub releases for an offline check")
    parser.add_argument("--output", default="dist/CHANGELOG.md")
    parser.add_argument("--summary", help="Append the verification results to the CI job summary")
    parser.add_argument("--release-body", help="Copy the selected handwritten version section into the release notes")
    parser.add_argument("--github-output", help="Write release comparison references to GitHub step outputs")
    args = parser.parse_args()

    source_path = Path(args.source).resolve()
    if any(Path(path).resolve() == source_path for path in (args.output, args.summary, args.release_body, args.github_output) if path):
        parser.error("The source changelog and output must be different files")
    if args.repository and not REPOSITORY.fullmatch(args.repository):
        parser.error("Invalid GitHub repository")
    if args.check_releases and not args.repository:
        parser.error("--check-releases requires a GitHub repository")
    if args.tag and not TAG.fullmatch(args.tag):
        parser.error("Expected a version tag such as v1.2.3-beta.1 or agent-v1.2.3")

    try:
        intro, entries = read_changelog(args.source)
    except (OSError, ValueError) as error:
        parser.error(f"Could not read the manual changelog: {error}")

    head = commit_ref(args.head)
    if head is None:
        parser.error("The target commit does not resolve")
    if args.tag and (existing := commit_ref(f"refs/tags/{args.tag}")) and existing != head:
        parser.error("The release tag already points to a different commit")
    try:
        releases, with_webinterface = published_releases(args.repository, args.releases_file, args.check_releases)
    except (OSError, ValueError, TypeError, KeyError, subprocess.CalledProcessError) as error:
        parser.error(f"Could not verify published releases: {error}")

    cutoff = datetime.combine(args.date, time.max, tzinfo=timezone.utc) if args.date else datetime.now(timezone.utc)
    target_date = (releases or {}).get(args.tag)
    if target_date and target_date > cutoff:
        target_date = None
    if target_date and not args.date:
        cutoff = target_date

    available = {
        tag: published_at for tag, published_at in (releases or {}).items()
        if published_at <= (target_date or cutoff)
    }
    channel = (args.channel or release_channel(args.tag)) if args.tag else None
    base_tag = None
    if args.tag and available:
        reachable = git("tag", "--merged", head).decode().splitlines()
        candidates = {tag: available[tag] for tag in reachable if tag in available}
        if args.tag.startswith("v"):
            candidates = {tag: published_at for tag, published_at in candidates.items() if tag in with_webinterface}
        base_tag = previous_tag(candidates, args.tag, channel)
    base = commit_ref(f"refs/tags/{base_tag}") if base_tag else None

    latest_panel = max(
        (tag for tag in available if tag.startswith("v")),
        key=lambda tag: (available[tag], release_order(tag)), default=None,
    )
    if releases is None:
        release_status = "Veröffentlichungsstatus nicht geprüft."
    elif args.tag:
        release_status = (
            f"{release_reference(args.tag, target_date, args.repository)} wurde veröffentlicht."
            if target_date else f"`{args.tag}` war zum Zeitpunkt der Prüfung unveröffentlicht."
        )
    elif latest_panel:
        reference = release_reference(latest_panel, available[latest_panel], args.repository)
        release_status = f"Letzter veröffentlichter Panel-Release bis zum Stichtag: {reference}."
    else:
        release_status = "Bis zum Stichtag gibt es keinen veröffentlichten Panel-Release."

    selected = [entry for entry in entries if entry.day <= cutoff.date()]
    write(args.output, intro + "".join(entry.content for entry in selected))
    if args.summary:
        with open(args.summary, "a", encoding="utf-8") as handle:
            handle.write(f"## Changelog-Prüfung · {cutoff.date().isoformat()} (UTC)\n\n{release_status}\n\n")
            handle.write(f"{len(selected)} manuell gepflegte Abschnitte bis zum Stichtag.\n\n")
    if args.release_body:
        marker = f"easywi-channel: {channel}\n\n" if channel else ""
        link = "Vollständiger Changelog im Release-Anhang `CHANGELOG.md`."
        if args.tag and args.repository:
            link = f"[Vollständiger Changelog](https://github.com/{args.repository}/releases/download/{args.tag.replace('+', '%2B')}/CHANGELOG.md)"
        notes = next((entry.content.strip() for entry in selected if entry.version == args.tag), "")
        if len(notes.encode("utf-8")) > 100_000:
            notes = ""
        body = f"{notes}\n\n{link}" if notes else link
        write(args.release_body, f"{marker}{body}\n")
    if args.github_output:
        with open(args.github_output, "a", encoding="utf-8") as handle:
            handle.write(f"base_tag={base_tag or ''}\nbase_sha={base or ''}\nhead_sha={head}\nchannel={channel or ''}\n")


if __name__ == "__main__":
    main()
