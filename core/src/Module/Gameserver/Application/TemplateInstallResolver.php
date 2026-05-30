<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Instance;
use Symfony\Contracts\Translation\TranslatorInterface;

class TemplateInstallResolver
{
    public function __construct(
        private readonly MinecraftCatalogService $catalogService,
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?JavaBinaryConfig $javaBinaryConfig = null,
    ) {
    }

    public function resolveInstallCommand(Instance $instance): string
    {
        $template = $instance->getTemplate();
        $installCommand = $template->getInstallCommand();
        if ($installCommand !== '' && !$this->isResolverPlaceholder($installCommand, 'install')) {
            return $this->finalizeCommand($installCommand, $instance);
        }
        $resolver = $template->getInstallResolver();
        $type = is_array($resolver) ? (string) ($resolver['type'] ?? '') : '';

        $command = match ($type) {
            'minecraft_vanilla' => $this->resolveMinecraftCommand($instance, 'vanilla'),
            'papermc_paper' => $this->resolveMinecraftCommand($instance, 'paper'),
            'minecraft_bedrock' => $this->resolveMinecraftCommand($instance, 'bedrock'),
            default => $installCommand,
        };

        return $this->finalizeCommand($command, $instance);
    }

    public function resolveUpdateCommand(Instance $instance): string
    {
        $template = $instance->getTemplate();
        $updateCommand = $template->getUpdateCommand();
        if ($updateCommand !== '' && !$this->isResolverPlaceholder($updateCommand, 'update')) {
            return $this->finalizeCommand($updateCommand, $instance);
        }
        $resolver = $template->getInstallResolver();
        $type = is_array($resolver) ? (string) ($resolver['type'] ?? '') : '';

        $command = match ($type) {
            'minecraft_vanilla' => $this->resolveMinecraftCommand($instance, 'vanilla'),
            'papermc_paper' => $this->resolveMinecraftCommand($instance, 'paper'),
            'minecraft_bedrock' => $this->resolveMinecraftCommand($instance, 'bedrock'),
            default => $updateCommand,
        };

        return $this->finalizeCommand($command, $instance);
    }

    private function finalizeCommand(string $command, Instance $instance): string
    {
        $command = $this->applySteamLogin($command, $instance);

        return $this->prependSteamDumpCleanup($command, $instance);
    }

    private function resolveMinecraftCommand(Instance $instance, string $channel): string
    {
        $entry = $this->catalogService->resolveEntry(
            $channel,
            $instance->getLockedVersion(),
            $instance->getLockedBuildId(),
        );

        $os = $this->resolveOs($instance->getNode());

        if ($entry === null) {
            return sprintf('echo %s >&2; exit 1', escapeshellarg($this->trans('minecraft_install_error_no_active_catalog_entry')));
        }

        if ($channel === 'bedrock') {
            return $os === 'windows'
                ? $this->buildWindowsBedrockCommand($entry->getDownloadUrl())
                : $this->buildLinuxBedrockCommand($entry->getDownloadUrl());
        }

        $downloadCmd = $os === 'windows'
            ? $this->buildWindowsDownloadCommand($entry->getDownloadUrl())
            : $this->buildLinuxDownloadCommand($entry->getDownloadUrl());

        if ($os === 'windows') {
            $javaCheckPreamble = $this->buildWindowsJavaCheckPreamble($entry->getJavaVersion());
            $downloadCmd = $javaCheckPreamble . $downloadCmd;
        } else {
            $javaCheckPreamble = $this->buildLinuxJavaCheckPreamble($entry->getJavaVersion());
            $downloadCmd = $javaCheckPreamble . $downloadCmd;
        }

        return $downloadCmd;
    }

    private function resolveOs(Agent $node): string
    {
        $stats = $node->getLastHeartbeatStats();
        $os = is_array($stats) ? (string) ($stats['os'] ?? '') : '';
        $os = strtolower($os);

        if ($os === 'windows') {
            return 'windows';
        }

        return 'linux';
    }

    private function buildLinuxDownloadCommand(string $url): string
    {
        $escaped = escapeshellarg($url);

        return sprintf(
            'if command -v curl >/dev/null 2>&1; then curl -L -o server.jar %1$s; '
            . 'elif command -v wget >/dev/null 2>&1; then wget -O server.jar %1$s; '
            . 'else echo "Missing curl or wget." >&2; exit 1; fi',
            $escaped,
        );
    }

    private function buildWindowsDownloadCommand(string $url): string
    {
        $escaped = str_replace("'", "''", $url);

        return sprintf(
            'powershell -Command "Invoke-WebRequest -Uri \'%s\' -OutFile \'server.jar\'"',
            $escaped,
        );
    }


