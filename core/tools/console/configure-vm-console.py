#!/usr/bin/env python3
"""Prepare the VM console without restarting services or changing any VM."""
import argparse
import ipaddress
import os
from pathlib import Path
import re
import secrets
import tempfile
from urllib.parse import urlsplit


def env_value(text, key):
    matches = re.findall(r'^(?:export\s+)?' + re.escape(key) + r'\s*=\s*(.*)$', text, re.M)
    if not matches:
        return None
    value = matches[-1].strip()
    if value[:1] in ('"', "'"):
        end = value.find(value[0], 1)
        if end < 0 or (value[end + 1:].strip() and not value[end + 1:].lstrip().startswith('#')):
            raise ValueError('Unsupported managed environment value: ' + key)
        return value[1:end]
    return re.split(r'\s+#', value, maxsplit=1)[0].rstrip()


def origin(value):
    if not value or re.search(r'[\s\x00-\x1f\x7f\"\'#$\\]', value):
        raise ValueError('A complete HTTPS panel origin is required, including the hostname.')
    parsed = urlsplit(value.rstrip('/'))
    try:
        local = ipaddress.ip_address(parsed.hostname or '').is_loopback
    except ValueError:
        local = False
    if not parsed.hostname or parsed.username is not None or parsed.password is not None or parsed.path or parsed.query or parsed.fragment or (parsed.scheme != 'https' and not (parsed.scheme == 'http' and local)):
        raise ValueError('Use an HTTPS origin without a path; HTTP is permitted only on loopback IPs.')
    host = parsed.hostname.lower()
    host = '[' + host + ']' if ':' in host else host
    port = parsed.port
    if port is not None and not 1 <= port <= 65535:
        raise ValueError('Invalid origin port.')
    return parsed.scheme + '://' + host + (':' + str(port) if port and port != (443 if parsed.scheme == 'https' else 80) else '')


def atomic(path, contents, mode, owner=None):
    if path.is_symlink():
        raise ValueError('Use your secret manager for symlinked configuration files.')
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temporary = tempfile.mkstemp(prefix='.vm-console-', dir=path.parent)
    try:
        with os.fdopen(fd, 'w') as handle:
            handle.write(contents)
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temporary, mode)
        if owner is not None:
            os.chown(temporary, owner.st_uid, owner.st_gid)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def managed_env(text, values):
    for key, value in values.items():
        text = re.sub(r'^(?:export\s+)?' + re.escape(key) + r'\s*=.*(?:\n|$)', '', text, flags=re.M)
        text = text.rstrip() + '\n' + key + '=' + value + '\n'
    return text


def nginx_mask(text):
    """Ignore braces in quoted strings/comments while locating server blocks."""
    result = list(text)
    quote = None
    comment = False
    escaped = False
    for index, char in enumerate(text):
        if comment:
            if char == '\n':
                comment = False
            else:
                result[index] = ' '
        elif escaped:
            result[index] = ' '
            escaped = False
        elif char == '\\':
            result[index] = ' '
            escaped = True
        elif quote:
            result[index] = ' ' if char != '\n' else '\n'
            if char == quote:
                quote = None
        elif char in ('"', "'"):
            quote = char
            result[index] = ' '
        elif char == '#':
            comment = True
            result[index] = ' '
    return ''.join(result)


def add_nginx_route(text, core, public_origin, snippet):
    mask = nginx_mask(text)
    candidates = []
    for match in re.finditer(r'\bserver\s*\{', mask):
        depth, end = 1, match.end()
        while end < len(mask) and depth:
            depth += (mask[end] == '{') - (mask[end] == '}')
            end += 1
        if depth:
            raise ValueError('Unbalanced Nginx server configuration.')
        body = text[match.end():end - 1]
        # Strip whole-line comments before inspecting literal roots/listeners.
        body = re.sub(r'^\s*#.*$', '', body, flags=re.M)
        roots = re.findall(r'\broot\s+([^;]+);', body)
        if any(Path(root.strip().strip('\"\'')).resolve() == (core / 'public').resolve() for root in roots):
            tls = bool(re.search(r'\blisten\s+[^;]*\bssl\b', body))
            candidates.append((match.end(), end - 1, body, tls))
    tls = [item for item in candidates if item[3]]
    if public_origin.startswith('https:') and tls:
        candidates = tls
    if len(candidates) != 1:
        raise ValueError('Expected one panel server block (or one TLS panel block); select the matching vhost explicitly.')
    start, end, body, _ = candidates[0]
    # An existing explicit route is operator-managed; never create a duplicate.
    if re.search(r'\blocation\s*=\s*[\"\']?/vm-console/ws[\"\']?\s*\{', body):
        raise ValueError('An explicit VM console location already exists; review it before using the managed include.')
    include_pattern = r'\binclude\s+[\"\']?' + re.escape(str(snippet)) + r'[\"\']?\s*;'
    if re.search(include_pattern, body):
        return text
    if re.search(r'[\x00-\x1f\x7f\"\'\\$;{}]', str(snippet)):
        raise ValueError('Unsupported Nginx snippet path.')
    return text[:end] + '    include "' + str(snippet) + '";\n' + text[end:]


