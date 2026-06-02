#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

bash -n "$repo_root/installer/linux-agent/install-agent.sh"
bash -n "$repo_root/installer/easywi-installer-menu-linux.sh"


python3 - "$repo_root/installer/easywi-installer-menu-linux.sh" <<'PY_PHP85_TESTS'
from pathlib import Path
import re
import sys

script = Path(sys.argv[1]).read_text()

def require(pattern, msg, flags=0):
    if not re.search(pattern, script, flags):
        raise SystemExit(msg)

require(r"ubuntu:resolute\) printf '8\.5'", 'Ubuntu 26.04/resolute must map to PHP 8.5')
require(r"ubuntu:26\.04\) printf '8\.5'", 'Ubuntu 26.04 VERSION_ID must map to PHP 8.5')
require(r"ubuntu:noble\)\s+printf '8\.3'", 'Ubuntu 24.04/noble native PHP mapping missing')
require(r'php_version_ge "\$\{native\}" "\$\{composer_min\}"', 'composer >= minimum must accept newer native PHP versions')
require(r'Nutze Ubuntu-PHP-Pakete \(PHP \$\{php_version\}\); Ondrej/PHP-PPA wird übersprungen\.', 'native Ubuntu PHP log missing')
require(r'critical=\(cli common mysql pgsql sqlite3 curl mbstring intl xml zip gd bcmath readline\)', 'critical PHP package list must not force OPcache as an apt package')
require(r'php\$\{php_version\}-\$\{module\}', 'versioned PHP packages must be preferred')

if 'apt_add_package_if_exists "php${php_version}-opcache"' in script:
    raise SystemExit('OPcache must not be added to the mandatory apt PHP package list')
critical_match = re.search(r'critical=\(([^)]*)\)', script)
if not critical_match or 'opcache' in critical_match.group(1).split():
    raise SystemExit('OPcache must not be a critical apt package')
require(r'apt_package_has_candidate', 'OPcache package fallback must check apt Candidate availability')
require(r'for pkg in "php\$\{php_version\}-opcache" php-opcache', 'OPcache fallback package order missing')
require(r'OPcache fehlt, aber kein separates OPcache-Paket ist für PHP \${php_version} verfügbar\.', 'OPcache missing failure message missing')
require(r'trap - ERR', 'run_or_fatal must disable ERR trap while capturing command output')
require(r'EASYWI_LAST_RUN_LOG', 'ERR trap must be able to show captured command output')
for pseudo in ['json', 'posix', 'fileinfo', 'dom', 'simplexml', 'tokenizer', 'ctype', 'iconv']:
    if re.search(r'php\$\{php_version\}-' + pseudo + r'|php-' + pseudo, script):
        raise SystemExit(f'pseudo/integrated module {pseudo} must not be forced as an apt package')
require(r'php -r \'exit\(extension_loaded\("Zend OPcache"\) \|\| extension_loaded\("opcache"\) \? 0 : 1\);\'', 'OPcache extension_loaded runtime check missing')
require(r"grep -iq 'opcache\.enable'", 'OPcache verification via php -i missing')
require(r'php-fpm\$\{php_version\}', 'versioned php-fpm check missing')
require(r'update-alternatives --display php', 'php update-alternatives diagnostic missing')
require(r'/etc/php/\$\{php_version\}/cli/php\.ini', 'versioned PHP CLI ini path missing')
require(r'systemctl restart "php\$\{php_version\}-fpm\.service"', 'versioned PHP-FPM restart missing')
require(r'tail -n 80', 'apt/run failure output must show last 80 lines')
require(r'PHP-Pakete installierbar:', 'installable package log missing')
require(r'PHP-Pakete optional nicht verfügbar:', 'optional-missing package log missing')
require(r'PHP-Pakete kritisch nicht verfügbar:', 'critical-missing package log missing')

