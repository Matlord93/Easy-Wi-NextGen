#!/usr/bin/env python3
import argparse
import contextlib
import importlib.util
import io
import os
from pathlib import Path
import tempfile
import unittest
import sys

sys.dont_write_bytecode = True

spec = importlib.util.spec_from_file_location('vm_console_config', Path(__file__).with_name('configure-vm-console.py'))
helper = importlib.util.module_from_spec(spec)
spec.loader.exec_module(helper)


class VmConsoleSetupTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='easywi-vm-setup-')
        self.root = Path(self.temp.name)
        self.core = self.root / 'core'
        (self.core / 'bin').mkdir(parents=True)
        (self.core / 'public').mkdir()
        (self.core / 'bin/console').write_text('<?php\n')
        (self.core / '.env.local').write_text('APP_SECRET=preserve-application-secret\nDEFAULT_URI="https://panel.example.test"\n')
        (self.core / '.env.local').chmod(0o640)
        self.vhost = self.root / 'easywi.conf'
        self.vhost.write_text(self.server(80))
        self.args = argparse.Namespace(core=str(self.core), origin=None, panel_origin=None, agent_binary='/usr/local/bin/easywi-agent', vhost=str(self.vhost), system_root=str(self.root / 'system'))

    def tearDown(self):
        self.temp.cleanup()

    def server(self, port):
        tls = ' ssl' if port == 443 else ''
        return 'server {\n listen ' + str(port) + tls + ';\n server_name panel.example.test;\n root "' + str(self.core / 'public') + '";\n location / { try_files $uri /index.php; }\n}\n'

    def configure(self):
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            helper.configure(self.args)
        return output.getvalue()

    def test_secret_proxy_and_service_are_idempotent_and_preserve_other_values(self):
        first = self.configure()
        local = (self.core / '.env.local').read_text()
        secret = helper.env_value(local, 'VM_CONSOLE_GATEWAY_SECRET')
        self.assertEqual(64, len(secret))
        gateway = self.root / 'system/etc/easywi/vm-console.env'
        self.assertEqual(secret, helper.env_value(gateway.read_text(), 'VM_CONSOLE_GATEWAY_SECRET'))
        self.assertEqual(0o600, gateway.stat().st_mode & 0o777)
        self.assertNotIn(secret, first)
        self.assertIn('APP_SECRET=preserve-application-secret', local)
        config = self.vhost.read_text()
        self.configure()
        self.assertEqual(local, (self.core / '.env.local').read_text())
        self.assertEqual(config, self.vhost.read_text())
        self.assertEqual(1, config.count('easywi-vm-console.conf'))
        self.assertIn('DynamicUser=yes', (self.root / 'system/etc/systemd/system/easywi-vm-console.service').read_text())
        self.assertIn('proxy_set_header Sec-WebSocket-Protocol', (self.root / 'system/etc/nginx/snippets/easywi-vm-console.conf').read_text())

    def test_existing_gateway_secret_ca_and_internal_origin_are_preserved(self):
        gateway = self.root / 'system/etc/easywi/vm-console.env'
        gateway.parent.mkdir(parents=True)
        secret = 'existing-secret-' + 'a' * 32
        gateway.write_text('VM_CONSOLE_GATEWAY_SECRET=' + secret + '\nVM_CONSOLE_PANEL_URL=http://127.0.0.1\nVM_CONSOLE_CA_FILE=/etc/private/ca.pem\n')
        self.configure()
        self.assertEqual(secret, helper.env_value((self.core / '.env.local').read_text(), 'VM_CONSOLE_GATEWAY_SECRET'))
        self.assertIn('VM_CONSOLE_CA_FILE=/etc/private/ca.pem', gateway.read_text())
        self.assertEqual('http://127.0.0.1', helper.env_value(gateway.read_text(), 'VM_CONSOLE_PANEL_URL'))

    def test_tls_server_is_selected_without_changing_http_redirect_block(self):
        http = self.server(80)
        self.vhost.write_text(http + self.server(443))
        self.configure()
        self.assertTrue(self.vhost.read_text().startswith(http))
        self.assertEqual(1, self.vhost.read_text().count('easywi-vm-console.conf'))

    def test_comments_and_quoted_braces_do_not_change_server_selection(self):
        self.vhost.write_text('# server { fake }\n' + self.server(443).replace(' location /', ' set $test "} quoted {";\n location /'))
        self.configure()
        self.assertEqual(1, self.vhost.read_text().count('easywi-vm-console.conf'))

    def test_unquoted_existing_include_is_not_duplicated(self):
        snippet = self.root / 'system/etc/nginx/snippets/easywi-vm-console.conf'
        self.vhost.write_text(self.server(80).replace(' location /', ' include ' + str(snippet) + ';\n location /'))
        self.configure()
        self.assertEqual(1, self.vhost.read_text().count('easywi-vm-console.conf'))

    def test_invalid_inputs_do_not_partially_write_configuration(self):
        original = (self.core / '.env.local').read_bytes()
        config = self.vhost.read_bytes()
        for value in ('https://', 'http://192.168.178.42', 'https://user:password@panel.test', 'https://panel.test/path', 'https://panel.test?secret=x'):
            self.args.origin = value
            with self.assertRaises(ValueError):
                self.configure()
            self.assertEqual(original, (self.core / '.env.local').read_bytes())
            self.assertEqual(config, self.vhost.read_bytes())
            self.assertFalse((self.root / 'system').exists())

    def test_ambiguous_vhost_is_not_modified(self):
        config = self.server(443) + self.server(443)
        self.vhost.write_text(config)
        with self.assertRaises(ValueError):
            self.configure()
        self.assertEqual(config, self.vhost.read_text())
        self.assertFalse((self.root / 'system').exists())

    def test_secret_manager_symlinks_are_preserved(self):
        secret_file = self.root / 'managed-env'
        secret_file.write_text('managed')
        local = self.core / '.env.local'
        local.unlink()
        local.symlink_to(secret_file)
        with self.assertRaises(ValueError):
            self.configure()
        self.assertTrue(local.is_symlink())
        self.assertEqual('managed', secret_file.read_text())

    def test_compiled_environment_is_left_for_the_standard_dump_env_step(self):
        compiled = self.core / '.env.local.php'
        compiled.write_text('<?php return ["APP_ENV" => "prod", "OTHER" => "preserve"];')
        self.configure()
        self.assertEqual('<?php return ["APP_ENV" => "prod", "OTHER" => "preserve"];', compiled.read_text())

    def test_default_ports_and_ipv6_origins_are_canonical(self):
        self.assertEqual('https://panel.example.test', helper.origin('https://PANEL.example.test:443/'))
        self.assertEqual('http://[::1]', helper.origin('http://[::1]:80'))
        self.assertEqual('https://panel.example.test:8443', helper.origin('https://panel.example.test:8443'))


if __name__ == '__main__':
    unittest.main()