def configure(args):
    core = Path(args.core).resolve()
    if not (core / 'bin/console').is_file():
        raise ValueError('Panel bin/console is missing.')
    local = core / '.env.local'
    compiled = core / '.env.local.php'
    system_root = Path(args.system_root)
    gateway = system_root / 'etc/easywi/vm-console.env'
    for path in (local, compiled, gateway):
        if path.is_symlink():
            raise ValueError('Use your secret manager for symlinked configuration files.')
    local_text = local.read_text() if local.exists() else ''
    base_text = (core / '.env').read_text() if (core / '.env').exists() else ''
    gateway_text = gateway.read_text() if gateway.exists() else ''
    public = origin(args.origin or env_value(local_text, 'DEFAULT_URI') or env_value(base_text, 'DEFAULT_URI'))
    internal = origin(args.panel_origin or env_value(gateway_text, 'VM_CONSOLE_PANEL_URL') or public)
    secret = env_value(local_text, 'VM_CONSOLE_GATEWAY_SECRET') or env_value(gateway_text, 'VM_CONSOLE_GATEWAY_SECRET') or secrets.token_hex(32)
    if not re.fullmatch(r'[A-Za-z0-9_-]{32,128}', secret):
        raise ValueError('The existing gateway secret must contain 32–128 URL-safe characters.')
    if not re.fullmatch(r'/[A-Za-z0-9_./-]+', args.agent_binary):
        raise ValueError('Unsupported agent binary path.')
    vhost = Path(args.vhost).resolve()
    config = vhost.read_text()
    snippet = system_root / 'etc/nginx/snippets/easywi-vm-console.conf'
    updated = add_nginx_route(config, core, public, snippet)
    templates = Path(__file__).resolve().parent
    service = (templates / 'vm-console.service.template').read_text().replace('/usr/local/bin/easywi-agent', args.agent_binary)
    nginx = (templates / 'vm-console.nginx.template').read_text()
    local_owner = local.stat() if local.exists() else core.stat()
    vhost_owner = vhost.stat()
    # All inputs/templates are checked before modifying configuration.
    backup = Path(str(vhost) + '.before-vm-console')
    if not backup.exists():
        atomic(backup, config, 0o600, vhost_owner)
    atomic(local, managed_env(local_text, {'VM_CONSOLE_GATEWAY_SECRET': secret}), (local_owner.st_mode & 0o660) | 0o600 if local.exists() else 0o640, local_owner)
    atomic(gateway, managed_env(gateway_text, {'VM_CONSOLE_GATEWAY_SECRET': secret, 'VM_CONSOLE_PUBLIC_ORIGIN': public, 'VM_CONSOLE_PANEL_URL': internal, 'VM_CONSOLE_LISTEN': '127.0.0.1:7457'}), 0o600)
    atomic(snippet, nginx, 0o644)
    atomic(system_root / 'etc/systemd/system/easywi-vm-console.service', service, 0o644)
    atomic(vhost, updated, vhost_owner.st_mode & 0o777, vhost_owner)
    print('VM console configuration prepared. No secret was printed; no service or VM was changed.')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--core', required=True)
    parser.add_argument('--origin')
    parser.add_argument('--panel-origin')
    parser.add_argument('--agent-binary', default='/usr/local/bin/easywi-agent')
    parser.add_argument('--vhost', required=True)
    parser.add_argument('--system-root', default='/')
    configure(parser.parse_args())


if __name__ == '__main__':
    main()
