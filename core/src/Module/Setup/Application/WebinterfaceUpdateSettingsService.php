<?php

declare(strict_types=1);

namespace App\Module\Setup\Application;

use App\Module\Core\Application\AgentReleaseChecker;
use App\Module\Core\Application\CoreReleaseChecker;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class WebinterfaceUpdateSettingsService
{
    private const DEFAULT_SETTINGS = [
        'autoEnabled' => false,
        'autoMigrate' => true,
        'coreChannel' => CoreReleaseChecker::CHANNEL_STABLE,
        'agentChannel' => AgentReleaseChecker::CHANNEL_STABLE,
    ];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array{autoEnabled: bool, autoMigrate: bool, coreChannel: string, agentChannel: string}
     */
    public function getSettings(): array
    {
        $settings = $this->readSettings();

        return [
            'autoEnabled' => (bool) ($settings['autoEnabled'] ?? self::DEFAULT_SETTINGS['autoEnabled']),
            'autoMigrate' => (bool) ($settings['autoMigrate'] ?? self::DEFAULT_SETTINGS['autoMigrate']),
            'coreChannel' => $this->normalizeChannel((string) ($settings['coreChannel'] ?? self::DEFAULT_SETTINGS['coreChannel'])),
            'agentChannel' => $this->normalizeChannel((string) ($settings['agentChannel'] ?? self::DEFAULT_SETTINGS['agentChannel'])),
        ];
    }

    public function getCoreChannel(): string
    {
        return $this->getSettings()['coreChannel'];
    }

    public function getAgentChannel(): string
    {
        return $this->getSettings()['agentChannel'];
    }

    public function isAutoMigrateEnabled(): bool
    {
        return $this->getSettings()['autoMigrate'];
    }

    public function setAutoEnabled(bool $enabled): void
    {
        $settings = $this->readSettings();
        $settings['autoEnabled'] = $enabled;
        $settings['updatedAt'] = (new \DateTimeImmutable())->format(DATE_ATOM);
        $this->writeSettings($settings);
    }

    public function setAutoMigrate(bool $enabled): void
    {
        $settings = $this->readSettings();
        $settings['autoMigrate'] = $enabled;
        $settings['updatedAt'] = (new \DateTimeImmutable())->format(DATE_ATOM);
        $this->writeSettings($settings);
    }

    public function setCoreChannel(string $channel): void
    {
        $settings = $this->readSettings();
        $settings['coreChannel'] = $this->normalizeChannel($channel);
        $settings['updatedAt'] = (new \DateTimeImmutable())->format(DATE_ATOM);
        $this->writeSettings($settings);
    }

    public function setAgentChannel(string $channel): void
    {
        $settings = $this->readSettings();
        $settings['agentChannel'] = $this->normalizeChannel($channel);
        $settings['updatedAt'] = (new \DateTimeImmutable())->format(DATE_ATOM);
        $this->writeSettings($settings);
    }

    private function normalizeChannel(string $channel): string
    {
        return match (strtolower(trim($channel))) {
            CoreReleaseChecker::CHANNEL_BETA => CoreReleaseChecker::CHANNEL_BETA,
            CoreReleaseChecker::CHANNEL_ALPHA => CoreReleaseChecker::CHANNEL_ALPHA,
            default => CoreReleaseChecker::CHANNEL_STABLE,
        };
    }

    private function readSettings(): array
    {
        $path = $this->settingsPath();
        if (!is_file($path)) {
            return self::DEFAULT_SETTINGS;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return self::DEFAULT_SETTINGS;
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return self::DEFAULT_SETTINGS;
        }

        return array_merge(self::DEFAULT_SETTINGS, $decoded);
    }

    private function writeSettings(array $settings): void
    {
        $path = $this->settingsPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $payload = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new \RuntimeException('Unable to encode update settings.');
        }

        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmp, $payload . "\n") === false) {
            throw new \RuntimeException('Unable to write update settings.');
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to persist update settings.');
        }
    }

    private function settingsPath(): string
    {
        return $this->projectDir . '/srv/setup/config/webinterface_update.json';
    }
}