    private function buildLinuxBedrockCommand(string $url): string
    {
        $escaped = escapeshellarg($url);

        return sprintf(
            'if command -v curl >/dev/null 2>&1; then curl --http1.1 -L -A "Mozilla/5.0" -H "Accept: application/zip" -H "Referer: https://www.minecraft.net/en-us/download/server/bedrock" -o bedrock-server.zip %1$s; '
            . 'elif command -v wget >/dev/null 2>&1; then wget --user-agent="Mozilla/5.0" --referer="https://www.minecraft.net/en-us/download/server/bedrock" -O bedrock-server.zip %1$s; '
            . 'else echo "Missing curl or wget." >&2; exit 1; fi; '
            . 'unzip -o bedrock-server.zip && chmod +x bedrock_server',
            $escaped,
        );
    }

    private function buildWindowsBedrockCommand(string $url): string
    {
        $escaped = str_replace("'", "''", $url);

        return sprintf(
            'powershell -Command "Invoke-WebRequest -Uri \'%s\' -OutFile \'bedrock-server.zip\'; Expand-Archive -Force \'bedrock-server.zip\' ."',
            $escaped,
        );
    }

    private function buildMinecraftFallbackLinuxCommand(string $channel): string
    {
        return match ($channel) {
            'paper' => 'if ! command -v curl >/dev/null 2>&1; then echo "Missing curl." >&2; exit 1; fi; '
                . 'if ! command -v jq >/dev/null 2>&1; then echo "Missing jq." >&2; exit 1; fi; '
                . 'VERSION=$(curl -s https://api.papermc.io/v2/projects/paper | jq -r \'.versions | last\'); '
                . 'BUILD=$(curl -s https://api.papermc.io/v2/projects/paper/versions/$VERSION | jq -r \'.builds | last\'); '
                . 'JAR=$(curl -s https://api.papermc.io/v2/projects/paper/versions/$VERSION/builds/$BUILD | jq -r \'.downloads.application.name\'); '
                . 'curl -L -o server.jar https://api.papermc.io/v2/projects/paper/versions/$VERSION/builds/$BUILD/downloads/$JAR',
            default => 'if ! command -v curl >/dev/null 2>&1; then echo "Missing curl." >&2; exit 1; fi; '
                . 'if ! command -v jq >/dev/null 2>&1; then echo "Missing jq." >&2; exit 1; fi; '
                . 'URL=$(curl -s https://piston-meta.mojang.com/mc/game/version_manifest_v2.json '
                . '| jq -r \'.versions[] | select(.type=="release") | .url\' | head -n 1 '
                . '| xargs curl -s | jq -r \'.downloads.server.url\'); '
                . 'curl -L -o server.jar "$URL"',
        };
    }

    private function buildMinecraftFallbackWindowsCommand(string $channel): string
    {
        return match ($channel) {
            'paper' => 'powershell -Command "$ErrorActionPreference = \'Stop\'; '
                . '$version = (Invoke-RestMethod https://api.papermc.io/v2/projects/paper).versions[-1]; '
                . '$builds = Invoke-RestMethod https://api.papermc.io/v2/projects/paper/versions/$version; '
                . '$build = $builds.builds[-1]; '
                . '$buildInfo = Invoke-RestMethod https://api.papermc.io/v2/projects/paper/versions/$version/builds/$build; '
                . '$jar = $buildInfo.downloads.application.name; '
                . 'Invoke-WebRequest -Uri https://api.papermc.io/v2/projects/paper/versions/$version/builds/$build/downloads/$jar -OutFile server.jar"',
            default => 'powershell -Command "$ErrorActionPreference = \'Stop\'; '
                . '$manifest = Invoke-RestMethod https://piston-meta.mojang.com/mc/game/version_manifest_v2.json; '
                . '$latest = ($manifest.versions | Where-Object { $_.type -eq \'release\' } | Select-Object -First 1).url; '
                . '$details = Invoke-RestMethod $latest; '
                . '$url = $details.downloads.server.url; '
                . 'Invoke-WebRequest -Uri $url -OutFile server.jar"',
        };
    }

    private function isResolverPlaceholder(string $command, string $kind): bool
    {
        $normalized = strtolower(trim($command));
        if ($normalized === '') {
            return false;
        }

        $needle = $kind === 'update'
            ? 'update handled by catalog resolver'
            : 'install handled by catalog resolver';

        return str_contains($normalized, $needle);
    }

