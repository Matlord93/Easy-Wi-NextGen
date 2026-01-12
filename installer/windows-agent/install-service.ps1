param(
    [string]$ServiceName = 'easywi-agent',
    [string]$AgentPath = 'C:\easywi\agent\easywi-agent.exe',
    [string]$ConfigPath = 'C:\ProgramData\easywi\agent.conf',
    [string]$LogDir = 'C:\ProgramData\easywi\logs',
    [string]$NssmPath = ''
)

$ErrorActionPreference = 'Stop'

function Resolve-NssmPath {
    if (-not [string]::IsNullOrWhiteSpace($NssmPath)) {
        return $NssmPath
    }
    $command = Get-Command nssm.exe -ErrorAction SilentlyContinue
    if ($null -ne $command) {
        return $command.Path
    }
    return $null
}

function Ensure-Directory {
    param([string]$Path)
    if (-not (Test-Path -Path $Path)) {
        New-Item -ItemType Directory -Path $Path -Force | Out-Null
    }
}

function Remove-ServiceIfExists {
    param([string]$Name)
    $service = Get-Service -Name $Name -ErrorAction SilentlyContinue
    if ($null -ne $service) {
        Stop-Service -Name $Name -Force -ErrorAction SilentlyContinue
        sc.exe delete $Name | Out-Null
        Start-Sleep -Seconds 1
    }
}

Ensure-Directory -Path $LogDir
$stdoutLog = Join-Path $LogDir 'easywi-agent.out.log'
$stderrLog = Join-Path $LogDir 'easywi-agent.err.log'

$nssm = Resolve-NssmPath
Remove-ServiceIfExists -Name $ServiceName

if ($null -ne $nssm) {
    & $nssm install $ServiceName $AgentPath '--config' $ConfigPath | Out-Null
    & $nssm set $ServiceName AppStdout $stdoutLog | Out-Null
    & $nssm set $ServiceName AppStderr $stderrLog | Out-Null
    & $nssm set $ServiceName AppRotateFiles 1 | Out-Null
    & $nssm set $ServiceName AppRotateSeconds 86400 | Out-Null
    & $nssm set $ServiceName Start SERVICE_AUTO_START | Out-Null
} else {
    $binPath = "cmd /c `"`"$AgentPath`" --config `"$ConfigPath`" >> `"$stdoutLog`" 2>> `"$stderrLog`"`""
    sc.exe create $ServiceName binPath= $binPath start= auto | Out-Null
}

Start-Service -Name $ServiceName
Write-Host "[easywi-agent] Service installed: $ServiceName"