require(r'run_composer\(\)', 'Composer wrapper missing')
require(r'COMPOSER_ALLOW_SUPERUSER=1', 'Composer wrapper must allow root non-interactively')
require(r'COMPOSER_NO_INTERACTION=1', 'Composer wrapper must disable interaction via environment')
require(r'composer --no-interaction "\$@" </dev/null', 'Composer wrapper must pass --no-interaction and close stdin')
require(r'run_composer install', 'composer install must use wrapper')
require(r'--no-progress', 'composer install must disable progress')
require(r'--prefer-dist', 'composer install must prefer dist packages')
require(r'composer_diagnose_status', 'composer diagnose summary helper missing')
require(r'Composer diagnose: OK', 'diagnostics must emit non-interactive composer OK summary')
require(r'Composer diagnose: WARN/FAILED, siehe Log', 'diagnostics must emit non-interactive composer warning summary')
require(r'Composer ist nicht installiert; composer diagnose übersprungen\.', 'missing composer diagnose warning missing')
if 'composer diagnose 2>&1' in script:
    raise SystemExit('direct composer diagnose call must not be used')
if 'COMPOSER_ALLOW_SUPERUSER=1 composer install' in script:
    raise SystemExit('direct composer install call must not be used')

# Ubuntu 26.04/PHP 8.5 installer-regression checks from current field logs.
require(r"php -r 'exit\(extension_loaded\(\"Zend OPcache\"\) \|\| extension_loaded\(\"opcache\"\) \? 0 : 1\);'", 'Zend OPcache extension_loaded runtime check must be used')
require(r"grep -Ei '\^\(Zend \)\?OPcache\$'", 'Zend OPcache php -m fallback must be accepted')
require(r'detect_agent_health_port', 'Agent health port detection helper missing')
require(r'agent_conf_value health_listen', 'Agent health_listen must be read from /etc/easywi/agent.conf')
require(r'agent_conf_value service_listen', 'Agent service_listen must be read from /etc/easywi/agent.conf')
require(r"ss -tulpn 2>/dev/null \| awk '/easywi-agent/", 'Agent port must be detectable from ss easywi-agent listener')
if re.search(r'local port="\$\{EASYWI_AGENT_HEALTH_PORT:-7443\}"', script):
    raise SystemExit('Agent healthcheck must not default hardcoded to 7443')
require(r'http_url="http://127\.0\.0\.1:\$\{port\}/health"', 'Agent healthcheck must test HTTP first on detected port')
require(r'Agent-Healthcheck OK: \$\{http_url\}', 'Agent HTTP success output missing')
require(r'Agent root: \$\{root:-unknown\}, file_api=\$\{file_api:-unknown\}', 'Agent root/file_api success output missing')
require(r'wrong version number.*HTTP-Port erwartet', 'HTTPS wrong-version-number must be classified as optional/non-fatal')
require(r'Agent läuft und Port \$\{port\} lauscht, aber /health antwortet nicht erfolgreich\.', 'Agent listener without /health must be WARN, not FAIL')
require(r'Apache: skipped because nginx profile active\.', 'Apache diagnostics must be skipped for nginx profile')
require(r'localhost_hosts_entry_configured "127\.0\.0\.1"', 'IPv4 localhost /etc/hosts direct check missing')
require(r'localhost_hosts_entry_configured "::1"', 'IPv6 localhost /etc/hosts direct check missing')
require(r'getent ahosts localhost', 'getent ahosts localhost must be supplemental')
require(r'fix_localhost_hosts_entries', '--fix must include localhost /etc/hosts remediation')
require(r'Dateilisting-Test für \$\{user\} übersprungen: Pfad ist leer\.', 'empty path listing test must warn and skip')
require(r"runuser -u \"\$\{user\}\" -- sh -c 'cd / && find", 'runuser find checks must start from /')
data_area_match = re.search(r'data_area_paths\(\) \{(?P<body>.*?)\n\}', script, re.S)
if not data_area_match:
    raise SystemExit('data_area_paths helper missing')
data_area_body = data_area_match.group('body')
if 'EASYWI_INSTANCE_BASE_DIR:-/home/easywi/instances' not in data_area_body or 'EASYWI_SFTP_BASE_DIR:-/srv/easywi/sftp' not in data_area_body:
    raise SystemExit('customer data paths must include instance and SFTP bases')
if '/var/www/easywi/core/var' in data_area_body:
    raise SystemExit('core/var must not be part of customer data_area_paths')
require(r'check_symfony_runtime_area', 'core/var must be handled as Symfony runtime area')
require(r'SELinux: not installed/not available\.', 'missing getenforce must be reported cleanly')
PY_PHP85_TESTS

