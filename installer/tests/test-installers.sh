#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

bash -n "$repo_root/installer/linux-agent/install-agent.sh"
bash -n "$repo_root/installer/easywi-installer-menu-linux.sh"

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
