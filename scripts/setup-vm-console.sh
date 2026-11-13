#!/usr/bin/env bash
# Configure the gateway on the panel host. Does not alter nginx vhosts or VMs.
set -euo pipefail
if [[ $(id -u) != 0 || $# -lt 2 || $# -gt 4 ]]; then
  echo "Usage (root): $0 /var/www/easywi/core https://panel.example.net [panel-internal-origin] [/usr/local/bin/easywi-agent]" >&2
  exit 1
fi
vm_panel_dir="${1%/}"
vm_public_origin="${2%/}"
vm_panel_origin="${3:-$vm_public_origin}"
vm_agent_binary="${4:-/usr/local/bin/easywi-agent}"
vm_script_root="$(cd "$(dirname "$0")/.." && pwd)"
[[ -f "$vm_panel_dir/bin/console" && -x "$vm_agent_binary" && "$vm_agent_binary" =~ ^/[a-zA-Z0-9_./-]+$ ]] || { echo 'Panel or updated agent binary missing.' >&2; exit 1; }
vm_binary_help="$("$vm_agent_binary" --help 2>&1 || true)"
[[ "$vm_binary_help" == *vm-console-gateway* ]] || { echo 'Update the agent binary first.' >&2; exit 1; }
install -d -m 0750 /etc/easywi
python3 - "$vm_panel_dir" "$vm_public_origin" "$vm_panel_origin" <<'PY'
import os,pathlib,re,secrets,sys,tempfile,urllib.parse,ipaddress,grp
panel,origin,internal=sys.argv[1:]
for value in [origin,internal]:
 u=urllib.parse.urlsplit(value)
 try: local=ipaddress.ip_address(u.hostname).is_loopback
 except ValueError: local=False
 if not u.hostname or u.username or u.password or u.path or u.query or u.fragment or (u.scheme!='https' and not(u.scheme=='http' and local)) or any(c.isspace() for c in value):raise SystemExit('Use HTTPS origins without paths (HTTP only on loopback).')
p=pathlib.Path(panel)/'.env.local'
if p.is_symlink():raise SystemExit('Configure a symlinked .env.local through your secret manager instead.')
contents=p.read_text() if p.exists() else ''
match=re.search(r'^VM_CONSOLE_GATEWAY_SECRET=(.*)$',contents,re.M)
secret=match[1].strip().strip('\'"') if match else secrets.token_hex(32)
if not re.fullmatch(r'[A-Za-z0-9_-]{32,128}',secret):raise SystemExit('Existing gateway secret must have 32-128 URL-safe characters.')
if not match:
 contents=contents.rstrip()+'\nVM_CONSOLE_GATEWAY_SECRET='+secret+'\n'
 old=p.stat() if p.exists() else None
 fd,tmp=tempfile.mkstemp(prefix='.console-env-',dir=panel)
 try:
  os.fchmod(fd,old.st_mode&0o777 if old else 0o640)
  os.fchown(fd,old.st_uid if old else 0,old.st_gid if old else grp.getgrnam('www-data').gr_gid)
  with os.fdopen(fd,'w') as f:f.write(contents);f.flush();os.fsync(f.fileno())
  os.replace(tmp,p)
 finally:
  if os.path.exists(tmp):os.unlink(tmp)
env=pathlib.Path('/etc/easywi/vm-console.env')
fd,tmp=tempfile.mkstemp(prefix='.vm-console-',dir=str(env.parent))
try:
 os.fchmod(fd,0o600)
 with os.fdopen(fd,'w') as f:
  f.write('VM_CONSOLE_GATEWAY_SECRET='+secret+'\nVM_CONSOLE_PUBLIC_ORIGIN='+origin+'\nVM_CONSOLE_PANEL_URL='+internal+'\nVM_CONSOLE_LISTEN=127.0.0.1:7457\n');f.flush();os.fsync(f.fileno())
 os.replace(tmp,env)
finally:
 if os.path.exists(tmp):os.unlink(tmp)
PY
sed "s#/usr/local/bin/easywi-agent#$vm_agent_binary#" "$vm_script_root/deploy/systemd/easywi-vm-console.service" > /etc/systemd/system/easywi-vm-console.service
install -d -m 0755 /etc/nginx/snippets
install -m 0644 "$vm_script_root/deploy/nginx/vm-console.conf" /etc/nginx/snippets/easywi-vm-console.conf
systemctl daemon-reload
systemctl enable easywi-vm-console.service
systemctl restart easywi-vm-console.service
printf '%s\n' 'Gateway configured. Include /etc/nginx/snippets/easywi-vm-console.conf inside the panel HTTPS server block, then validate and reload nginx.'
if [[ -f "$vm_panel_dir/.env.local.php" ]]; then
  printf '%s\n' 'Rebuild the compiled Symfony environment with composer dump-env prod before testing the console.'
fi
