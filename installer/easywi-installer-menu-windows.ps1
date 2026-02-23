param(
    [switch]$NonInteractive
)

$ErrorActionPreference = 'Stop'

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$RepoOwner = $env:EASYWI_REPO_OWNER
if ([string]::IsNullOrWhiteSpace($RepoOwner)) { $RepoOwner = 'Matlord93' }
$RepoName = $env:EASYWI_REPO_NAME
if ([string]::IsNullOrWhiteSpace($RepoName)) { $RepoName = 'Easy-Wi-NextGen' }

function Write-Log {
    param([string]$Message)
    Write-Host "[easywi-installer-menu] $Message"
}

function Assert-Admin {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'Bitte die PowerShell als Administrator starten.'
    }
}

function Read-Optional {
    param([string]$Prompt, [string]$Default = '')
    if ($NonInteractive) { return $Default }
    if (-not [Console]::IsInputRedirected) {
        if (-not [string]::IsNullOrWhiteSpace($Default)) { $Prompt = "$Prompt [$Default]" }
        $value = Read-Host $Prompt
        if ([string]::IsNullOrWhiteSpace($value)) { return $Default }
        return $value
    }
    return $Default
}

function Ensure-Directory {
    param([string]$Path)
    if (-not (Test-Path -Path $Path)) {
        New-Item -ItemType Directory -Path $Path -Force | Out-Null
    }
}

function Normalize-Url {
    param([string]$Url)
    if ([string]::IsNullOrWhiteSpace($Url)) { return $Url }
    return $Url.Trim().TrimEnd('/')
}

function Get-Sha256Hex {
    param([string]$Input)
    $sha = [System.Security.Cryptography.SHA256]::Create()
    try {
        $bytes = [System.Text.Encoding]::UTF8.GetBytes($Input)
        $hash = $sha.ComputeHash($bytes)
        return ([System.BitConverter]::ToString($hash) -replace '-', '').ToLower()
    } finally {
        $sha.Dispose()
    }
}

function Get-HmacSha256Hex {
    param([string]$Key, [string]$Input)
    $hmac = New-Object System.Security.Cryptography.HMACSHA256
    try {
        $hmac.Key = [System.Text.Encoding]::UTF8.GetBytes($Key)
        $bytes = [System.Text.Encoding]::UTF8.GetBytes($Input)
        $hash = $hmac.ComputeHash($bytes)
        return ([System.BitConverter]::ToString($hash) -replace '-', '').ToLower()
    } finally {
        $hmac.Dispose()
    }
}

function New-Nonce {
    $bytes = New-Object byte[] 16
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    return ([System.BitConverter]::ToString($bytes) -replace '-', '').ToLower()
}

function Download-ReleaseAsset {
    param(
        [string]$Version,
        [string]$AssetName,
        [string]$TargetPath
    )

    Ensure-Directory -Path (Split-Path -Parent $TargetPath)
    if ($Version -eq 'latest') {
        $url = "https://github.com/$RepoOwner/$RepoName/releases/latest/download/$AssetName"
    } else {
        $url = "https://github.com/$RepoOwner/$RepoName/releases/download/$Version/$AssetName"
    }
    Write-Log "Lade $AssetName von $url"
    Invoke-WebRequest -Uri $url -OutFile $TargetPath
}

