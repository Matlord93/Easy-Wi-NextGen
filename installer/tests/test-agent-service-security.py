#!/usr/bin/env python3
"""Run isolated installer helpers, without installing services or changing /etc."""
from pathlib import Path
import hashlib
import hmac
import json
import os
import re
import subprocess
import tempfile
import unittest

SOURCE = (Path(__file__).resolve().parents[1] / 'easywi-installer-menu-linux.sh').read_text()


def function(name):
    start = re.search(r'^' + re.escape(name) + r'\(\).*$', SOURCE, re.M)
    if not start:
        raise AssertionError(f'Missing installer helper: {name}')
    if start.group().rstrip().endswith('}'):
        return start.group() + '\n'
    end = re.search(r'^}', SOURCE[start.end():], re.M)
    return SOURCE[start.start(): start.end() + end.end()] + '\n'


class AgentServiceSecurityTest(unittest.TestCase):
    def test_listener_default_and_explicit_private_interface(self):
        for listen in ['', '10.1.2.3:7456']:
            with self.subTest(listen=listen), tempfile.TemporaryDirectory() as temp:
                config_dir = Path(temp) / 'easywi'
                script = 'set -euo pipefail\n' + function('write_agent_conf').replace('/etc/easywi', str(config_dir))
                script += '\nfunction build_agent_base_dir_config() { printf "/home\\n/home,/var/www\\n"; }\nfunction ok() { :; }\n'
                script += 'write_agent_conf node synthetic-secret https://panel.example.test /home /srv/sftp "" 15 30 10 "$1"\n'
                subprocess.run(['bash', '-c', script, 'test', listen], check=True, capture_output=True)
                config = (config_dir / 'agent.conf').read_text()
                self.assertIn('service_listen=' + (listen or '127.0.0.1:7456') + '\n', config)
                self.assertEqual((config_dir / 'agent.conf').stat().st_mode & 0o777, 0o600)

    def test_local_service_url_is_covered_by_registration_signature(self):
        with tempfile.TemporaryDirectory() as temp:
            script = 'set -euo pipefail\n'
            for name in ['sha256_hex', 'hmac_sha256_hex', 'build_sig_payload', 'complete_agent_registration']:
                script += function(name)
            script += r'''
step() { :; }
random_hex() { printf 'synthetic-nonce'; }
fatal() { printf '%s' "$*" >&2; exit 1; }
curl() {
    while (($#)); do
        case "$1" in
          -d) printf '%s' "$2" > "$SECURITY_TEST_DIR/body"; shift ;;
          -H)
            case "$2" in
              'X-Signature: '*) printf '%s' "${2#X-Signature: }" > "$SECURITY_TEST_DIR/signature" ;;
              'X-Timestamp: '*) printf '%s' "${2#X-Timestamp: }" > "$SECURITY_TEST_DIR/timestamp" ;;
            esac
            shift ;;
        esac
        shift
    done
    printf '{"secret":"synthetic-node-secret"}\n201'
}
complete_agent_registration https://panel.example.test/api/v1/agent/register register-secret node node-name http://127.0.0.1:7456
'''
            result = subprocess.run(['bash', '-c', script], check=True, capture_output=True, text=True, env={**os.environ, 'SECURITY_TEST_DIR': temp})
            self.assertEqual(result.stdout, 'synthetic-node-secret')
            body = (Path(temp) / 'body').read_text()
            self.assertEqual(json.loads(body)['service_base_url'], 'http://127.0.0.1:7456')
            payload = '\n'.join(['node', 'POST', '/api/v1/agent/register', hashlib.sha256(body.encode()).hexdigest(), (Path(temp) / 'timestamp').read_text(), 'synthetic-nonce'])
            self.assertEqual((Path(temp) / 'signature').read_text(), hmac.new(b'register-secret', payload.encode(), hashlib.sha256).hexdigest())


if __name__ == '__main__':
    unittest.main()