if command -v php >/dev/null 2>&1; then
  php -l "$repo_root/core/src/Infrastructure/Security/SecretKeyLoader.php" >/dev/null
  php -l "$repo_root/core/src/Module/Core/Application/TokenGenerator.php" >/dev/null
  php -l "$repo_root/core/src/Module/Core/Command/AgentBootstrapTokenCreateCommand.php" >/dev/null
else
  echo "php not available; skipping PHP syntax checks"
fi

if [[ "${EUID}" -eq 0 ]]; then
  python3 - "$repo_root/installer/easywi-installer-menu-linux.sh" <<'PY_PROMPT_TEST'
import os
import select
import signal
import subprocess
import sys
import time

script = sys.argv[1]
master, slave = os.openpty()
proc = subprocess.Popen(
    ["bash", script],
    stdin=slave,
    stdout=slave,
    stderr=slave,
    preexec_fn=os.setsid,
)
os.close(slave)
output = b""
inputs = [
    (b"3\n", b"Auswahl [1-7]:"),
    (b"1\n", b"Auswahl [1-3]:"),
    (b"\n", b"DB-Root-Passwort"),
]
sent = 0
try:
    deadline = time.time() + 6
    while time.time() < deadline:
        readable, _, _ = select.select([master], [], [], 0.1)
        if readable:
            try:
                chunk = os.read(master, 4096)
            except OSError:
                break
            if not chunk:
                break
            output += chunk
        if sent < len(inputs) and inputs[sent][1] in output:
            os.write(master, inputs[sent][0])
            sent += 1
        if sent == len(inputs) and b"DB-Port" in output:
            break
finally:
    try:
        os.killpg(proc.pid, signal.SIGTERM)
    except ProcessLookupError:
        pass
    try:
        proc.wait(timeout=1)
    except subprocess.TimeoutExpired:
        os.killpg(proc.pid, signal.SIGKILL)
        proc.wait()
    os.close(master)

if b"DB-Port" not in output:
    sys.stderr.write(output.decode("utf-8", "replace"))
    raise SystemExit("installer exited before accepting an empty DB root password")
PY_PROMPT_TEST

  python3 - "$repo_root/installer/easywi-installer-menu-linux.sh" <<'PY_AGENT_PROMPT_TEST'
import os
import select
import signal
import subprocess
import sys
import time

script = sys.argv[1]
master, slave = os.openpty()
proc = subprocess.Popen(
    ["bash", script],
    stdin=slave,
    stdout=slave,
    stderr=slave,
    preexec_fn=os.setsid,
)
os.close(slave)
output = b""
try:
    deadline = time.time() + 8
    menu_sent = False
    prompt_replies = 0
    last_len = 0
    while time.time() < deadline:
        readable, _, _ = select.select([master], [], [], 0.1)
        if readable:
            try:
                chunk = os.read(master, 4096)
            except OSError:
                break
            if not chunk:
                break
            output += chunk
        if not menu_sent and b"Auswahl [1-7]:" in output:
            os.write(master, b"2\n")
            menu_sent = True
            last_len = len(output)
        elif menu_sent and len(output) != last_len and output.rstrip().endswith(b":") and prompt_replies < 8:
            os.write(master, b"\n")
            prompt_replies += 1
            last_len = len(output)
        if b"Wie oft soll der Agent neue Aufgaben" in output and b"Netzwerk-Traffic" in output:
            break
finally:
    try:
        os.killpg(proc.pid, signal.SIGTERM)
    except ProcessLookupError:
        pass
    try:
        proc.wait(timeout=1)
    except subprocess.TimeoutExpired:
        os.killpg(proc.pid, signal.SIGKILL)
        proc.wait()
    os.close(master)

if b"Wie oft soll der Agent neue Aufgaben" not in output or b"Netzwerk-Traffic" not in output:
    sys.stderr.write(output.decode("utf-8", "replace"))
    raise SystemExit("standalone agent prompt or traffic warning missing")
PY_AGENT_PROMPT_TEST
else
  echo "not root; skipping interactive installer prompt regression check"
fi