    private function prependSteamDumpCleanup(string $command, Instance $instance): string
    {
        if ($this->resolveOs($instance->getNode()) !== 'linux') {
            return $command;
        }

        if (stripos($command, 'steamcmd') === false || stripos($command, 'steamcmd.exe') !== false) {
            return $command;
        }

        if (str_contains($command, '/tmp/dumps')) {
            return $command;
        }

        return 'rm -rf /tmp/dumps /tmp/dumps-* 2>/dev/null || true; ' . $command;
    }

    private function applySteamLogin(string $command, Instance $instance): string
    {
        $account = trim((string) $instance->getSteamAccount());
        if ($account === '') {
            return $command;
        }

        $setupVars = $instance->getSetupVars();
        $password = trim((string) ($setupVars['STEAM_PASSWORD'] ?? ''));
        if ($password === '' || str_contains($command, '{{STEAM_ACCOUNT}}')) {
            return $command;
        }

        $replacement = '+login {{STEAM_ACCOUNT}} {{STEAM_PASSWORD}}';
        $updated = preg_replace('/\+login\s+anonymous\b/i', $replacement, $command, 1);

        return $updated ?? $command;
    }


    private function trans(string $key): string
    {
        return $this->translator?->trans($key, [], 'portal') ?? $key;
    }

    private function buildLinuxJavaCheckPreamble(?string $catalogJavaVersion = null): string
    {
        $config = $this->javaBinaryConfig ?? JavaBinaryConfig::defaults();

        $javaBin = ($catalogJavaVersion !== null && isset(MinecraftJavaVersionResolver::JAVA_BIN_BY_VERSION[$catalogJavaVersion]))
            ? $config->getBinForVersion($catalogJavaVersion)
            : '{{JAVA_BIN}}';

        $preamble = '';

        if ($config->autoInstallJava) {
            $preamble = $this->buildLinuxInstallAllJavaScript();
        }

        $escapedBin = escapeshellarg($javaBin);
        $preamble .= sprintf(
            'if ! command -v %1$s >/dev/null 2>&1 && [ ! -x %1$s ]; then'
            . ' echo "Required Java binary %2$s is missing on this node.'
            . ' Install the required Java version or ensure %2$s is in PATH." >&2; exit 1; fi; ',
            $escapedBin,
            $javaBin,
        );

        return $preamble;
    }

