# Windows Agent (Stage 1 & 2)

## Overview
Stage 1 enables a minimal Windows control-plane agent:
- Heartbeats include `os=windows` and minimal capabilities.
- Job polling is restricted to safe jobs only (`agent.self_update`).
- Windows nodes are disabled by default via `APP_WINDOWS_NODES_ENABLED=0`.

Stage 2 adds Windows service wrapper scripts plus a safer update swap.

## Enable Windows Nodes
Set the feature flag in the Core environment and reload PHP-FPM/web server:
```env
APP_WINDOWS_NODES_ENABLED=1
```

## Install as a Windows Service
The agent binary should live in a persistent directory (example: `C:\easywi\agent\easywi-agent.exe`).
The default config path is `C:\ProgramData\easywi\agent.conf`.

### Option A: NSSM (recommended)
```powershell
.\installer\windows-agent\install-service.ps1 `
  -AgentPath "C:\easywi\agent\easywi-agent.exe" `
  -ConfigPath "C:\ProgramData\easywi\agent.conf" `
  -LogDir "C:\ProgramData\easywi\logs"
```

### Option B: sc.exe (built-in)
```powershell
.\installer\windows-agent\install-service.ps1 `
  -AgentPath "C:\easywi\agent\easywi-agent.exe" `
  -ConfigPath "C:\ProgramData\easywi\agent.conf" `
  -LogDir "C:\ProgramData\easywi\logs" `
  -NssmPath ""
```

### Uninstall
```powershell
.\installer\windows-agent\uninstall-service.ps1 -ServiceName "easywi-agent"
```

### Logging Path
Log files are written to `C:\ProgramData\easywi\logs`:
- `easywi-agent.out.log`
- `easywi-agent.err.log`

## Update Safety (Windows)
Updates download a new executable, verify checksums, and then swap atomically:
1. Current binary is renamed to `.bak`.
2. New binary is renamed into place.
3. On failure, the `.bak` binary is restored.

The Windows updater runs in a helper PowerShell process after the agent exits, ensuring the running binary can be replaced safely.

## Manual Test Steps
1. Enable `APP_WINDOWS_NODES_ENABLED=1` on Core and restart services.
2. Create a bootstrap token in **Infrastructure → Bootstrap tokens**.
3. On the Windows node, register the agent via bootstrap flow (see `docs/agent-bootstrap.md`).
4. Install the service using `install-service.ps1`.
5. Verify the node heartbeats and reports `os=windows`.
6. Queue an agent update from the **Nodes** page and confirm the service restarts with the new binary.
