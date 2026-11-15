"""Exercise installer origin selection without running package/service setup."""
from pathlib import Path
import os
import re
import subprocess
import tempfile
import unittest

SCRIPT = (Path(__file__).parents[1] / 'easywi-installer-menu-linux.sh').read_text()
PROMPT = re.search(r'^prompt_value\(\) \{.*?^\}', SCRIPT, re.M | re.S).group()
UPDATE = re.search(r'^update_default_uri\(\) \{.*?^\}', SCRIPT, re.M | re.S).group()
START = SCRIPT.index('  local suggested_host=')
END = SCRIPT.index('\nPY_PANEL_ORIGIN', START) + len('\nPY_PANEL_ORIGIN')
SELECT = SCRIPT[START:END]


class PanelOriginTest(unittest.TestCase):
    def select(self, host='_', detected='192.168.178.46', override='', interactive=False):
        shell = '\n'.join([
            'set -euo pipefail', PROMPT,
            'fatal() { echo "$*" >&2; exit 1; }',
            'detect_primary_ipv4() { printf "%s" "$TEST_IP"; }',
            'is_tty() { test "$TEST_TTY" = yes; }',
            'read_from_tty() { printf "%s" "$TEST_ANSWER"; }',
            'choose() { local web_hostname="$TEST_HOST" web_scheme=http',
            SELECT, 'printf "%s" "$default_uri"', '}', 'choose',
        ])
        return subprocess.run(['bash', '-c', shell], text=True, capture_output=True,
                              env={**os.environ, 'TEST_HOST': host, 'TEST_IP': detected,
                                   'EASYWI_PANEL_ORIGIN': override,
                                   'TEST_TTY': 'yes' if interactive else 'no',
                                   'TEST_ANSWER': 'https://panel.example.test:8443/'})

    def test_wildcard_uses_server_ip(self):
        result = self.select()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout, 'http://192.168.178.46')

    def test_domain_and_explicit_override(self):
        self.assertEqual(self.select(host='panel.example.test').stdout, 'http://panel.example.test')
        self.assertEqual(self.select(override='https://panel.example.test:8443/').stdout,
                         'https://panel.example.test:8443')
        self.assertEqual(self.select(interactive=True).stdout, 'https://panel.example.test:8443')

    def test_missing_ip_and_invalid_origins_fail(self):
        self.assertNotEqual(self.select(detected='').returncode, 0)
        for value in ['http://_', 'https://panel.example.test/path', 'https://user:pass@panel.test',
                      'https://panel.test?x=1', 'https://panel.test:invalid', 'http://panel.test\nX=1']:
            with self.subTest(value=value):
                self.assertNotEqual(self.select(override=value).returncode, 0)

    def test_ssl_update_replaces_duplicate_origins_and_preserves_secret(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / '.env.local'
            path.write_text('DEFAULT_URI=http://localhost\nCONSOLE_PUBLIC_ORIGIN=http://localhost\nDEFAULT_URI=http://old\nCONSOLE_PROXY_SECRET=synthetic-secret\n')
            subprocess.run(['bash', '-eu', '-c', UPDATE + '\nupdate_default_uri "$1" "$2"',
                            'test', str(path), 'https://panel.example.test'], check=True)
            self.assertEqual(path.read_text(), 'CONSOLE_PROXY_SECRET=synthetic-secret\nDEFAULT_URI=https://panel.example.test\nCONSOLE_PUBLIC_ORIGIN=https://panel.example.test\n')


if __name__ == '__main__':
    unittest.main()