rg -q 'cleanup_old_easywi_agent_processes' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'expected_config="/etc/easywi/agent.conf"' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'ensure_agent_service_interval_environment' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'easywi-agent.service.d/intervals.conf' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'systemctl stop easywi-agent.service' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'systemctl start easywi-agent.service' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'EASYWI_AGENT_JOB_POLL_INTERVAL_SECONDS=\$\{poll_interval_seconds\}' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'poll_interval=\$\{poll_interval_seconds\}s' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'Wie oft soll der Agent neue Aufgaben beim Panel abfragen\? Sekunden' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'validate_agent_interval_seconds "\$\{poll_interval_seconds\}" "Agent-Polling-Intervall" 2 300' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'niedriges Intervall den Netzwerk-Traffic' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'EASYWI_AGENT_JOB_POLL_INTERVAL_SECONDS:-2' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'verify_checksum' "$repo_root/installer/linux-agent/install-agent.sh"
rg -q 'PROXY_URL' "$repo_root/installer/linux-agent/install-agent.sh"
rg -q 'LOG_PATH' "$repo_root/installer/linux-agent/install-agent.sh"
rg -q 'local status=\$?' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'backup_conflicting_ondrej_php_sources' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -Fq 'ppa\.launchpad(content)?\.net/ondrej/php' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'Signed-By-Konflikt' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'install -d -o "\$\{runtime_user\}" -g "\$\{readable_group\}" -m 2775 /etc/easywi' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'set_easywi_config_permissions "\$\{system_user\}" "\$\{web_group\}"' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'ReadWritePaths=/etc/easywi' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'configure_php_fpm_easywi_sandbox "\$\{PHP_FPM_SERVICE\}"' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'resolve_latest_release_with_assets' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'list_agent_release_options' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'find_extracted_agent_binary' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'prepare_agent_binary_asset' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'Bitte wählen Sie den gewünschten Agent-Kanal' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'Aktuelle Dev-Version' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'Aktuelle Beta-Version' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'Aktuelle Stable-Version' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -Fq 'releases?per_page=50' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q "GitHub-'latest'-Release enthält kein Agent-Binary" "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'try_install_cpu_tools' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'linux-cpupower' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'kernel-tools' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'try_install_cpu_tools "\$\{manager\}" cpupower' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'try_install_cpu_tools "\$\{manager\}" cpufrequtils' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'scaling_min_freq' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'intel_idle.max_cstate=1' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'RETRIES="\$\{2:-45\}"' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'verify_performance' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'disable_cpu_power_conflicts' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'power-profiles-daemon.service' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'After=multi-user.target systemd-modules-load.service' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'easywi-cpu-governor.timer' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'OnBootSec=30sec' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'OnUnitActiveSec=5min' "$repo_root/installer/easywi-installer-menu-linux.sh"
rg -q 'PanelAgent' "$repo_root/installer/easywi-installer-menu-windows.ps1"
rg -q 'RunComposerInstall' "$repo_root/installer/easywi-installer-menu-windows.ps1"

if command -v pwsh >/dev/null 2>&1; then
  pwsh -NoProfile -Command "[void][System.Management.Automation.Language.Parser]::ParseFile('$repo_root/installer/windows-agent/install-service.ps1',[ref]\$null,[ref]\$null)"
  pwsh -NoProfile -Command "[void][System.Management.Automation.Language.Parser]::ParseFile('$repo_root/installer/windows-agent/uninstall-service.ps1',[ref]\$null,[ref]\$null)"
  pwsh -NoProfile -Command "[void][System.Management.Automation.Language.Parser]::ParseFile('$repo_root/installer/windows-agent/install-agent.ps1',[ref]\$null,[ref]\$null)"
  pwsh -NoProfile -Command "[void][System.Management.Automation.Language.Parser]::ParseFile('$repo_root/installer/easywi-installer-menu-windows.ps1',[ref]\$null,[ref]\$null)"

  pwsh -NoProfile -Command "if (-not (Select-String -Path '$repo_root/installer/windows-agent/install-agent.ps1' -Pattern 'Assert-Checksum' -Quiet)) { exit 1 }"
  pwsh -NoProfile -Command "if (-not (Select-String -Path '$repo_root/installer/windows-agent/install-agent.ps1' -Pattern 'Resolve-EffectiveProxy' -Quiet)) { exit 1 }"
  pwsh -NoProfile -Command "if (-not (Select-String -Path '$repo_root/installer/windows-agent/install-agent.ps1' -Pattern 'LogPath' -Quiet)) { exit 1 }"
else
  echo "pwsh not available; skipping PowerShell parse checks"
fi

printf 'installer script checks passed\n'
