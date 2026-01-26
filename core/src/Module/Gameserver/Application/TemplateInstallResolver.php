<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Instance;

final class TemplateInstallResolver
{
    public function __construct(
        private readonly MinecraftCatalogService $catalogService,
    ) {
    }

    public function resolveInstallCommand(Instance $instance): string
    {
        $template = $instance->getTemplate();
        $resolver = $template->getInstallResolver();
        $type = is_array($resolver) ? (string) ($resolver['type'] ?? '') : '';

        $command = match ($type) {
            'minecraft_vanilla' => $this->resolveMinecraftCommand($instance, 'vanilla'),
            'papermc_paper' => $this->resolveMinecraftCommand($instance, 'paper'),
            default => $template->getInstallCommand(),
        };

        return $this->applySteamLogin($command, $instance);
    }

    public function resolveUpdateCommand(Instance $instance): string
    {
        $template = $instance->getTemplate();
        $resolver = $template->getInstallResolver();
        $type = is_array($resolver) ? (string) ($resolver['type'] ?? '') : '';

        $command = match ($type) {
            'minecraft_vanilla' => $this->resolveMinecraftCommand($instance, 'vanilla'),
            'papermc_paper' => $this->resolveMinecraftCommand($instance, 'paper'),
            default => $template->getUpdateCommand(),
        };

        return $this->applySteamLogin($command, $instance);
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
            return $os === 'windows'
                ? $this->buildMinecraftFallbackWindowsCommand($channel)
                : $this->buildMinecraftFallbackLinuxCommand($channel);
        }

        return $os === 'windows'
            ? $this->buildWindowsDownloadCommand($entry->getDownloadUrl())
            : $this->buildLinuxDownloadCommand($entry->getDownloadUrl());
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
}
