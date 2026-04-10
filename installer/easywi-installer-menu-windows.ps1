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

function Normalize-Token {
    param([string]$Token)

    if ([string]::IsNullOrWhiteSpace($Token)) { return '' }

    $normalized = $Token.Trim()
    $normalized = $normalized.Trim("`"'")
    $normalized = $normalized -replace "`uFEFF", ''
    $normalized = $normalized -replace "`u200B", ''

    return $normalized.Trim()
}

function Normalize-PollInterval {
    param([string]$PollInterval)

    if ([string]::IsNullOrWhiteSpace($PollInterval)) { return '30s' }

    $normalized = $PollInterval.Trim()
    $normalized = $normalized.Trim("`"'")

    if ($normalized -match '^[0-9]+$') {
        return "${normalized}s"
    }

    return $normalized
}

function Write-Utf8NoBomLines {
    param(
        [string]$Path,
        [string[]]$Lines
    )

    $directory = Split-Path -Parent $Path
    if (-not [string]::IsNullOrWhiteSpace($directory) -and -not (Test-Path -Path $directory)) {
        New-Item -ItemType Directory -Path $directory -Force | Out-Null
    }

    $encoding = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllLines($Path, $Lines, $encoding)
}

function Get-DefaultWindowsFileBaseDirs {
    $dirs = New-Object System.Collections.Generic.List[string]
    try {
        $drives = [System.IO.DriveInfo]::GetDrives() | Where-Object { $_.DriveType -eq 'Fixed' -and $_.IsReady }
        foreach ($drive in $drives) {
            $root = $drive.RootDirectory.FullName.TrimEnd('\')
            if (-not [string]::IsNullOrWhiteSpace($root)) {
                $dirs.Add("$root\home")
            }
        }
    } catch {
        # fallback below
    }

    if ($dirs.Count -eq 0) {
        $dirs.Add('C:\home')
        $dirs.Add('C:\inetpub\wwwroot')
    }

    return (($dirs | Select-Object -Unique) -join ',')
}

function Get-LocalIPv4Addresses {
    $ips = New-Object System.Collections.Generic.List[string]
    try {
        $entries = Get-NetIPAddress -AddressFamily IPv4 -ErrorAction Stop |
            Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' }
        foreach ($entry in $entries) {
            if (-not [string]::IsNullOrWhiteSpace($entry.IPAddress)) {
                $ips.Add($entry.IPAddress)
            }
        }
    } catch {
        # keep empty and fallback below
    }

    if ($ips.Count -eq 0) {
        return ''
    }

    return (($ips | Select-Object -Unique) -join ',')
}

function Ensure-Tls12 {
    $current = [Net.ServicePointManager]::SecurityProtocol
    if (-not ($current -band [Net.SecurityProtocolType]::Tls12)) {
        [Net.ServicePointManager]::SecurityProtocol = $current -bor [Net.SecurityProtocolType]::Tls12
        Write-Log "TLS 1.2 wurde für diese PowerShell-Session aktiviert."
    }
    [System.Net.ServicePointManager]::Expect100Continue = $false
}

function Invoke-CurlJsonApi {
    param(
        [ValidateSet('GET', 'POST', 'PUT', 'PATCH', 'DELETE')]
        [string]$Method,
        [string]$Uri,
        [string]$Body,
        [hashtable]$Headers = @{},
        [int]$TimeoutSec = 60
    )

    $curl = Get-Command curl.exe -ErrorAction SilentlyContinue
    if (-not $curl) {
        throw 'curl.exe ist nicht verfügbar.'
    }

    $args = @('-sS', '--connect-timeout', [Math]::Max(5, [Math]::Min($TimeoutSec, 30)), '--max-time', $TimeoutSec, '-X', $Method)
    foreach ($header in $Headers.GetEnumerator()) {
        $args += '-H'
        $args += "{0}: {1}" -f $header.Key, $header.Value
    }
    $args += '-H'
    $args += 'Content-Type: application/json; charset=utf-8'
    $args += '-H'
    $args += 'Accept: application/json'

    if (-not [string]::IsNullOrWhiteSpace($Body) -and $Method -ne 'GET') {
        $args += '--data-binary'
        $args += $Body
    }

    $args += '-w'
    $args += "`n%{http_code}"
    $args += $Uri

    $result = & $curl.Source @args
    if ($LASTEXITCODE -ne 0) {
        throw "curl.exe Fehlercode $LASTEXITCODE"
    }

    $parts = $result -split "`r?`n"
    $statusLine = $parts[-1]
    $rawBody = (($parts | Select-Object -SkipLast 1) -join "`n").Trim()
    [int]$statusCode = 0
    if (-not [int]::TryParse($statusLine, [ref]$statusCode)) {
        throw "curl.exe lieferte keinen gültigen HTTP-Status: $statusLine"
    }
    if ($statusCode -lt 200 -or $statusCode -ge 300) {
        throw "HTTP $statusCode via curl.exe: $rawBody"
    }
    if ([string]::IsNullOrWhiteSpace($rawBody)) { return $null }
    return $rawBody | ConvertFrom-Json
}

function Get-HttpErrorDetails {
    param($Exception)

    $responseBody = ''

    if ($null -ne $Exception -and $null -ne $Exception.Response) {
        try {
            $stream = $Exception.Response.GetResponseStream()
            if ($null -ne $stream) {
                $reader = New-Object System.IO.StreamReader($stream)
                try {
                    $responseBody = $reader.ReadToEnd()
                } finally {
                    $reader.Dispose()
                }
            }
        } catch {
            # ignore: body extraction is best-effort only
        }
    }

    if ([string]::IsNullOrWhiteSpace($responseBody)) {
        return @{
            detail = ''
            body = ''
        }
    }

    $detail = ''
    try {
        $json = $responseBody | ConvertFrom-Json
        if ($null -ne $json.detail) {
            $detail = [string]$json.detail
        }
    } catch {
        # non-JSON body
    }

    return @{
        detail = $detail
        body = $responseBody.Trim()
    }
}

function Invoke-JsonApiWithRetry {
    param(
        [ValidateSet('Get', 'Post', 'Put', 'Patch', 'Delete')]
        [string]$Method,
        [string]$Uri,
        [string]$Body,
        [hashtable]$Headers = @{},
        [int]$MaxAttempts = 5,
        [int]$TimeoutSec = 60,
        [string]$OperationName = 'API Request'
    )

    Ensure-Tls12

    if (-not $Headers.ContainsKey('Accept')) { $Headers['Accept'] = 'application/json' }
    if (-not $Headers.ContainsKey('User-Agent')) { $Headers['User-Agent'] = 'easywi-installer-menu/1.0' }

    $attempt = 1
    while ($attempt -le $MaxAttempts) {
        try {
            return Invoke-RestMethod -Method $Method -Uri $Uri -ContentType 'application/json; charset=utf-8' -Body $Body -Headers $Headers -TimeoutSec $TimeoutSec
        } catch {
            $message = $_.Exception.Message
            $statusCode = $null
            $responseBody = $null
            if ($_.Exception.Response -and $_.Exception.Response.StatusCode) {
                $statusCode = [int]$_.Exception.Response.StatusCode
                try {
                    $response = $_.Exception.Response
                    $stream = $response.GetResponseStream()
                    if ($stream) {
                        $reader = New-Object System.IO.StreamReader($stream)
                        $responseBody = $reader.ReadToEnd()
                        $reader.Dispose()
                        $stream.Dispose()
                    }
                } catch {
                    $responseBody = $null
                }
            }

            $httpError = Get-HttpErrorDetails -Exception $_.Exception
            $httpDetail = $httpError.detail
            $httpBody = $httpError.body

            $isTransient = $false
            if ($statusCode -ge 500 -or $statusCode -eq 429) {
                $isTransient = $true
            } elseif ($message -match 'unerwartet getrennt|timed out|Zeitüberschreitung|tempor|temporar|closed|reset|abgebrochen') {
                $isTransient = $true
            }

            if ($isTransient) {
                try {
                    Write-Log "${OperationName}: fallback auf curl.exe (Versuch $attempt/$MaxAttempts)."
                    return Invoke-CurlJsonApi -Method $Method.ToUpperInvariant() -Uri $Uri -Body $Body -Headers $Headers -TimeoutSec $TimeoutSec
                } catch {
                    Write-Log "$OperationName curl.exe fallback fehlgeschlagen: $($_.Exception.Message)"
                }
            }

            if ($attempt -lt $MaxAttempts -and $isTransient) {
                $delay = [Math]::Min([Math]::Pow(2, $attempt), 30)
                Write-Log "$OperationName fehlgeschlagen (Versuch $attempt/$MaxAttempts): $message"
                Write-Log "Warte ${delay}s und versuche erneut..."
                Start-Sleep -Seconds $delay
                $attempt += 1
                continue
            }

            if ($statusCode) {
                $parts = @("$OperationName fehlgeschlagen (HTTP $statusCode): $message")
                if (-not [string]::IsNullOrWhiteSpace($httpDetail)) {
                    $parts += "Detail: $httpDetail"
                }
                if (-not [string]::IsNullOrWhiteSpace($httpBody)) {
                    $parts += "Antwort: $httpBody"
                }
                if ($statusCode -eq 401 -and $OperationName -eq 'Agent Bootstrap') {
                    $parts += 'Hinweis: Prüfe Bootstrap-Token auf Gültigkeit/Ablauf, entferne unsichtbare Zeichen und vergleiche die Core-URL.'
                }
                if ($statusCode -eq 401 -and $OperationName -eq 'Agent Register') {
                    $parts += 'Hinweis: Prüfe Register-Token, Systemzeit (NTP), und ob register_url/signierter Pfad exakt zusammenpassen.'
                }
                throw ($parts -join ' ')
                if ($statusCode -eq 401 -and $OperationName -eq 'Agent Register') {
                    $hint = 'Prüfe Bootstrap-Token, Systemzeit (NTP) und Core-URL.'
                    if (-not [string]::IsNullOrWhiteSpace($responseBody)) {
                        throw "$OperationName fehlgeschlagen (HTTP $statusCode): Nicht autorisiert. $hint Antwort: $responseBody"
                    }
                    throw "$OperationName fehlgeschlagen (HTTP $statusCode): Nicht autorisiert. $hint"
                }
                if (-not [string]::IsNullOrWhiteSpace($responseBody)) {
                    throw "$OperationName fehlgeschlagen (HTTP $statusCode): $message Antwort: $responseBody"
                }
                throw "$OperationName fehlgeschlagen (HTTP $statusCode): $message"
            }
            throw "$OperationName fehlgeschlagen: $message"
        }
    }
}

function Get-RandomHex {
    param([int]$Length = 64)
    $bytes = New-Object byte[] ([Math]::Ceiling($Length / 2))
    [System.Security.Cryptography.RandomNumberGenerator]::Fill($bytes)
    (($bytes | ForEach-Object { $_.ToString('x2') }) -join '').Substring(0, $Length)
}

function Run-PanelSetup {
    param(
        [string]$Mode,
        [string]$InstallDir,
        [string]$RepoUrl,
        [string]$RepoRef,
        [string]$DbDriver,
        [string]$DbHost,
        [string]$DbPort,
        [string]$DbName,
        [string]$DbUser,
        [string]$DbPassword,
        [string]$AppSecret
    )

    Write-Log "Starte Windows Panel-Vorbereitung ($Mode)."
    Ensure-Directory -Path $InstallDir

    if (Test-Path (Join-Path $InstallDir '.git')) {
        Write-Log 'Repository vorhanden, aktualisiere…'
        git -C $InstallDir fetch --all --tags
        git -C $InstallDir checkout $RepoRef
        git -C $InstallDir pull --ff-only
    } else {
        if ((Get-ChildItem -Force -ErrorAction SilentlyContinue $InstallDir | Measure-Object).Count -gt 0) {
            throw "Installationsverzeichnis ist nicht leer und kein Git-Repository: $InstallDir"
        }
        Write-Log "Clone $RepoUrl ($RepoRef)"
        git clone $RepoUrl $InstallDir
        git -C $InstallDir checkout $RepoRef
    }

    $coreDir = Join-Path $InstallDir 'core'
    if (-not (Test-Path $coreDir)) {
        throw "Core-Verzeichnis fehlt: $coreDir"
    }

    if ([string]::IsNullOrWhiteSpace($AppSecret)) {
        $AppSecret = Get-RandomHex -Length 64
    }

    $dbUri = if ([string]::IsNullOrWhiteSpace($DbPort)) {
        "${DbDriver}://${DbUser}`:${DbPassword}@${DbHost}/${DbName}"
    } else {
        "${DbDriver}://${DbUser}`:${DbPassword}@${DbHost}`:${DbPort}/${DbName}"
    }

    $envLocalPath = Join-Path $coreDir '.env.local'
    @(
        'APP_ENV=prod',
        "APP_SECRET=$AppSecret",
        'TRUSTED_PROXIES=127.0.0.1',
        "DATABASE_URL=$dbUri"
    ) | Set-Content -Path $envLocalPath -Encoding UTF8

    $infoPath = Join-Path $InstallDir 'INSTALLATION_INFO_WINDOWS.txt'
    @(
        'EasyWI Windows Panel Vorbereitung',
        '=================================',
        "InstallDir: $InstallDir",
        "Repo: $RepoUrl@$RepoRef",
        "DB: $DbDriver $DbHost $DbName",
        '',
        'Nächste Schritte:',
        '1) PHP + Composer installieren',
        '2) Im core-Verzeichnis `composer install` ausführen',
        '3) `php bin/console doctrine:migrations:migrate --no-interaction` ausführen',
        '4) Webserver (IIS/Nginx/Apache) auf core/public zeigen lassen'
    ) | Set-Content -Path $infoPath -Encoding UTF8

    Write-Log "Fertig. .env.local wurde erstellt: $envLocalPath"
    Write-Log "Hinweise gespeichert: $infoPath"
}

function Get-Sha256Hex {
    param([string]$Value)
    $sha = [System.Security.Cryptography.SHA256]::Create()
    try {
        $bytes = [System.Text.Encoding]::UTF8.GetBytes($Value)
        $hash = $sha.ComputeHash($bytes)
        return ([System.BitConverter]::ToString($hash) -replace '-', '').ToLower()
    } finally {
        $sha.Dispose()
    }
}

function Get-HmacSha256Hex {
    param([string]$Key, [string]$Value)
    $hmac = New-Object System.Security.Cryptography.HMACSHA256
    try {
        $hmac.Key = [System.Text.Encoding]::UTF8.GetBytes($Key)
        $bytes = [System.Text.Encoding]::UTF8.GetBytes($Value)
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


function Try-DownloadReleaseAsset {
    param(
        [string]$Version,
        [string]$AssetName,
        [string]$TargetPath
    )

    try {
        Download-ReleaseAsset -Version $Version -AssetName $AssetName -TargetPath $TargetPath
        return $true
    } catch {
        Write-Log "Optionales Asset nicht verfügbar: $AssetName"
        return $false
    }
}


function Download-ReleaseAssetFromCandidates {
    param(
        [string]$Version,
        [string[]]$AssetNames,
        [string]$TargetPath
    )

    foreach ($candidate in $AssetNames) {
        if (Try-DownloadReleaseAsset -Version $Version -AssetName $candidate -TargetPath $TargetPath) {
            return $candidate
        }
    }

    throw "Keines der erwarteten Release-Assets gefunden: $($AssetNames -join ', ')"
}

function Resolve-WindowsExecutableAsset {
    param(
        [string]$Version,
        [string]$BaseAssetName,
        [string]$TargetPath
    )

    Ensure-Directory -Path (Split-Path -Parent $TargetPath)
    $tempAssetPath = "$TargetPath.download"
    $assetName = Download-ReleaseAssetFromCandidates -Version $Version -AssetNames @(
        "$BaseAssetName.exe",
        "$BaseAssetName.zip"
    ) -TargetPath $tempAssetPath

    if ($assetName.EndsWith('.zip')) {
        $extractDir = Join-Path ([System.IO.Path]::GetTempPath()) ("easywi-installer-" + [guid]::NewGuid().ToString('N'))
        Ensure-Directory -Path $extractDir
        try {
            Expand-Archive -Path $tempAssetPath -DestinationPath $extractDir -Force
            $expectedExe = "$BaseAssetName.exe"
            $exeCandidate = Get-ChildItem -Path $extractDir -Recurse -File | Where-Object { $_.Name -eq $expectedExe } | Select-Object -First 1
            if ($null -eq $exeCandidate) {
                throw "Keine ausführbare Datei $expectedExe im Archiv gefunden."
            }
            Move-Item -Path $exeCandidate.FullName -Destination $TargetPath -Force
        } finally {
            Remove-Item -Path $extractDir -Recurse -Force -ErrorAction SilentlyContinue
            Remove-Item -Path $tempAssetPath -Force -ErrorAction SilentlyContinue
        }
        return
    }

    Move-Item -Path $tempAssetPath -Destination $TargetPath -Force
}

function Install-AgentServices {
    param(
        [string]$ServiceName,
        [string]$AgentPath,
        [string]$ConfigPath,
        [string]$SftpServiceName,
        [string]$SftpPath,
        [string]$SftpConfigPath,
        [string]$InstanceBaseDir,
        [string]$SftpBaseDir
    )

    Write-Log 'Installiere Services über integrierte Windows-Menü-Logik.'

    function Ensure-LocalDirectory {
        param([string]$Path)
        if (-not (Test-Path -Path $Path)) {
            New-Item -ItemType Directory -Path $Path -Force | Out-Null
        }
    }

    function Resolve-LocalNssmPath {
        $command = Get-Command nssm.exe -ErrorAction SilentlyContinue
        if ($null -ne $command) { return $command.Path }
        return $null
    }

    function Remove-LocalServiceIfExists {
        param([string]$Name)
        $service = Get-Service -Name $Name -ErrorAction SilentlyContinue
        if ($null -ne $service) {
            Stop-Service -Name $Name -Force -ErrorAction SilentlyContinue
            sc.exe delete $Name | Out-Null
            Start-Sleep -Seconds 1
        }
    }

    function Set-LocalServiceEnvironment {
        param([string]$NssmExe, [string]$Name, [string]$EnvironmentValue)
        if ($null -ne $NssmExe) {
            & $NssmExe set $Name AppEnvironmentExtra $EnvironmentValue | Out-Null
        }
    }

    function Wait-LocalServiceExists {
        param([string]$Name, [int]$Attempts = 10)

        for ($i = 0; $i -lt $Attempts; $i++) {
            $service = Get-Service -Name $Name -ErrorAction SilentlyContinue
            if ($null -ne $service) {
                return $service
            }
            Start-Sleep -Milliseconds 300
        }

        return $null
    }

    $logDir = 'C:\ProgramData\easywi\logs'
    Ensure-LocalDirectory -Path $logDir
    Ensure-LocalDirectory -Path $SftpBaseDir

    $stdoutLog = Join-Path $logDir 'easywi-agent.out.log'
    $stderrLog = Join-Path $logDir 'easywi-agent.err.log'

    if (-not (Test-Path -Path $AgentPath)) {
        throw "Agent-Binary nicht gefunden: $AgentPath"
    }
    if (-not (Test-Path -Path $ConfigPath)) {
        throw "Agent-Konfiguration nicht gefunden: $ConfigPath"
    }

    [Environment]::SetEnvironmentVariable('EASYWI_INSTANCE_BASE_DIR', $InstanceBaseDir, 'Machine')
    [Environment]::SetEnvironmentVariable('EASYWI_SFTP_BASE_DIR', $SftpBaseDir, 'Machine')
    $agentEnvironment = "EASYWI_INSTANCE_BASE_DIR=$InstanceBaseDir`nEASYWI_SFTP_BASE_DIR=$SftpBaseDir"

    $nssm = Resolve-LocalNssmPath
    Remove-LocalServiceIfExists -Name $ServiceName

    if ($null -ne $nssm) {
        & $nssm install $ServiceName $AgentPath '--config' $ConfigPath | Out-Null
        & $nssm set $ServiceName AppStdout $stdoutLog | Out-Null
        & $nssm set $ServiceName AppStderr $stderrLog | Out-Null
        & $nssm set $ServiceName AppRotateFiles 1 | Out-Null
        & $nssm set $ServiceName AppRotateSeconds 86400 | Out-Null
        & $nssm set $ServiceName Start SERVICE_AUTO_START | Out-Null
        Set-LocalServiceEnvironment -NssmExe $nssm -Name $ServiceName -EnvironmentValue $agentEnvironment
    } else {
        $binPath = "`"$AgentPath`" --config `"$ConfigPath`""
        New-Service -Name $ServiceName -BinaryPathName $binPath -DisplayName $ServiceName -StartupType Automatic | Out-Null
        Write-Log 'Hinweis: NSSM nicht gefunden; Service wird ohne stdout/stderr-Umleitung installiert.'
    }

    $createdService = Wait-LocalServiceExists -Name $ServiceName
    if ($null -eq $createdService) {
        throw "Dienst konnte nicht erstellt werden: $ServiceName"
    }

    try {
        Start-Service -Name $ServiceName -ErrorAction Stop
        Write-Log "Service installiert: $ServiceName"
    } catch {
        $agentService = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
        $serviceStatus = if ($null -ne $agentService) { [string]$agentService.Status } else { 'unknown' }
        Write-Log "Agent-Service konnte nicht gestartet werden. Name=$ServiceName Status=$serviceStatus"
        if (Test-Path -Path $stderrLog) {
            try {
                $tail = Get-Content -Path $stderrLog -Tail 20 -ErrorAction Stop
                if ($null -ne $tail -and $tail.Count -gt 0) {
                    Write-Log 'Letzte Agent-Fehlerausgaben:'
                    $tail | ForEach-Object { Write-Log "  $_" }
                }
            } catch {
                Write-Log "Konnte Agent-Error-Log nicht lesen: $($_.Exception.Message)"
            }
        }
        throw "Agent-Service konnte nicht gestartet werden. Häufige Ursache ohne NSSM: Service-Account hat keinen Zugriff auf Binary/Config oder Startparameter sind ungültig. Prüfe: $AgentPath, $ConfigPath, $stderrLog"
    }

    if (Test-Path -Path $SftpPath) {
        Ensure-LocalDirectory -Path ([System.IO.Path]::GetDirectoryName($SftpConfigPath))
        if (-not (Test-Path -Path $SftpConfigPath)) {
            '{"version":1,"listen":"0.0.0.0:2222","users_file":"C:\ProgramData\EasyWI\sftp\users.json","marker":"BEGIN EASYWI MANAGED"}' | Out-File -FilePath $SftpConfigPath -Encoding utf8 -Force
        }
        Remove-LocalServiceIfExists -Name $SftpServiceName
        if ($null -ne $nssm) {
            & $nssm install $SftpServiceName $SftpPath '--config' $SftpConfigPath | Out-Null
            & $nssm set $SftpServiceName Start SERVICE_AUTO_START | Out-Null
            Set-LocalServiceEnvironment -NssmExe $nssm -Name $SftpServiceName -EnvironmentValue $agentEnvironment
        } else {
            if (-not (Test-Path -Path $SftpConfigPath)) {
                throw "SFTP-Konfiguration nicht gefunden: $SftpConfigPath"
            }
            $sftpBin = "`"$SftpPath`" --config `"$SftpConfigPath`""
            New-Service -Name $SftpServiceName -BinaryPathName $sftpBin -DisplayName $SftpServiceName -StartupType Automatic | Out-Null
            Write-Log 'Hinweis: NSSM nicht gefunden; SFTP-Service wird ohne stdout/stderr-Umleitung installiert.'
        }
        $createdSftpService = Wait-LocalServiceExists -Name $SftpServiceName
        if ($null -eq $createdSftpService) {
            throw "Dienst konnte nicht erstellt werden: $SftpServiceName"
        }
        try {
            Start-Service -Name $SftpServiceName -ErrorAction Stop
            Write-Log "Service installiert: $SftpServiceName"
        } catch {
            $sftpService = Get-Service -Name $SftpServiceName -ErrorAction SilentlyContinue
            $sftpStatus = if ($null -ne $sftpService) { [string]$sftpService.Status } else { 'unknown' }
            Write-Log "SFTP-Service konnte nicht gestartet werden. Name=$SftpServiceName Status=$sftpStatus"
            throw "SFTP-Service konnte nicht gestartet werden. Prüfe Pfade/Config: $SftpConfigPath"
        }
    } else {
        Write-Log 'SFTP-Binary nicht gefunden, separater SFTP-Service wird übersprungen.'
    }
}

function Get-SignaturePathFromUrl {
    param([string]$Url)

    if ([string]::IsNullOrWhiteSpace($Url)) { return '/' }

    try {
        $uri = [System.Uri]$Url
        $path = $uri.AbsolutePath
    } catch {
        $path = $Url
    }

    if ([string]::IsNullOrWhiteSpace($path)) { return '/' }
    if (-not $path.StartsWith('/')) { $path = "/$path" }
    if ($path.Length -gt 1 -and $path.EndsWith('/')) { $path = $path.TrimEnd('/') }
    if ([string]::IsNullOrWhiteSpace($path)) { return '/' }

    return $path
}

function Register-Agent {
    param(
        [string]$CoreUrl,
        [string]$BootstrapToken,
        [string]$AgentVersion,
        [string]$ConfigPath,
        [string]$FileBaseDirs,
        [string]$BindIPAddresses
    )

    $CoreUrl = Normalize-Url -Url $CoreUrl
    $hostname = $env:COMPUTERNAME
    $osName = 'windows'
    $bootstrapTokenNormalized = Normalize-Token -Token $BootstrapToken
    if ([string]::IsNullOrWhiteSpace($bootstrapTokenNormalized)) { throw 'Bootstrap Token ist leer oder ungültig.' }

    $payload = @{ bootstrap_token = $bootstrapTokenNormalized; hostname = $hostname; os = $osName; agent_version = $AgentVersion } | ConvertTo-Json

    Write-Log "Bootstrapping Agent via $CoreUrl/api/v1/agent/bootstrap"
    $bootstrapResponse = Invoke-JsonApiWithRetry -Method Post -Uri "$CoreUrl/api/v1/agent/bootstrap" -Body $payload -OperationName 'Agent Bootstrap'

    if (-not $bootstrapResponse.register_token) { throw 'Bootstrap response missing register_token.' }
    if (-not $bootstrapResponse.agent_id) { throw 'Bootstrap response missing agent_id.' }

    $registerUrl = $bootstrapResponse.register_url
    if ([string]::IsNullOrWhiteSpace($registerUrl)) { $registerUrl = "$CoreUrl/api/v1/agent/register" }

    $corePublicUrl = $bootstrapResponse.core_public_url
    if (-not [string]::IsNullOrWhiteSpace($corePublicUrl)) { $CoreUrl = Normalize-Url -Url $corePublicUrl }

    $pollInterval = Normalize-PollInterval -PollInterval ([string]$bootstrapResponse.polling_interval)

    $registerToken = Normalize-Token -Token ([string]$bootstrapResponse.register_token)
    if ([string]::IsNullOrWhiteSpace($registerToken)) { throw 'Bootstrap response missing register_token.' }

    $registerPayload = @{ agent_id = $bootstrapResponse.agent_id; name = $hostname; register_token = $registerToken } | ConvertTo-Json
    $bodyHash = Get-Sha256Hex -Value $registerPayload
    $timestamp = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
    $nonce = New-Nonce
    $signaturePath = Get-SignaturePathFromUrl -Url $registerUrl
    $signaturePayload = "$($bootstrapResponse.agent_id)`nPOST`n$signaturePath`n$bodyHash`n$timestamp`n$nonce"
    $signature = Get-HmacSha256Hex -Key $registerToken -Value $signaturePayload

    Write-Log "Registriere Agent via $registerUrl"
    Write-Log "Register Debug: agent_id=$($bootstrapResponse.agent_id) path=$signaturePath body_hash=$bodyHash timestamp=$timestamp nonce=$nonce signature_prefix=$($signature.Substring(0, [Math]::Min(12, $signature.Length)))"
    Write-Log "Register Payload Debug: length=$($registerPayload.Length)"
    $registerResponse = Invoke-JsonApiWithRetry -Method Post -Uri $registerUrl -Body $registerPayload -Headers @{
        'X-Agent-ID' = $bootstrapResponse.agent_id
        'X-Timestamp' = $timestamp
        'X-Nonce' = $nonce
        'X-Signature' = $signature
    } -OperationName 'Agent Register'

    if (-not $registerResponse.secret) { throw 'Register response missing secret.' }

    Write-Utf8NoBomLines -Path $ConfigPath -Lines @(
        "agent_id=$($bootstrapResponse.agent_id)",
        "secret=$($registerResponse.secret)",
        "api_url=$CoreUrl",
        "poll_interval=$pollInterval",
        "version=$AgentVersion",
        "file_base_dir=$($FileBaseDirs.Split(',')[0].Trim())",
        "file_base_dirs=$FileBaseDirs",
        "bind_ip_addresses=$BindIPAddresses"
    )
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

    Run-PanelSetup -Mode $mode -InstallDir $installDir -RepoUrl $repoUrl -RepoRef $repoRef -DbDriver $dbDriver -DbHost $dbHost -DbPort $dbPort -DbName $dbName -DbUser $dbUser -DbPassword $dbPassword -AppSecret $appSecret
}

function Run-AgentInstall {
    Write-Host ''
    Write-Host 'Agent-Setup: Wir laden Agent (und optional easywi-sftp), registrieren den Agenten und installieren Windows-Services.'
    $coreUrl = Read-Optional -Prompt 'Core API URL' -Default $env:EASYWI_CORE_URL
    if ([string]::IsNullOrWhiteSpace($coreUrl)) { $coreUrl = 'https://api.easywi.example' }
    $bootstrapToken = Read-Optional -Prompt 'Bootstrap Token' -Default $env:EASYWI_BOOTSTRAP_TOKEN
    $agentVersion = Read-Optional -Prompt 'Agent Version (latest oder Tag)' -Default 'latest'
    $installDir = Read-Optional -Prompt 'Agent Installationsverzeichnis' -Default 'C:\easywi\agent'
    $configPath = Read-Optional -Prompt 'Agent Config Pfad' -Default 'C:\ProgramData\easywi\agent.conf'
    $serviceName = Read-Optional -Prompt 'Service-Name (Agent)' -Default 'easywi-agent'
    $defaultFileBaseDirs = Get-DefaultWindowsFileBaseDirs
    $fileBaseDirs = Read-Optional -Prompt 'File Base Directories (comma separated)' -Default $defaultFileBaseDirs
    $instanceBaseDir = Read-Optional -Prompt 'Instance Base Directory' -Default 'C:\home'
    $installEmbeddedSftp = Read-Optional -Prompt 'Embedded easywi-sftp zusätzlich installieren? (yes/no)' -Default 'yes'
    $sftpServiceName = Read-Optional -Prompt 'Service-Name (SFTP)' -Default 'EasyWI-SFTP'
    $sftpBaseDir = Read-Optional -Prompt 'SFTP Base Directory' -Default 'C:\ProgramData\EasyWI\sftp'
    $defaultBindIPs = Get-LocalIPv4Addresses
    $bindIPAddresses = Read-Optional -Prompt 'Bind IP Addresses (comma separated, optional)' -Default $defaultBindIPs

    $bootstrapToken = Normalize-Token -Token $bootstrapToken

    if ([string]::IsNullOrWhiteSpace($coreUrl) -or [string]::IsNullOrWhiteSpace($bootstrapToken)) {
        throw 'Core API URL und Bootstrap Token sind erforderlich.'
    }

    $agentPath = Join-Path $installDir 'easywi-agent.exe'
    $sftpPath = Join-Path $installDir 'easywi-sftp.exe'
    $sftpConfigPath = Join-Path $sftpBaseDir 'config.json'

    Resolve-WindowsExecutableAsset -Version $agentVersion -BaseAssetName 'easywi-agent-windows-amd64' -TargetPath $agentPath
    $enableSftpService = $installEmbeddedSftp -match '^(1|y|yes|true)$'
    if ($enableSftpService) {
        try {
            Resolve-WindowsExecutableAsset -Version $agentVersion -BaseAssetName 'easywi-sftp-windows-amd64' -TargetPath $sftpPath
            $downloadedSftp = $true
        } catch {
            $downloadedSftp = $false
        }
        if (-not $downloadedSftp) {
            Write-Log 'easywi-sftp konnte nicht heruntergeladen werden, Agent wird ohne separaten SFTP-Service installiert.'
        }
    }

    $bindIPAddresses = ($bindIPAddresses -split ',') | ForEach-Object { $_.Trim() } | Where-Object { -not [string]::IsNullOrWhiteSpace($_) } | Select-Object -Unique
    $bindIPAddressesValue = ($bindIPAddresses -join ',')

    Register-Agent -CoreUrl $coreUrl -BootstrapToken $bootstrapToken -AgentVersion $agentVersion -ConfigPath $configPath -FileBaseDirs $fileBaseDirs -BindIPAddresses $bindIPAddressesValue
    Install-AgentServices `
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