function Register-Agent {
    param(
        [string]$CoreUrl,
        [string]$BootstrapToken,
        [string]$AgentVersion,
        [string]$ConfigPath,
        [string]$FileBaseDirs
    )

    $CoreUrl = Normalize-Url -Url $CoreUrl
    $hostname = $env:COMPUTERNAME
    $osName = 'windows'
    $payload = @{ bootstrap_token = $BootstrapToken; hostname = $hostname; os = $osName; agent_version = $AgentVersion } | ConvertTo-Json

    Write-Log "Bootstrapping Agent via $CoreUrl/api/v1/agent/bootstrap"
    $bootstrapResponse = Invoke-RestMethod -Method Post -Uri "$CoreUrl/api/v1/agent/bootstrap" -ContentType 'application/json' -Body $payload

    if (-not $bootstrapResponse.register_token) { throw 'Bootstrap response missing register_token.' }
    if (-not $bootstrapResponse.agent_id) { throw 'Bootstrap response missing agent_id.' }

    $registerUrl = $bootstrapResponse.register_url
    if ([string]::IsNullOrWhiteSpace($registerUrl)) { $registerUrl = "$CoreUrl/api/v1/agent/register" }

    $corePublicUrl = $bootstrapResponse.core_public_url
    if (-not [string]::IsNullOrWhiteSpace($corePublicUrl)) { $CoreUrl = Normalize-Url -Url $corePublicUrl }

    $pollInterval = $bootstrapResponse.polling_interval
    if ([string]::IsNullOrWhiteSpace($pollInterval)) { $pollInterval = '30s' }

    $registerPayload = @{ agent_id = $bootstrapResponse.agent_id; name = $hostname; register_token = $bootstrapResponse.register_token } | ConvertTo-Json
    $bodyHash = Get-Sha256Hex -Input $registerPayload
    $timestamp = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
    $nonce = New-Nonce
    $signaturePayload = "$($bootstrapResponse.agent_id)`nPOST`n/api/v1/agent/register`n$bodyHash`n$timestamp`n$nonce"
    $signature = Get-HmacSha256Hex -Key $bootstrapResponse.register_token -Input $signaturePayload

    Write-Log "Registriere Agent via $registerUrl"
    $registerResponse = Invoke-RestMethod -Method Post -Uri $registerUrl -ContentType 'application/json' -Body $registerPayload -Headers @{
        'X-Agent-ID' = $bootstrapResponse.agent_id
        'X-Timestamp' = $timestamp
        'X-Nonce' = $nonce
        'X-Signature' = $signature
    }

    if (-not $registerResponse.secret) { throw 'Register response missing secret.' }

    Ensure-Directory -Path (Split-Path -Parent $ConfigPath)
    @(
        "agent_id=$($bootstrapResponse.agent_id)",
        "secret=$($registerResponse.secret)",
        "api_url=$CoreUrl",
        "poll_interval=$pollInterval",
        "version=$AgentVersion",
        "file_base_dir=$($FileBaseDirs.Split(',')[0].Trim())",
        "file_base_dirs=$FileBaseDirs"
    ) | Set-Content -Path $ConfigPath -Encoding UTF8
}

function Run-PanelInstall {
    Write-Host ''
    Write-Host 'Panel-Setup: Wir laden das Webinterface, schreiben die .env.local und richten Abhängigkeiten ein.'
    $mode = 'Standalone'
    $installDir = Read-Optional -Prompt 'Installationsverzeichnis' -Default 'C:\easywi'
    $repoUrl = Read-Optional -Prompt 'Git-Repository URL' -Default "https://github.com/$RepoOwner/$RepoName"
    $repoRef = Read-Optional -Prompt 'Git-Branch/Tag' -Default 'Beta'
    $dbDriver = Read-Optional -Prompt 'DB-Treiber (mysql/pgsql)' -Default 'mysql'
    $dbHost = Read-Optional -Prompt 'DB-Host' -Default '127.0.0.1'
    $dbPort = Read-Optional -Prompt 'DB-Port (leer = Standard)' -Default ''
    $dbName = Read-Optional -Prompt 'DB-Name' -Default 'easywi'
    $dbUser = Read-Optional -Prompt 'DB-User' -Default 'easywi'
    $dbPassword = Read-Optional -Prompt 'DB-Passwort' -Default ''
    $appSecret = Read-Optional -Prompt 'APP_SECRET (leer = automatisch)' -Default ''

    $panelArgs = @(
        '-Mode', $mode,
        '-InstallDir', $installDir,
        '-RepoUrl', $repoUrl,
        '-RepoRef', $repoRef,
        '-DbDriver', $dbDriver,
        '-DbHost', $dbHost,
        '-DbName', $dbName,
        '-DbUser', $dbUser
    )
    if (-not [string]::IsNullOrWhiteSpace($dbPort)) { $panelArgs += @('-DbPort', $dbPort) }
    if (-not [string]::IsNullOrWhiteSpace($dbPassword)) { $panelArgs += @('-DbPassword', $dbPassword) }
    if (-not [string]::IsNullOrWhiteSpace($appSecret)) { $panelArgs += @('-AppSecret', $appSecret) }

    & "$ScriptDir\easywi-installer-panel-windows.ps1" @panelArgs
}

