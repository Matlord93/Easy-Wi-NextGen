#!/usr/bin/env python3
"""Configure the existing panel Nginx/PHP and agent path; create no daemon.

Does not reload services, stop games or disable a legacy relay. Run nginx -t
and reload after reviewing the generated change. Secrets are never printed.
"""
import argparse
import ipaddress
import os
from pathlib import Path
import re
import secrets
import shutil
import tempfile
from urllib.parse import urlsplit


def env_value(text, key):
    match = re.search(r'^' + re.escape(key) + r'=(.*)$', text, re.M)
    return match.group(1).strip().strip('\"\'') if match else None


def atomic(path, text, mode, owner=None):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, name = tempfile.mkstemp(dir=path.parent)
    try:
        with os.fdopen(fd, 'w') as file:
            file.write(text)
            file.flush()
            os.fsync(file.fileno())
        os.chmod(name, mode)
        if owner is not None and hasattr(os, 'chown'):
            os.chown(name, owner.st_uid, owner.st_gid)
        os.replace(name, path)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def nginx_quote(value):
    if any(c in value for c in '\n\r\x00$;{}"\\'):
        raise ValueError('Unsupported Nginx value')
    return '"' + value + '"'


def configure(args):
    core = Path(args.core).absolute()
    vhost = Path(args.vhost).resolve()
    env_file = core / '.env.local'
    base = core / '.env'
    combined = base.read_text() if base.exists() else ''
    local = env_file.read_text() if env_file.exists() else ''
    origin = args.origin or env_value(local, 'CONSOLE_PUBLIC_ORIGIN') or env_value(combined, 'DEFAULT_URI')
    origin = (origin or '').rstrip('/')
    if re.search(r'[\s\x00-\x1f\x7f\"\'#$]', origin):
        raise ValueError('Invalid public panel origin')
    parsed = urlsplit(origin)
    if parsed.scheme not in ('http', 'https') or not parsed.hostname or parsed.username or parsed.password or parsed.query or parsed.fragment or parsed.path:
        raise ValueError('An exact public panel origin is required, e.g. https://panel.example.test (no trailing slash)')
    secret = env_value(local, 'CONSOLE_PROXY_SECRET') or env_value(combined, 'CONSOLE_PROXY_SECRET') or secrets.token_hex(32)
    if not re.fullmatch(r'[a-zA-Z0-9_-]{32,128}', secret):
        raise ValueError('Existing CONSOLE_PROXY_SECRET must use 32–128 letters, digits, underscores or hyphens')
    resolvers = args.resolver
    if not resolvers:
        resolvers = re.findall(r'^nameserver\s+(\S+)', Path('/etc/resolv.conf').read_text(), re.M)
    addresses = [ipaddress.ip_address(value) for value in resolvers]
    if not addresses:
        raise ValueError('DNS resolver required')
    resolver = ' '.join('[' + str(ip) + ']' if ip.version == 6 else str(ip) for ip in addresses)
    fpm = args.php_fpm
    if fpm.startswith('/'):
        fpm = 'unix:' + fpm
    elif not re.fullmatch(r'(?:127\.0\.0\.1|\[::1\]):[0-9]{1,5}', fpm):
        raise ValueError('PHP-FPM must be a local Unix socket or loopback TCP address')
    ca = args.ca_bundle or next((p for p in ['/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt', '/etc/ssl/cert.pem'] if Path(p).is_file()), None)
    if not ca:
        raise ValueError('TLS CA bundle required')
    template = Path(__file__).resolve().with_name('live-console.conf.template')
    values = {'RESOLVERS': resolver, 'FASTCGI_PARAMS': nginx_quote(args.fastcgi_params), 'PHP_FPM': nginx_quote(fpm), 'CA_BUNDLE': nginx_quote(ca), 'FRONT_CONTROLLER': nginx_quote(str(core / 'public/index.php')), 'PROXY_SECRET': nginx_quote(secret)}
    snippet = template.read_text()
    for key, value in values.items():
        snippet = snippet.replace('@' + key + '@', value)
    include = vhost.parent / 'easywi-snippets' / (vhost.stem + '-live-console.inc')
    config = vhost.read_text()
    directive = 'include ' + nginx_quote(str(include)) + ';'
    if directive not in config:
        # Choose exactly the server whose document root belongs to this panel.
        # Abort on ambiguous/missing matches, rather than modify a redirect vhost.
        blocks = []
        for match in re.finditer(r'\bserver\s*\{', config):
            depth, end = 1, match.end()
            while end < len(config) and depth:
                depth += (config[end] == '{') - (config[end] == '}')
                end += 1
            if depth:
                raise ValueError('Unbalanced Nginx server block')
            block = config[match.end():end - 1]
            roots = re.findall(r'^\s*root\s+([^;]+);', block, re.M)
            if any(Path(root.strip().strip('\"\'')).resolve() == (core / 'public').resolve() for root in roots):
                blocks.append(end - 1)
        if len(blocks) != 1:
            raise ValueError('Expected exactly one server with this panel document root')
        end = blocks[0]
        config = config[:end] + '    ' + directive + '\n' + config[end:]
    new_local = local.rstrip() + '\n'
    for key, value in {'CONSOLE_PROXY_SECRET': secret, 'CONSOLE_PUBLIC_ORIGIN': origin}.items():
        new_local = re.sub(r'^' + key + r'=.*\n?', '', new_local, flags=re.M)
        new_local += key + '=' + value + '\n'
    # Validate all inputs before writing any file. Preserve original ownership.
    owner = (env_file if env_file.exists() else base).stat()
    vhost_owner = vhost.stat()
    backup = Path(str(vhost) + '.before-live-console')
    if not backup.exists():
        shutil.copy2(vhost, backup)
    atomic(env_file, new_local, 0o640, owner)
    atomic(include, snippet, 0o600)
    atomic(vhost, config, vhost_owner.st_mode & 0o777, vhost_owner)
    print('Live console configured in ' + str(include) + '. Run nginx -t, clear the production Symfony cache, then reload Nginx. No service was stopped.')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--core', required=True)
    parser.add_argument('--vhost', required=True)
    parser.add_argument('--php-fpm', required=True)
    parser.add_argument('--origin')
    parser.add_argument('--resolver', action='append', default=[])
    parser.add_argument('--ca-bundle')
    parser.add_argument('--fastcgi-params', default='/etc/nginx/fastcgi_params')
    configure(parser.parse_args())


if __name__ == '__main__':
    main()
