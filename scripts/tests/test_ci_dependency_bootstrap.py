"""Exercise the actual dependency bootstrap with controlled Go download failures."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class GoDependencyBootstrapTest(unittest.TestCase):
    def run_bootstrap(self, failures=0, verify_exit=0):
        with tempfile.TemporaryDirectory(prefix="easywi-go-bootstrap-") as temporary:
            root = Path(temporary)
            (root / "agent").mkdir()
            (root / "scripts").mkdir()
            binaries = root / "bin"
            binaries.mkdir()
            script = root / "scripts/ci-dependency-bootstrap.sh"
            shutil.copyfile(ROOT / "scripts/ci-dependency-bootstrap.sh", script)
            stub = '''#!/usr/bin/env python3
import json, os, pathlib, sys
name = pathlib.Path(sys.argv[0]).name
args = sys.argv[1:]
with open(os.environ['BOOTSTRAP_CALLS'], 'a') as log:
    log.write(json.dumps({'name': name, 'args': args, 'cwd': pathlib.Path.cwd().name,
                         'proxy': os.environ.get('GOPROXY'),
                         'sumdb': os.environ.get('GOSUMDB')}) + '\\n')
if name == 'go' and args == ['mod', 'download']:
    counter = pathlib.Path(os.environ['BOOTSTRAP_COUNT'])
    count = int(counter.read_text()) + 1 if counter.exists() else 1
    counter.write_text(str(count))
    if count <= int(os.environ['BOOTSTRAP_FAILURES']):
        print('module zip: stream error: INTERNAL_ERROR; received from peer', file=sys.stderr)
        sys.exit(56)
if name == 'go' and args == ['mod', 'verify']:
    sys.exit(int(os.environ['BOOTSTRAP_VERIFY_EXIT']))
'''
            for name in ['go', 'curl', 'sleep']:
                executable = binaries / name
                executable.write_text(stub, encoding="utf-8")
                executable.chmod(0o755)
            environment = os.environ.copy()
            environment.update({
                "PATH": str(binaries) + os.pathsep + environment["PATH"],
                "BOOTSTRAP_CALLS": str(root / "calls.jsonl"),
                "BOOTSTRAP_COUNT": str(root / "count"),
                "BOOTSTRAP_FAILURES": str(failures),
                "BOOTSTRAP_VERIFY_EXIT": str(verify_exit),
                "EASYWI_GOPROXY_MIRROR": "https://approved-mirror.example.test",
                "GOSUMDB": "sum.golang.org",
            })
            result = subprocess.run(["bash", str(script), "go"], cwd=root, env=environment,
                                    text=True, capture_output=True, timeout=15)
            calls = [json.loads(line) for line in (root / "calls.jsonl").read_text().splitlines()]
            return result, calls

    @staticmethod
    def command_calls(calls, arguments):
        return [call for call in calls if call["name"] == "go" and call["args"] == arguments]

    def test_success_does_not_retry_and_verifies_modules(self):
        result, calls = self.run_bootstrap()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(len(self.command_calls(calls, ['mod', 'download'])), 1)
        self.assertEqual(len(self.command_calls(calls, ['mod', 'verify'])), 1)
        self.assertFalse(any(call["name"] == "sleep" for call in calls))
        download = self.command_calls(calls, ['mod', 'download'])[0]
        self.assertEqual(download["cwd"], "agent")
        self.assertEqual(download["proxy"], "https://approved-mirror.example.test")
        self.assertEqual(download["sumdb"], "sum.golang.org")

    def test_transient_stream_errors_retry_then_verify(self):
        for failures in [1, 2]:
            with self.subTest(failures=failures):
                result, calls = self.run_bootstrap(failures=failures)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(len(self.command_calls(calls, ['mod', 'download'])), failures + 1)
                self.assertEqual(len(self.command_calls(calls, ['mod', 'verify'])), 1)
                self.assertEqual([call["args"] for call in calls if call["name"] == "sleep"],
                                 [[str(attempt * 2)] for attempt in range(1, failures + 1)])
                self.assertIn("INTERNAL_ERROR", result.stderr)

    def test_permanent_download_failure_stops_after_three_attempts(self):
        result, calls = self.run_bootstrap(failures=20)
        self.assertEqual(result.returncode, 56, result.stderr)
        self.assertEqual(len(self.command_calls(calls, ['mod', 'download'])), 3)
        self.assertEqual(len(self.command_calls(calls, ['mod', 'verify'])), 0)
        self.assertEqual([call["args"] for call in calls if call["name"] == "sleep"], [['2'], ['4']])
        self.assertIn("Go module download failed", result.stderr)

    def test_integrity_verification_failure_is_not_retried_or_ignored(self):
        result, calls = self.run_bootstrap(verify_exit=9)
        self.assertEqual(result.returncode, 9, result.stderr)
        self.assertEqual(len(self.command_calls(calls, ['mod', 'download'])), 1)
        self.assertEqual(len(self.command_calls(calls, ['mod', 'verify'])), 1)
        self.assertFalse(any(call["name"] == "sleep" for call in calls))


if __name__ == "__main__":
    unittest.main()
