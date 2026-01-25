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

        if ($entry === null) {
            throw new \RuntimeException('Minecraft catalog entry not found.');
        }

        $os = $this->resolveOs($instance->getNode());

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
