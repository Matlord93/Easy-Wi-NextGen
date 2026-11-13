import argparse
import contextlib
import importlib.util
import io
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('console_config', Path(__file__).resolve().parents[1] / 'configure-live-console.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class ConsoleConfigTest(unittest.TestCase):
    def test_configuration_is_private_idempotent_and_preserves_settings(self):
        with tempfile.TemporaryDirectory() as temp:
            base = Path(temp)
            core = base / 'core'
            (core / 'public').mkdir(parents=True)
            (core / '.env').write_text('DEFAULT_URI=https://panel.example.test\nAPP_SECRET=existing-synthetic\n')
            (core / '.env.local').write_text('EXISTING_SETTING=preserved\n')
            vhost = base / 'panel.conf'
            vhost.write_text('server { listen 80; return 301 https://$host$request_uri; }\nserver {\n root ' + str(core / 'public') + ';\n location / { try_files $uri /index.php; }\n}\n')
            args = argparse.Namespace(core=str(core), vhost=str(vhost), php_fpm='/run/php/php8.5-fpm.sock', origin=None, resolver=['127.0.0.1'], ca_bundle='/etc/ssl/certs/ca-certificates.crt', fastcgi_params='/etc/nginx/fastcgi_params')
            output = io.StringIO()
            with contextlib.redirect_stdout(output):
                module.configure(args)
                first = vhost.read_text()
                env = (core / '.env.local').read_text()
                secret = module.env_value(env, 'CONSOLE_PROXY_SECRET')
                module.configure(args)
            self.assertEqual(vhost.read_text(), first)
            self.assertEqual(first.count('-live-console.inc'), 1)
            self.assertIn('EXISTING_SETTING=preserved', env)
            self.assertEqual(module.env_value((core / '.env.local').read_text(), 'CONSOLE_PROXY_SECRET'), secret)
            self.assertNotIn(secret, output.getvalue())
            snippet = next((base / 'easywi-snippets').iterdir())
            self.assertEqual(snippet.stat().st_mode & 0o777, 0o600)
            text = snippet.read_text()
            self.assertIn('internal;', text)
            self.assertIn('proxy_set_header Sec-WebSocket-Key', text)
            self.assertIn('proxy_ssl_verify on', text)
            before = vhost.read_text()
            args.origin = 'https://panel.example.test/not-an-origin'
            with self.assertRaises(ValueError):
                module.configure(args)
            self.assertEqual(vhost.read_text(), before)


if __name__ == '__main__':
    unittest.main()