function Run-AgentInstall {
    Write-Host ''
    Write-Host 'Agent-Setup: Wir laden Agent/SFTP-Binaries, registrieren den Agenten und installieren Windows-Services.'
    $coreUrl = Read-Optional -Prompt 'Core API URL' -Default $env:EASYWI_CORE_URL
    if ([string]::IsNullOrWhiteSpace($coreUrl)) { $coreUrl = 'https://api.easywi.example' }
    $bootstrapToken = Read-Optional -Prompt 'Bootstrap Token' -Default $env:EASYWI_BOOTSTRAP_TOKEN
    $agentVersion = Read-Optional -Prompt 'Agent Version (latest oder Tag)' -Default 'latest'
    $installDir = Read-Optional -Prompt 'Agent Installationsverzeichnis' -Default 'C:\easywi\agent'
    $configPath = Read-Optional -Prompt 'Agent Config Pfad' -Default 'C:\ProgramData\easywi\agent.conf'
    $serviceName = Read-Optional -Prompt 'Service-Name (Agent)' -Default 'easywi-agent'
    $sftpServiceName = Read-Optional -Prompt 'Service-Name (SFTP)' -Default 'EasyWI-SFTP'
    $fileBaseDirs = Read-Optional -Prompt 'File Base Directories (comma separated)' -Default 'C:\home,C:\inetpub\wwwroot'
    $instanceBaseDir = Read-Optional -Prompt 'Instance Base Directory' -Default 'C:\home'
    $sftpBaseDir = Read-Optional -Prompt 'SFTP Base Directory' -Default 'C:\ProgramData\EasyWI\sftp'

    if ([string]::IsNullOrWhiteSpace($coreUrl) -or [string]::IsNullOrWhiteSpace($bootstrapToken)) {
        throw 'Core API URL und Bootstrap Token sind erforderlich.'
    }

    $agentPath = Join-Path $installDir 'easywi-agent.exe'
    $sftpPath = Join-Path $installDir 'easywi-sftp.exe'
    $sftpConfigPath = Join-Path $sftpBaseDir 'config.json'

    Download-ReleaseAsset -Version $agentVersion -AssetName 'easywi-agent-windows-amd64.exe' -TargetPath $agentPath
    Download-ReleaseAsset -Version $agentVersion -AssetName 'easywi-sftp-windows-amd64.exe' -TargetPath $sftpPath
    Register-Agent -CoreUrl $coreUrl -BootstrapToken $bootstrapToken -AgentVersion $agentVersion -ConfigPath $configPath -FileBaseDirs $fileBaseDirs

    & "$ScriptDir\windows-agent\install-service.ps1" `
        -ServiceName $serviceName `
        -AgentPath $agentPath `
        -ConfigPath $configPath `
        -SftpServiceName $sftpServiceName `
        -SftpPath $sftpPath `
        -SftpConfigPath $sftpConfigPath `
        -InstanceBaseDir $instanceBaseDir `
        -SftpBaseDir $sftpBaseDir
}

function Main-Menu {
    Assert-Admin
    Write-Host 'EasyWI Installer Menü (Windows)'
    Write-Host 'Dieses Skript erklärt jeden Schritt und führt die Installation automatisiert aus.'
    Write-Host ''
    Write-Host '1) Webinterface (Panel)'
    Write-Host '2) Agent'
    Write-Host '3) Panel + Agent'
    Write-Host '4) Beenden'

    $choice = Read-Optional -Prompt 'Bitte wählen Sie [1-4]' -Default '4'
    switch ($choice) {
        '1' { Run-PanelInstall }
        '2' { Run-AgentInstall }
        '3' { Run-PanelInstall; Run-AgentInstall }
        default { Write-Log 'Installation beendet.' }
    }
}

Main-Menu
