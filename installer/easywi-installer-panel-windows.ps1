param(
    [ValidateSet('Standalone')]
    [string]$Mode = 'Standalone',
    [string]$InstallDir = 'C:\easywi',
    [string]$RepoUrl = 'https://github.com/Matlord93/Easy-Wi-NextGen',
    [string]$RepoRef = 'Beta',
    [string]$DbDriver = 'mysql',
    [string]$DbHost = '127.0.0.1',
    [string]$DbPort = '',
    [string]$DbName = 'easywi',
    [string]$DbUser = 'easywi',
    [string]$DbPassword = '',
    [string]$AppEnv = 'prod',
    [string]$AppSecret = '',
    [switch]$RunMigrations = $true,
    [switch]$Force
)

$ErrorActionPreference = 'Stop'

function Write-Log {
    param([string]$Message)
    Write-Host "[easywi-panel-installer] $Message"
}

function Require-Value {
    param([string]$Value, [string]$Name)
    if ([string]::IsNullOrWhiteSpace($Value)) {
        throw "Missing required value: $Name"
    }
}

function Ensure-Directory {
    param([string]$Path)
    if (Test-Path -Path $Path) {
        if ($Force) {
            Remove-Item -Path $Path -Recurse -Force
        } elseif ((Get-ChildItem -Path $Path -Force | Measure-Object).Count -gt 0) {
            throw "Install directory is not empty. Use -Force to overwrite."
        }
    }
    New-Item -ItemType Directory -Path $Path -Force | Out-Null
}

function Resolve-RepoZipUrl {
    param([string]$Url, [string]$Ref)
    $repoPath = $Url.TrimEnd('/') -replace 'https://github.com/', ''
    $refUrl = "https://github.com/$repoPath/archive/refs/heads/$Ref.zip"
    $tagUrl = "https://github.com/$repoPath/archive/refs/tags/$Ref.zip"
    return @{
        Head = $refUrl
        Tag = $tagUrl
    }
}

function Download-Source {
    param([string]$TargetDir)
    if (Get-Command git -ErrorAction SilentlyContinue) {
        Write-Log "Cloning $RepoUrl ($RepoRef) to $TargetDir"
        git clone --depth 1 --branch $RepoRef $RepoUrl $TargetDir | Out-Null
        return
    }

    $urls = Resolve-RepoZipUrl -Url $RepoUrl -Ref $RepoRef
    $zipPath = Join-Path $env:TEMP "easywi-$RepoRef.zip"
    try {
        Write-Log "Downloading $($urls.Head)"
        Invoke-WebRequest -Uri $urls.Head -OutFile $zipPath
    } catch {
        Write-Log "Falling back to tag download $($urls.Tag)"
        Invoke-WebRequest -Uri $urls.Tag -OutFile $zipPath
    }

    Write-Log "Extracting archive"
    Expand-Archive -Path $zipPath -DestinationPath $TargetDir -Force
    Remove-Item -Path $zipPath -Force

    $subdir = Get-ChildItem -Path $TargetDir | Where-Object { $_.PSIsContainer } | Select-Object -First 1
    if ($null -ne $subdir) {
        Get-ChildItem -Path $subdir.FullName -Force | ForEach-Object {
            Move-Item -Path $_.FullName -Destination $TargetDir -Force
        }
        Remove-Item -Path $subdir.FullName -Force -Recurse
    }
}

function Generate-AppSecret {
    if (-not [string]::IsNullOrWhiteSpace($AppSecret)) {
        return $AppSecret
    }
    $bytes = New-Object byte[] 16
    [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    return ([System.BitConverter]::ToString($bytes) -replace '-', '').ToLower()
}

function UrlEncode {
    param([string]$Value)
    return [System.Net.WebUtility]::UrlEncode($Value)
}

function Write-EnvLocal {
    param([string]$TargetDir)
    $portValue = $DbPort
    if ([string]::IsNullOrWhiteSpace($portValue)) {
        $portValue = if ($DbDriver -eq 'pgsql') { '5432' } else { '3306' }
    }
    $secretValue = Generate-AppSecret
    $passwordEncoded = UrlEncode -Value $DbPassword
    $databaseUrl = "$DbDriver://$DbUser:$passwordEncoded@$DbHost`:$portValue/$DbName"
    $envPath = Join-Path $TargetDir 'core\.env.local'
    $content = @(
        "APP_ENV=$AppEnv"
        "APP_SECRET=$secretValue"
        "DATABASE_URL=$databaseUrl"
    ) -join "`r`n"
    $content | Set-Content -Path $envPath -Encoding UTF8
}

function Ensure-Composer {
    if (Get-Command composer -ErrorAction SilentlyContinue) {
        return $true
    }
    if (Get-Command winget -ErrorAction SilentlyContinue) {
        Write-Log "Installing Composer via winget"
        winget install --id Composer.Composer -e --source winget | Out-Null
        return $true
    }
    if (Get-Command choco -ErrorAction SilentlyContinue) {
        Write-Log "Installing Composer via Chocolatey"
        choco install composer -y | Out-Null
        return $true
    }
    return $false
}

function Ensure-Php {
    if (Get-Command php -ErrorAction SilentlyContinue) {
        return $true
    }
    if (Get-Command winget -ErrorAction SilentlyContinue) {
        Write-Log "Installing PHP via winget"
        winget install --id PHP.PHP -e --source winget | Out-Null
        return $true
    }
    if (Get-Command choco -ErrorAction SilentlyContinue) {
        Write-Log "Installing PHP via Chocolatey"
        choco install php -y | Out-Null
        return $true
    }
    return $false
}

function Run-Composer {
    if (-not (Ensure-Php)) {
        throw "PHP is required to run composer."
    }
    if (-not (Ensure-Composer)) {
        throw "Composer is missing. Install it or run again in an environment with Composer."
    }
    Write-Log "Installing PHP dependencies"
    Push-Location (Join-Path $InstallDir 'core')
    composer install --no-dev --optimize-autoloader --no-interaction
    Pop-Location
}

function Run-Migrations {
    if (-not $RunMigrations) {
        Write-Log "Skipping migrations"
        return
    }
    if (-not (Ensure-Php)) {
        throw "PHP is required to run migrations."
    }
    Write-Log "Running database migrations"
    Push-Location (Join-Path $InstallDir 'core')
    php bin/console doctrine:migrations:migrate --no-interaction
    Pop-Location
}

Require-Value -Value $DbPassword -Name 'DbPassword'

Write-Log "Preparing install directory"
Ensure-Directory -Path $InstallDir

Write-Log "Downloading web interface"
Download-Source -TargetDir $InstallDir

Write-Log "Writing configuration"
Write-EnvLocal -TargetDir $InstallDir

Run-Composer
Run-Migrations

Write-Log "Panel installation complete."
Write-Log "Open /install in the browser to create the first admin account."
