import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest


VALIDATOR = Path(__file__).resolve().parents[1] / 'ci-agent-test-report.py'


class AgentTestReportTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='easywi-report-test-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.events = [
            {'Action': 'start', 'Package': 'agent'},
            {'Action': 'run', 'Package': 'agent', 'Test': 'TestExample'},
            {'Action': 'pass', 'Package': 'agent', 'Test': 'TestExample'},
            {'Action': 'pass', 'Package': 'agent'},
        ]

    def check_report(self, events, packages=None, text=None):
        manifest = {'packages': packages or ['agent'], 'required_test_packages': ['agent'], 'reports': ['events.jsonl']}
        path = self.root / 'manifest.json'
        path.write_text(json.dumps(manifest), encoding='utf-8')
        report = self.root / 'events.jsonl'
        report.write_text(text if text is not None else ''.join(json.dumps(x) + '\n' for x in events), encoding='utf-8')
        result = subprocess.run([sys.executable, str(VALIDATOR), str(path)], capture_output=True, text=True)
        summary = json.loads((self.root / 'summary.json').read_text(encoding='utf-8'))
        return result.returncode, summary

    def test_complete_success_passes(self):
        code, summary = self.check_report(self.events)
        self.assertEqual(code, 0)
        self.assertEqual(summary['passed'], 1)
        self.assertEqual(summary['errors'], [])

    def test_directory_without_test_files_does_not_hide_a_skipped_test_case(self):
        events = self.events + [{'Action': 'start', 'Package': 'empty'}, {'Action': 'skip', 'Package': 'empty'}]
        code, summary = self.check_report(events, ['agent', 'empty'])
        self.assertEqual(code, 0)
        self.assertEqual(summary['completed_packages'], 2)
        self.assertEqual(summary['skipped'], 0)

    def test_skipped_or_failed_test_fails_the_check(self):
        for action in ['skip', 'fail']:
            with self.subTest(action=action):
                events = [{**x, 'Action': action} if x.get('Test') and x['Action'] == 'pass' else x for x in self.events]
                code, summary = self.check_report(events)
                self.assertEqual(code, 1)
                self.assertTrue(summary['errors'])

    def test_incomplete_test_or_package_fails_the_check(self):
        for position in [1, 2, 3]:
            with self.subTest(missing_event=position):
                code, summary = self.check_report([x for i, x in enumerate(self.events) if i != position])
                self.assertEqual(code, 1)
                self.assertTrue(summary['errors'])

    def test_missing_package_fails_the_check(self):
        code, summary = self.check_report(self.events, ['agent', 'missing'])
        self.assertEqual(code, 1)
        self.assertIn('Package did not complete: missing', summary['errors'])

    def test_required_package_with_zero_test_cases_fails_the_check(self):
        code, summary = self.check_report([self.events[0], self.events[-1]])
        self.assertEqual(code, 1)
        self.assertEqual(summary['passed'], 0)

    def test_empty_or_corrupt_report_fails_the_check(self):
        for text in ['', '{broken json\n', '[]\n']:
            with self.subTest(report=text):
                code, summary = self.check_report([], text=text)
                self.assertEqual(code, 1)
                self.assertTrue(summary['errors'])

    def test_unexpected_package_fails_the_check(self):
        code, summary = self.check_report(self.events + [{'Action': 'pass', 'Package': 'unexpected'}])
        self.assertEqual(code, 1)
        self.assertTrue(any('Unexpected package' in item for item in summary['errors']))


if __name__ == '__main__':
    unittest.main()
