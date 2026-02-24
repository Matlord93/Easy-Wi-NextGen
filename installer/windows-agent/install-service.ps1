param(
    [string]$ServiceName = 'easywi-agent',
    [string]$AgentPath = 'C:\easywi\agent\easywi-agent.exe',
    [string]$SftpServiceName = 'EasyWI-SFTP',
    [string]$SftpPath = 'C:\easywi\agent\easywi-sftp.exe',
    [string]$SftpConfigPath = 'C:\ProgramData\EasyWI\sftp\config.json',
    [string]$ConfigPath = 'C:\ProgramData\easywi\agent.conf',
    [string]$LogDir = 'C:\ProgramData\easywi\logs',
    [string]$NssmPath = '',
    [string]$InstanceBaseDir = 'C:\home',
    [string]$SftpBaseDir = 'C:\ProgramData\EasyWI\sftp'
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

function Set-ServiceEnvironment {
    param(
        [string]$NssmExe,
        [string]$Name,
        [string]$EnvironmentValue
    )

    if ($null -ne $NssmExe) {
        & $NssmExe set $Name AppEnvironmentExtra $EnvironmentValue | Out-Null
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
Ensure-Directory -Path $SftpBaseDir
$stdoutLog = Join-Path $LogDir 'easywi-agent.out.log'
$stderrLog = Join-Path $LogDir 'easywi-agent.err.log'

[Environment]::SetEnvironmentVariable('EASYWI_INSTANCE_BASE_DIR', $InstanceBaseDir, 'Machine')
[Environment]::SetEnvironmentVariable('EASYWI_SFTP_BASE_DIR', $SftpBaseDir, 'Machine')
$agentEnvironment = "EASYWI_INSTANCE_BASE_DIR=$InstanceBaseDir`nEASYWI_SFTP_BASE_DIR=$SftpBaseDir"

$nssm = Resolve-NssmPath
Remove-ServiceIfExists -Name $ServiceName

if ($null -ne $nssm) {
    & $nssm install $ServiceName $AgentPath '--config' $ConfigPath | Out-Null
    & $nssm set $ServiceName AppStdout $stdoutLog | Out-Null
    & $nssm set $ServiceName AppStderr $stderrLog | Out-Null
    & $nssm set $ServiceName AppRotateFiles 1 | Out-Null
    & $nssm set $ServiceName AppRotateSeconds 86400 | Out-Null
    & $nssm set $ServiceName Start SERVICE_AUTO_START | Out-Null
    Set-ServiceEnvironment -NssmExe $nssm -Name $ServiceName -EnvironmentValue $agentEnvironment
} else {
    $binPath = "cmd /c `"`"$AgentPath`" --config `"$ConfigPath`" >> `"$stdoutLog`" 2>> `"$stderrLog`"`""
    sc.exe create $ServiceName binPath= $binPath start= auto | Out-Null
}

Start-Service -Name $ServiceName
Write-Host "[easywi-agent] Service installed: $ServiceName"

if (Test-Path -Path $SftpPath) {
    Ensure-Directory -Path ([System.IO.Path]::GetDirectoryName($SftpConfigPath))
    if (-not (Test-Path -Path $SftpConfigPath)) {
        '{"version":1,"listen":"0.0.0.0:2222","users_file":"C:\\ProgramData\\EasyWI\\sftp\\users.json","marker":"BEGIN EASYWI MANAGED"}' | Out-File -FilePath $SftpConfigPath -Encoding utf8 -Force
    }
    Remove-ServiceIfExists -Name $SftpServiceName
    if ($null -ne $nssm) {
        & $nssm install $SftpServiceName $SftpPath '--config' $SftpConfigPath | Out-Null
        & $nssm set $SftpServiceName Start SERVICE_AUTO_START | Out-Null
        Set-ServiceEnvironment -NssmExe $nssm -Name $SftpServiceName -EnvironmentValue $agentEnvironment
    } else {
        $sftpBin = "`"$SftpPath`" --config `"$SftpConfigPath`""
        sc.exe create $SftpServiceName binPath= $sftpBin start= auto | Out-Null
    }
    Start-Service -Name $SftpServiceName
    Write-Host "[easywi-agent] Service installed: $SftpServiceName"
} else {
    Write-Host "[easywi-agent] SFTP binary not found, skipping dedicated SFTP service installation."
}