    private function buildLinuxInstallAllJavaScript(): string
    {
        // Installs Java 8, 17, 21 from standard apt/dnf/yum repos.
        // Runs only once per node (sentinel). Tries sudo -n when not running as root.
        return <<<'BASH'
_ewi_sentinel=/usr/local/share/easywi/.java-setup-done
if [ ! -f "$_ewi_sentinel" ]; then
  _ewi_sudo=""
  [ "$(id -u)" != "0" ] && command -v sudo >/dev/null 2>&1 && _ewi_sudo="sudo -n"
  if command -v apt-get >/dev/null 2>&1; then
    DEBIAN_FRONTEND=noninteractive $_ewi_sudo apt-get update -qq 2>&1 | tail -2
    DEBIAN_FRONTEND=noninteractive $_ewi_sudo apt-get install -y -qq openjdk-21-jre-headless openjdk-17-jre-headless 2>&1 | tail -3
    DEBIAN_FRONTEND=noninteractive $_ewi_sudo apt-get install -y -qq openjdk-8-jre-headless 2>&1 | tail -1 || true
  elif command -v dnf >/dev/null 2>&1 || command -v yum >/dev/null 2>&1; then
    _ewi_pkg="$(command -v dnf >/dev/null 2>&1 && echo dnf || echo yum)"
    $_ewi_sudo $_ewi_pkg install -y java-21-openjdk-headless java-17-openjdk-headless 2>&1 | tail -3
    $_ewi_sudo $_ewi_pkg install -y java-1.8.0-openjdk-headless 2>&1 | tail -1 || true
  fi
  for _ewi_jv in 8 17 21; do
    command -v "java${_ewi_jv}" >/dev/null 2>&1 && continue
    _ewi_f="$(find /usr/lib/jvm /usr/local/lib/jvm -maxdepth 7 -name java \
      \( -path "*java-${_ewi_jv}-*" -o -path "*java-${_ewi_jv}/*" \
         -o -path "*jdk-${_ewi_jv}*" -o -path "*1.${_ewi_jv}.0*" \) \
      -type f 2>/dev/null | head -1)"
    [ -n "$_ewi_f" ] && { $_ewi_sudo ln -sf "$_ewi_f" "/usr/local/bin/java${_ewi_jv}" 2>/dev/null || true; }
  done
  $_ewi_sudo mkdir -p /usr/local/share/easywi 2>/dev/null \
    && $_ewi_sudo touch "$_ewi_sentinel" 2>/dev/null || true
fi

BASH;
    }

    private function buildWindowsJavaCheckPreamble(?string $catalogJavaVersion = null): string
    {
        $config = $this->javaBinaryConfig ?? JavaBinaryConfig::defaults();

        $javaBin = ($catalogJavaVersion !== null && isset(MinecraftJavaVersionResolver::JAVA_BIN_BY_VERSION[$catalogJavaVersion]))
            ? $config->getBinForVersion($catalogJavaVersion)
            : '{{JAVA_BIN}}';

        $preamble = '';

        if ($config->autoInstallJava) {
            $preamble = $this->buildWindowsInstallAllJavaScript();
        }

        $escapedBin = str_replace("'", "''", $javaBin);
        $preamble .= sprintf(
            'if (-not (Get-Command %1$s -ErrorAction SilentlyContinue) -and (-not (Test-Path %1$s))) {'
            . ' Write-Error "Required Java binary %2$s is missing on this node.'
            . ' Install the required Java version or ensure %2$s is in PATH."; exit 1 }; ',
            "'$escapedBin'",
            $javaBin,
        );

        return $preamble;
    }

    private function buildWindowsInstallAllJavaScript(): string
    {
        // Installs all 4 Java versions on Windows using winget (Win 10 1709+ / Server 2019+).
        // Falls back to direct MSI/zip download via Adoptium API.
        // Sentinel prevents re-running on every install.
        return <<<'PS1'
$_ewi_sentinel = "$env:ProgramData\easywi\.java-setup-done"
if (-not (Test-Path $_ewi_sentinel)) {
  $ErrorActionPreference = 'Continue'
  $javaVersions = @(
    @{ version = '8';  adoptiumId = '8';  wingetId = 'EclipseAdoptium.Temurin.8.JRE'  },
    @{ version = '17'; adoptiumId = '17'; wingetId = 'EclipseAdoptium.Temurin.17.JRE' },
    @{ version = '21'; adoptiumId = '21'; wingetId = 'EclipseAdoptium.Temurin.21.JRE' }
  )
  foreach ($jv in $javaVersions) {
    $binName = "java$($jv.version)"
    if (Get-Command $binName -ErrorAction SilentlyContinue) { continue }
    $installed = $false
    if (Get-Command winget -ErrorAction SilentlyContinue) {
      winget install --id $jv.wingetId --silent --accept-package-agreements --accept-source-agreements 2>&1 | Select-Object -Last 3
      $installed = $?
    }
    if (-not $installed) {
      try {
        $api = "https://api.adoptium.net/v3/assets/latest/$($jv.adoptiumId)/hotspot?os=windows&architecture=x64&image_type=jre"
        $info = Invoke-RestMethod $api -ErrorAction Stop | Select-Object -First 1
        $url  = $info.binary.installer.link
        $ext  = if ($url -match '\.zip$') { 'zip' } else { 'msi' }
        $tmp  = "$env:TEMP\temurin-$($jv.version).$ext"
        Invoke-WebRequest $url -OutFile $tmp -UseBasicParsing
        if ($ext -eq 'msi') {
          Start-Process msiexec -ArgumentList "/i `"$tmp`" /quiet ADDLOCAL=FeatureMain" -Wait -NoNewWindow
        } else {
          Expand-Archive $tmp -DestinationPath "$env:ProgramFiles\Java" -Force
        }
        Remove-Item $tmp -Force -ErrorAction SilentlyContinue
      } catch { Write-Warning "Could not install Java $($jv.version): $_" }
    }
    $jvmPath = (Get-ChildItem "$env:ProgramFiles\Java","$env:ProgramFiles\Eclipse Adoptium","$env:ProgramFiles\Microsoft","C:\Program Files\Eclipse Adoptium" -Filter java.exe -Recurse -ErrorAction SilentlyContinue |
      Where-Object { $_.FullName -match "jdk-$($jv.adoptiumId)\.|jre-$($jv.adoptiumId)\.|$($jv.adoptiumId)\." } |
      Select-Object -First 1)?.FullName
    if ($jvmPath) {
      $link = "$env:SystemRoot\System32\java$($jv.version).cmd"
      Set-Content $link "@echo off`r`n`"$jvmPath`" %*" -Encoding ASCII
    }
  }
  $null = New-Item -ItemType Directory -Force (Split-Path $_ewi_sentinel)
  $null = New-Item -ItemType File -Force $_ewi_sentinel
}
PS1;
    }
}
