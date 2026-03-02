#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

bash -n "$repo_root/installer/linux-agent/install-agent.sh"
bash -n "$repo_root/installer/easywi-installer-menu-linux.sh"

rg -q 'verify_checksum' "$repo_root/installer/linux-agent/install-agent.sh"
rg -q 'PROXY_URL' "$repo_root/installer/linux-agent/install-agent.sh"
rg -q 'LOG_PATH' "$repo_root/installer/linux-agent/install-agent.sh"

if command -v pwsh >/dev/null 2>&1; then
  pwsh -NoProfile -Command "[void][System.Management.Automation.Language.Parser]::ParseFile('$repo_root/installer/windows-agent/install-service.ps1',[ref]\$null,[ref]\$null)"
  pwsh -NoProfile -Command "[void][System.Management.Automation.Language.Parser]::ParseFile('$repo_root/installer/windows-agent/uninstall-service.ps1',[ref]\$null,[ref]\$null)"
  pwsh -NoProfile -Command "[void][System.Management.Automation.Language.Parser]::ParseFile('$repo_root/installer/windows-agent/install-agent.ps1',[ref]\$null,[ref]\$null)"

  pwsh -NoProfile -Command "if (-not (Select-String -Path '$repo_root/installer/windows-agent/install-agent.ps1' -Pattern 'Assert-Checksum' -Quiet)) { exit 1 }"
  pwsh -NoProfile -Command "if (-not (Select-String -Path '$repo_root/installer/windows-agent/install-agent.ps1' -Pattern 'Resolve-EffectiveProxy' -Quiet)) { exit 1 }"
  pwsh -NoProfile -Command "if (-not (Select-String -Path '$repo_root/installer/windows-agent/install-agent.ps1' -Pattern 'LogPath' -Quiet)) { exit 1 }"
else
  echo "pwsh not available; skipping PowerShell parse checks"
fi

printf 'installer script checks passed\n'
