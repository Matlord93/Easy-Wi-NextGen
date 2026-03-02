param(
    [string]$Version = 'latest',
    [string]$InstallDir = 'C:\easywi\agent',
    [string]$ServiceName = 'easywi-agent',
    [string]$ConfigPath = 'C:\ProgramData\easywi\agent.conf',
    [string]$LogPath = 'C:\ProgramData\easywi\install-agent.log',
    [string]$ProxyUrl = '',
    [switch]$SkipServiceInstall
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

function Write-Log {
    param([string]$Message)
    $line = "[easywi-windows-agent] $Message"
    Write-Host $line
    $logDir = Split-Path -Parent $LogPath
    if (-not [string]::IsNullOrWhiteSpace($logDir) -and -not (Test-Path -Path $logDir)) {
        New-Item -Path $logDir -ItemType Directory -Force | Out-Null
    }
    Add-Content -Path $LogPath -Value "$(Get-Date -Format o) $line"
}

function Ensure-Directory {
    param([string]$Path)
    if (-not (Test-Path -Path $Path)) {
        New-Item -Path $Path -ItemType Directory -Force | Out-Null
    }
}

function Resolve-EffectiveProxy {
    param([string]$ExplicitProxy)

    if (-not [string]::IsNullOrWhiteSpace($ExplicitProxy)) {
        return $ExplicitProxy
    }

    foreach ($name in @('HTTPS_PROXY', 'https_proxy', 'HTTP_PROXY', 'http_proxy')) {
        $value = [Environment]::GetEnvironmentVariable($name)
        if (-not [string]::IsNullOrWhiteSpace($value)) {
            return $value
        }
    }

    return ''
}

function Invoke-ApiRequest {
    param(
        [Parameter(Mandatory = $true)][string]$Uri,
        [string]$OutFile = ''
    )

    $headers = @{ 'User-Agent' = 'easywi-windows-agent-installer' }
    $params = @{
        Method  = 'Get'
        Uri     = $Uri
        Headers = $headers
    }

    if (-not [string]::IsNullOrWhiteSpace($script:EffectiveProxy)) {
        $params.Proxy = $script:EffectiveProxy
    }

    if (-not [string]::IsNullOrWhiteSpace($OutFile)) {
        $params.OutFile = $OutFile
        Invoke-WebRequest @params | Out-Null
        return $null
    }

    return Invoke-RestMethod @params
}

function Resolve-ReleaseTag {
    param([string]$RequestedVersion)

    if ($RequestedVersion -ne '' -and $RequestedVersion -ne 'latest') {
        return $RequestedVersion
    }

    $release = Invoke-ApiRequest -Uri 'https://api.github.com/repos/Matlord93/Easy-Wi-NextGen/releases/latest'
    if ($null -eq $release.tag_name -or [string]::IsNullOrWhiteSpace($release.tag_name)) {
        throw 'Konnte keine aktuelle Agent-Version von GitHub ermitteln.'
    }

    return [string]$release.tag_name
}

function Ensure-PackageTooling {
    if (Get-Command nssm.exe -ErrorAction SilentlyContinue) {
        return
    }

    if (Get-Command choco.exe -ErrorAction SilentlyContinue) {
        choco install nssm -y --no-progress | Out-Null
        return
    }

    if (Get-Command winget.exe -ErrorAction SilentlyContinue) {
        winget install --id NSSM.NSSM --silent --accept-package-agreements --accept-source-agreements | Out-Null
        return
    }

    if (Get-Command scoop -ErrorAction SilentlyContinue) {
        scoop install nssm | Out-Null
        return
    }

    throw 'Kein Paketmanager verfügbar (choco/winget/scoop) und nssm.exe fehlt.'
}

function Get-ExpectedChecksum {
    param(
        [string]$ChecksumsPath,
        [string]$AssetName
    )

    $line = Select-String -Path $ChecksumsPath -Pattern "\s$([Regex]::Escape($AssetName))$" | Select-Object -First 1
    if ($null -eq $line) {
        throw "Keine Prüfsumme für $AssetName gefunden."
    }

    $parts = ($line.Line -split '\s+') | Where-Object { $_ -ne '' }
    if ($parts.Length -lt 2) {
        throw "Ungültiger Prüfsummen-Eintrag: $($line.Line)"
    }

    return $parts[0].ToLowerInvariant()
}

function Assert-Checksum {
    param(
        [string]$FilePath,
        [string]$ExpectedHash
    )

    $actual = (Get-FileHash -Path $FilePath -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($actual -ne $ExpectedHash.ToLowerInvariant()) {
        throw "Checksum mismatch für $FilePath"
    }
}

$script:EffectiveProxy = Resolve-EffectiveProxy -ExplicitProxy $ProxyUrl
if (-not [string]::IsNullOrWhiteSpace($script:EffectiveProxy)) {
    Write-Log "Proxy aktiv: $script:EffectiveProxy"
}

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$serviceInstaller = Join-Path $scriptRoot 'install-service.ps1'
if (-not (Test-Path -Path $serviceInstaller)) {
    throw "install-service.ps1 nicht gefunden: $serviceInstaller"
}

$resolvedVersion = Resolve-ReleaseTag -RequestedVersion $Version
$cleanVersion = $resolvedVersion.TrimStart('v', 'V')
$assetName = 'easywi-agent-windows-amd64.exe'
$releaseBase = "https://github.com/Matlord93/Easy-Wi-NextGen/releases/download/$resolvedVersion"
$assetUrl = "$releaseBase/$assetName"
$checksumsUrl = "$releaseBase/checksums-agent.txt"

Ensure-Directory -Path $InstallDir
Ensure-Directory -Path ([System.IO.Path]::GetDirectoryName($ConfigPath))

$agentBinary = Join-Path $InstallDir 'easywi-agent.exe'
$tempBinary = Join-Path $env:TEMP "easywi-agent-$cleanVersion.exe"
$tempChecksums = Join-Path $env:TEMP "easywi-agent-$cleanVersion-checksums.txt"

Write-Log "Lade Agent-Version $resolvedVersion herunter ..."
Invoke-ApiRequest -Uri $assetUrl -OutFile $tempBinary
Invoke-ApiRequest -Uri $checksumsUrl -OutFile $tempChecksums

$expectedHash = Get-ExpectedChecksum -ChecksumsPath $tempChecksums -AssetName $assetName
Assert-Checksum -FilePath $tempBinary -ExpectedHash $expectedHash

$replaceBinary = $true
if (Test-Path -Path $agentBinary) {
    $existingHash = (Get-FileHash -Path $agentBinary -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($existingHash -eq $expectedHash) {
        $replaceBinary = $false
        Write-Log "Binary bereits aktuell ($resolvedVersion); kein Austausch nötig."
    }
}

if ($replaceBinary) {
    Move-Item -Path $tempBinary -Destination $agentBinary -Force
    Write-Log "Binary aktualisiert: $agentBinary"
} else {
    Remove-Item -Path $tempBinary -Force -ErrorAction SilentlyContinue
}
Remove-Item -Path $tempChecksums -Force -ErrorAction SilentlyContinue

Ensure-PackageTooling

if (-not (Test-Path -Path $ConfigPath)) {
    @(
        '# EasyWI Agent Konfiguration',
        '# agent_id=node-xxxx',
        '# shared_secret=<secret>',
        'control_listen=0.0.0.0:7443',
        'service_listen=0.0.0.0:7456'
    ) | Set-Content -Path $ConfigPath -Encoding UTF8
    Write-Log "Neue Konfiguration erstellt: $ConfigPath"
} else {
    Write-Log "Bestehende Konfiguration bleibt erhalten: $ConfigPath"
}

if (-not $SkipServiceInstall) {
    & $serviceInstaller -ServiceName $ServiceName -AgentPath $agentBinary -ConfigPath $ConfigPath
}

Write-Log "Installation abgeschlossen: $agentBinary ($resolvedVersion)"
