"""Exercise hand-written CI changelogs and release packaging."""

import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
import zipfile


ROOT = Path(__file__).resolve().parents[2]


class ChangelogTest(unittest.TestCase):
    def setUp(self):
        workspace = tempfile.TemporaryDirectory()
        self.addCleanup(workspace.cleanup)
        self.root = Path(workspace.name)
        self.repository = self.root / "repository"
        self.repository.mkdir()
        self.git("init", "--initial-branch=main", "--quiet")
        self.git("config", "user.email", "test@example.test")
        self.git("config", "user.name", "CI Test")
        self.releases = []
        self.source = self.repository / "CHANGELOG.md"
        self.source.write_text("# Changelog\n\n## 2026-01-01 · Unveröffentlicht\n\n- Erster redaktioneller Eintrag.\n", encoding="utf-8")
        self.git("add", "CHANGELOG.md")
        self.base = self.commit("VERSION", "Initial revision", timestamp="2026-01-01T12:00:00Z")

    def git(self, *args, timestamp=None):
        env = os.environ | {"GIT_AUTHOR_DATE": timestamp, "GIT_COMMITTER_DATE": timestamp} if timestamp else None
        return subprocess.check_output(["git", *args], cwd=self.repository, text=True, env=env).strip()

    def commit(self, filename, subject, *, body="", timestamp=None):
        file = self.repository / filename
        file.parent.mkdir(parents=True, exist_ok=True)
        file.write_text(subject, encoding="utf-8")
        self.git("add", "--", filename)
        args = ["commit", "--quiet", "-m", subject]
        if body:
            args.extend(["-m", body])
        self.git(*args, timestamp=timestamp)
        return self.git("rev-parse", "HEAD")

    def publish(self, tag, published_at, *, draft=False, complete=True):
        names = (f"easywi-webinterface-{tag[1:]}.zip", "checksums-core.txt", "manifest.json") if complete else ()
        assets = [{"name": name, "state": "uploaded"} for name in names]
        self.releases.append({"tag_name": tag, "published_at": published_at, "draft": draft, "assets": assets})

    def run_script(self, name, *args, check=True, env=None):
        return subprocess.run(
            [sys.executable, str(ROOT / "scripts" / name), *args],
            cwd=self.repository, check=check, capture_output=True, text=True, env=env,
        )

    def changelog(self, *args):
        full = self.root / "CHANGELOG.md"
        metadata = self.root / "outputs"
        releases = self.root / "releases.json"
        releases.write_text(json.dumps(self.releases), encoding="utf-8")
        self.run_script(
            "ci-changelog.py", "--repository", "owner/repo", "--output", str(full),
            "--github-output", str(metadata), "--releases-file", str(releases), *args,
        )
        return full.read_text(encoding="utf-8"), dict(line.split("=", 1) for line in metadata.read_text().splitlines())

    def test_ci_copies_manual_file_without_using_commit_subjects(self):
        self.commit("agent/main.go", "A Git commit subject")
        self.publish("v1.0.0", "2026-01-01T10:00:00Z")
        summary = self.root / "summary.md"
        report, _ = self.changelog("--date", "2026-01-01", "--summary", str(summary))
        self.assertEqual(self.source.read_text(), report)
        self.assertNotIn("A Git commit subject", report)
        self.assertIn("/releases/tag/v1.0.0) vom 2026-01-01", summary.read_text())

    def test_historical_artifact_only_contains_sections_up_to_the_requested_day(self):
        self.source.write_text(
            "# Changelog\n\n"
            "## 2026-01-03 · Unveröffentlicht\n\n- Späterer Eintrag.\n\n"
            "## 2026-01-02 · Unveröffentlicht\n\n### Shop\n\n- Vertrag direkt öffnen.\n",
            encoding="utf-8",
        )
        self.publish("v1.0.0", "2026-01-02T23:59:59Z")
        self.publish("v1.1.0", "2026-01-03T00:00:00Z")
        self.publish("v1.2.0", "2026-01-02T23:59:59Z", draft=True)
        original = self.source.read_bytes()
        summary = self.root / "summary.md"
        report, _ = self.changelog("--date", "2026-01-02", "--summary", str(summary))
        self.assertEqual(
            "# Changelog\n\n## 2026-01-02 · Unveröffentlicht\n\n### Shop\n\n- Vertrag direkt öffnen.\n", report,
        )
        self.assertEqual(original, self.source.read_bytes())
        self.assertIn("/releases/tag/v1.0.0) vom 2026-01-02", summary.read_text())
        self.assertNotIn("v1.1.0", summary.read_text())
        self.assertNotIn("v1.2.0", summary.read_text())

    def test_release_notes_copy_only_the_manually_assigned_section(self):
        self.source.write_text(
            "# Changelog\n\n"
            "## 2026-01-03 · Unveröffentlicht\n\n- Weitere Arbeiten.\n\n"
            "## 2026-01-02 · v1.1.0-beta.1\n\n### Panel\n\n- Paketprüfung verbessert.\n",
            encoding="utf-8",
        )
        self.git("tag", "v1.0.0")
        self.publish("v1.0.0", "2026-01-01T12:30:00Z")
        self.commit("core/example.php", "Fallback content must never come from Git")
        self.git("tag", "agent-v1.0.0")
        self.publish("agent-v1.0.0", "2026-01-02T12:30:00Z")
        body = self.root / "release-notes.md"
        report, meta = self.changelog("--tag", "v1.1.0-beta.1", "--release-body", str(body))
        self.assertEqual(self.source.read_text(), report)
        self.assertEqual("v1.0.0", meta["base_tag"])
        self.assertEqual(self.base, meta["base_sha"])
        notes = body.read_text()
        self.assertTrue(notes.startswith("easywi-channel: beta\n\n"))
        self.assertIn("### Panel\n\n- Paketprüfung verbessert.", notes)
        self.assertNotIn("Weitere Arbeiten.", notes)
        self.assertNotIn("Fallback content", notes)
        self.assertIn("/releases/download/v1.1.0-beta.1/CHANGELOG.md", notes)

    def test_publication_date_limits_release_artifact_and_delta_base(self):
        self.git("tag", "v1.0.0-beta.1")
        self.publish("v1.0.0-beta.1", "2026-01-02T12:00:00Z")
        self.commit("core/older", "Earlier commit, later release")
        self.git("tag", "v1.0.0-beta.2")
        self.publish("v1.0.0-beta.2", "2026-01-04T12:00:00Z")
        self.commit("core/untagged", "Only Git tag")
        self.git("tag", "v1.0.0-beta.3")
        self.publish("v1.0.0-beta.3", "2026-01-02T15:00:00Z", complete=False)
        target = self.commit("core/current", "Target")
        self.git("tag", "v1.0.0-beta.4")
        self.publish("v1.0.0-beta.4", "2026-01-03T12:00:00Z")
        self.source.write_text(
            "# Changelog\n\n"
            "## 2026-01-04 · Unveröffentlicht\n\n- Änderungen nach der Veröffentlichung.\n\n"
            "## 2026-01-03 · v1.0.0-beta.4\n\n- Überarbeitete Veröffentlichung.\n",
            encoding="utf-8",
        )
        summary = self.root / "summary.md"
        report, meta = self.changelog("--tag", "v1.0.0-beta.4", "--summary", str(summary))
        self.assertEqual("v1.0.0-beta.1", meta["base_tag"])
        self.assertEqual(target, meta["head_sha"])
        self.assertNotIn("Änderungen nach der Veröffentlichung.", report)
        self.assertIn("vom 2026-01-03 wurde veröffentlicht.", summary.read_text())

    def test_missing_version_section_uses_a_link_to_the_manual_file(self):
        body = self.root / "release-notes.md"
        self.changelog("--tag", "agent-v1.0.0", "--release-body", str(body))
        notes = body.read_text()
        self.assertIn("/releases/download/agent-v1.0.0/CHANGELOG.md", notes)
        self.assertNotIn("Erster redaktioneller Eintrag.", notes)

    def test_invalid_or_unsorted_dates_fail_validation(self):
        headings = (
            "## 2026-02-30 · Unveröffentlicht",
            "## 2026-01-01 · Unveröffentlicht\n\n## 2026-01-02 · Unveröffentlicht",
            "## 2026-01-02 · v1.0.0\n\n## 2026-01-01 · v1.0.0",
        )
        for section in headings:
            with self.subTest(section=section):
                self.source.write_text(f"# Changelog\n\n{section}\n", encoding="utf-8")
                result = self.run_script("ci-changelog.py", check=False)
                self.assertNotEqual(0, result.returncode)
                self.assertIn("Could not read the manual changelog", result.stderr)
                self.assertFalse((self.repository / "dist/CHANGELOG.md").exists())

    def test_output_cannot_overwrite_the_source(self):
        original = self.source.read_bytes()
        result = self.run_script("ci-changelog.py", "--summary", str(self.source), check=False)
        self.assertNotEqual(0, result.returncode)
        self.assertEqual(original, self.source.read_bytes())

    def test_failed_release_lookup_does_not_generate_a_false_result(self):
        gh = self.root / "gh"
        gh.write_text("#!/bin/sh\nexit 1\n", encoding="utf-8")
        gh.chmod(0o755)
        result = self.run_script(
            "ci-changelog.py", "--check-releases", "--repository", "owner/repo", check=False,
            env=os.environ | {"PATH": f"{self.root}:{os.environ['PATH']}"},
        )
        self.assertNotEqual(0, result.returncode)
        self.assertIn("Could not verify published releases", result.stderr)
        self.assertFalse((self.repository / "dist/CHANGELOG.md").exists())

    def test_existing_tag_cannot_publish_a_different_checkout(self):
        self.git("tag", "v1.0.0")
        self.commit("core/file.php", "Different checkout")
        result = self.run_script("ci-changelog.py", "--tag", "v1.0.0", check=False)
        self.assertNotEqual(0, result.returncode)
        self.assertIn("different commit", result.stderr)

    def test_panel_bundle_and_delta_match_the_published_manifest(self):
        for helper in ("prepare-minecraft-catalog.sh", "build-webinterface-delta.py"):
            path = self.repository / "scripts" / helper
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes((ROOT / "scripts" / helper).read_bytes())
        required = (
            "core/.env", "core/tools/console/configure-live-console.py",
            "core/tools/console/live-console.conf.template", "core/public/js/gameserver/console-live.js",
        )
        catalogs = ("minecraft-server.json", "paper-versions.json", "bedrock-server.json")
        for path in required:
            self.commit(path, "Fixture " + path)
        for name in catalogs:
            self.commit("minecraft/" + name, "Source " + name)
        self.commit("core/.env.local.php", "Excluded fixture")
        self.git("tag", "v1.0.0")
        before = self.git("rev-parse", "HEAD")
        self.commit("minecraft/paper-versions.json", "Updated Paper catalogue")

        dist = self.root / "release"
        dist.mkdir()
        (dist / "easywi-core-1.1.0.tar.gz").write_bytes(b"simulated packaged Core build")
        notes = self.root / "notes.md"
        notes.write_text("Release change notes")
        subprocess.run(
            ["bash", str(ROOT / "scripts/build-panel-release.sh"), "v1.1.0", "v1.0.0", before, str(dist), str(notes)],
            cwd=self.repository, check=True, capture_output=True, text=True,
            env={"PATH": os.environ["PATH"], "GITHUB_REPOSITORY": "owner/repo"},
        )
        manifest = json.loads((dist / "manifest.json").read_text())
        self.assertEqual("1.1.0", manifest["latest"])
        self.assertEqual("Release change notes", manifest["notes"])
        expected = {
            "manifest.json": "easywi-webinterface-1.1.0.zip",
            "manifest-core.json": "easywi-core-1.1.0.tar.gz",
        }
        for name, archive in expected.items():
            payload = json.loads((dist / name).read_text())
            self.assertTrue(payload["asset_url"].endswith("/v1.1.0/" + archive))
            self.assertEqual(hashlib.sha256((dist / archive).read_bytes()).hexdigest(), payload["sha256"])

        full = dist / expected["manifest.json"]
        delta = dist / "easywi-webinterface-update-1.0.0-to-1.1.0.zip"
        self.assertEqual(hashlib.sha256(delta.read_bytes()).hexdigest(), manifest["delta"]["sha256"])
        with zipfile.ZipFile(full) as bundle, zipfile.ZipFile(delta) as update:
            self.assertTrue(all(path in bundle.namelist() for path in required))
            self.assertNotIn("core/.env.local.php", bundle.namelist())
            self.assertEqual(b"1.1.0\n", bundle.read("VERSION"))
            self.assertEqual(bundle.read("VERSION"), update.read("VERSION"))
            for name in catalogs:
                path = "core/resources/minecraft/" + name
                self.assertEqual(bundle.read(path), update.read(path))
            self.assertEqual(b"Updated Paper catalogue", update.read("core/resources/minecraft/paper-versions.json"))

    def test_delta_uses_untagged_head_and_removes_the_old_renamed_path(self):
        original = "core/old name.php"
        renamed = "core/new\tname.php"
        before = self.commit(original, "Original tracked file")
        (self.repository / original).rename(self.repository / renamed)
        self.git("add", "--all")
        self.git("commit", "--quiet", "-m", "Rename tracked file")
        dist = self.root / "delta"
        env = self.root / "environment"
        self.run_script(
            "build-webinterface-delta.py", "--base", before, "--head", "HEAD", "--version", "1.1.0",
            "--previous-version", "1.0.0", "--dist", str(dist), "--github-env", str(env),
        )
        metadata = dict(line.split("=", 1) for line in env.read_text().splitlines())
        archive = dist / metadata["DELTA_ARCHIVE"]
        with zipfile.ZipFile(archive) as bundle:
            self.assertCountEqual(["VERSION", renamed], bundle.namelist())
            self.assertEqual(b"1.1.0\n", bundle.read("VERSION"))
            self.assertEqual(b"Original tracked file", bundle.read(renamed))
        self.assertEqual([original], json.loads((dist / "delta-delete.json").read_text()))
        self.assertEqual(hashlib.sha256(archive.read_bytes()).hexdigest(), metadata["DELTA_SHA256"])


if __name__ == "__main__":
    unittest.main()
