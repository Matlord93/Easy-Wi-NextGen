import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest


SCRIPT = Path(__file__).resolve().parents[1] / "normalize-psalm-sarif.php"


class NormalizePsalmSarifTest(unittest.TestCase):
    def normalize(self, contents):
        with tempfile.TemporaryDirectory() as directory:
            report = Path(directory) / "results.sarif"
            report.write_text(contents)
            result = subprocess.run(
                [os.environ.get("PHP_BINARY", "php"), str(SCRIPT), str(report)],
                capture_output=True,
                text=True,
            )
            return result, report.read_text()

    def test_preserves_every_finding_and_valid_rule_metadata(self):
        rules = [
            {"id": "PluginIssue", "helpUri": "", "shortDescription": {"text": "Plugin"}},
            {"id": "OtherIssue", "helpUri": "  "},
            {"id": "TaintedHtml", "helpUri": "https://psalm.dev/245"},
        ]
        findings = [{"ruleId": "TaintedHtml", "message": {"text": "Unsafe output"}}]
        report = {
            "version": "2.1.0",
            "runs": [{"tool": {"driver": {"rules": rules}}, "results": findings}],
        }
        result, contents = self.normalize(json.dumps(report))
        self.assertEqual(result.returncode, 0, result.stderr)
        del rules[0]["helpUri"]
        del rules[1]["helpUri"]
        self.assertEqual(json.loads(contents), report)

    def test_missing_optional_metadata_remains_missing(self):
        report = {"runs": [{"tool": {"driver": {"name": "Psalm"}}, "results": []}]}
        result, contents = self.normalize(json.dumps(report))
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(contents), report)

    def test_malformed_report_fails_without_overwriting_evidence(self):
        for contents in ["{malformed", '{"version": "2.1.0"}']:
            with self.subTest(contents=contents):
                result, after = self.normalize(contents)
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(after, contents)


if __name__ == "__main__":
    unittest.main()
