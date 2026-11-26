"""Check that recovery only appends verified assets to the published release."""

import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest


SCRIPT = Path(__file__).resolve().parents[1] / "complete-published-release.py"
TAG = "v1.0.0-dev.20261126.2"


class PublishedReleaseTest(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        root = Path(temp.name)
        self.staged = root / "staged"
        self.staged.mkdir()
        self.notes = root / "notes.md"
        self.notes.write_text("## Manually written notes\n", encoding="utf-8")
        self.state = root / "state.json"
        self.events = root / "events.jsonl"
        self.release = {"tag_name": TAG, "draft": False, "assets": [], "body": "Full Changelog: comparison link"}
        for name in ("CHANGELOG.md", "manifest.json", "checksums-core.txt", "panel.zip", "checksums-core.txt.asc"):
            (self.staged / name).write_text(name, encoding="utf-8")
        gh = root / "gh"
        gh.write_text("""#!/usr/bin/env python3
import hashlib
import json
import os
from pathlib import Path
import sys

state = Path(os.environ['FIXTURE_STATE'])
release = json.loads(state.read_text())
args = sys.argv[1:]
if args[0] == 'api':
    print(json.dumps(release))
elif args[:2] == ['release', 'view']:
    if release.get('missing'):
        sys.exit('release not found')
    print(json.dumps({'isDraft': release['draft'], 'assets': release['assets']}))
else:
    if '--clobber' in args or '--draft' in args:
        sys.exit('Attempt to overwrite an asset or change publication state')
    if args[:2] == ['release', 'upload']:
        path = Path(args[3])
        if path.name == os.getenv('FIXTURE_FAIL_ASSET'):
            sys.exit('Simulated upload failure')
        release['assets'].append({'name': path.name, 'state': 'uploaded', 'digest': 'sha256:' + hashlib.sha256(path.read_bytes()).hexdigest()})
        event = path.name
    elif args[:2] == ['release', 'edit']:
        release['body'] = Path(args[args.index('--notes-file') + 1]).read_text()
        event = 'notes'
    else:
        sys.exit('Unexpected gh command')
    state.write_text(json.dumps(release))
    with open(os.environ['FIXTURE_EVENTS'], 'a') as handle:
        handle.write(json.dumps(event) + '\\n')
""", encoding="utf-8")
        gh.chmod(0o755)
        self.env = os.environ | {
            "PATH": f"{root}:{os.environ['PATH']}", "GITHUB_REPOSITORY": "owner/repo",
            "FIXTURE_STATE": str(self.state), "FIXTURE_EVENTS": str(self.events),
        }

    def run_command(self, *args, **env):
        return subprocess.run([sys.executable, SCRIPT, *args], env=self.env | env, capture_output=True, text=True)

    def test_initial_upload_and_retry_keep_matching_existing_files(self):
        self.state.write_text(json.dumps(self.release))
        result = self.run_command("upload", TAG, self.staged, self.notes, FIXTURE_FAIL_ASSET="checksums-core.txt")
        self.assertNotEqual(0, result.returncode)
        self.assertEqual("with-assets", self.run_command("state", TAG).stdout.strip())
        result = self.run_command("upload", TAG, self.staged, self.notes)
        self.assertEqual(0, result.returncode, result.stderr)
        result = self.run_command("upload", TAG, self.staged, self.notes)
        self.assertEqual(0, result.returncode, result.stderr)
        events = [json.loads(line) for line in self.events.read_text().splitlines()]
        self.assertEqual(["CHANGELOG.md", "checksums-core.txt.asc", "panel.zip", "checksums-core.txt", "manifest.json", "notes"], events)
        self.assertEqual("## Manually written notes\n\nFull Changelog: comparison link\n", json.loads(self.state.read_text())["body"])

    def test_different_or_unrelated_assets_block_all_mutations(self):
        for name in ("panel.zip", "custom.zip"):
            with self.subTest(name=name):
                self.release["assets"] = [{"name": name, "state": "uploaded", "digest": "sha256:" + hashlib.sha256(b"other bytes").hexdigest()}]
                self.state.write_text(json.dumps(self.release))
                result = self.run_command("upload", TAG, self.staged, self.notes)
                self.assertNotEqual(0, result.returncode)
                self.assertFalse(self.events.exists())

    def test_empty_draft_and_absent_are_distinct(self):
        for expected, update in [("empty", {}), ("draft", {"draft": True}), ("new", {"missing": True})]:
            self.state.write_text(json.dumps(self.release | update))
            result = self.run_command("state", TAG)
            self.assertEqual(0, result.returncode, result.stderr)
            self.assertEqual(expected, result.stdout.strip())
