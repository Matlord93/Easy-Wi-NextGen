#!/usr/bin/env python3
"""Exercise installer orchestration with staged files and no host services."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest


class VmConsoleInstallerTest(unittest.TestCase):
    def test_gateway_is_configured_for_https_panel_only_or_combined_installs(self):
        self.run_case('https://panel.example.test:8443', agent_exists=True, expected_setup=True)

    def test_panel_only_host_gets_binary_without_registering_an_agent(self):
        self.run_case('https://panel.example.test', agent_exists=False, expected_setup=True)

    def test_public_http_does_not_enable_an_insecure_console(self):
        self.run_case('http://192.168.178.42', agent_exists=False, expected_setup=False)

    def run_case(self, origin, agent_exists, expected_setup):
        repository = Path(__file__).resolve().parents[2]
        source = (repository / 'installer/easywi-installer-menu-linux.sh').read_text()
        start = source.index('configure_panel_vm_console() {')
        end = source.index('\ninstall_panel() {', start)
        function = source[start:end]
        with tempfile.TemporaryDirectory(prefix='vm-console-installer-') as directory:
            root = Path(directory)
            core = root / 'core'
            (core / 'tools/console').mkdir(parents=True)
            setup = core / 'tools/console/setup-vm-console.sh'
            setup.write_text('#!/usr/bin/env bash\nprintf "%s\\n" "$@" > "$TEST_SETUP_LOG"\n')
            php = root / 'php'
            php.write_text('#!/bin/sh\nexit 0\n')
            php.chmod(0o755)
            agent = root / 'easywi-agent'
            if agent_exists:
                agent.write_text('#!/bin/sh\nexit 0\n')
                agent.chmod(0o755)
            script = root / 'test.sh'
            script.write_text('''#!/usr/bin/env bash
set -euo pipefail
has_systemctl() { return 0; }
detect_os_family() { echo debian; }
detect_nginx_conf_dir() { printf '%s' "$TEST_ROOT"; }
detect_arch_suffix() { echo linux-amd64; }
warn() { printf '%s\\n' "$*"; }
ok() { printf '%s\\n' "$*"; }
install_agent_binaries() {
  printf '%s\\n' "$@" > "$TEST_DOWNLOAD_LOG"
  printf '#!/bin/sh\\nexit 0\\n' > "$EASYWI_VM_CONSOLE_AGENT_BINARY"
  chmod 0755 "$EASYWI_VM_CONSOLE_AGENT_BINARY"
}
''' + function + '\nconfigure_panel_vm_console "$1" "$2" "test-release" "$3" "test-web-user"\n')
            env = os.environ | {'TEST_ROOT': str(root), 'TEST_SETUP_LOG': str(root / 'setup.log'), 'TEST_DOWNLOAD_LOG': str(root / 'download.log'), 'EASYWI_VM_CONSOLE_AGENT_BINARY': str(agent)}
            result = subprocess.run(['bash', str(script), str(core), origin, str(php)], env=env, capture_output=True, text=True)
            self.assertEqual(0, result.returncode, result.stderr)
            self.assertEqual(expected_setup, (root / 'setup.log').exists())
            if expected_setup:
                self.assertEqual([str(core), origin, '', str(agent), str(root / 'easywi.conf'), str(php)], (root / 'setup.log').read_text().splitlines())
                self.assertEqual(not agent_exists, (root / 'download.log').exists())
            else:
                self.assertFalse((root / 'download.log').exists())


if __name__ == '__main__':
    unittest.main()
