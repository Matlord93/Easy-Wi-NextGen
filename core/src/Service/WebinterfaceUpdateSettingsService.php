<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class WebinterfaceUpdateSettingsService
{
    private const DEFAULT_SETTINGS = [
        'autoEnabled' => false,
    ];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array{autoEnabled: bool}
     */
    public function getSettings(): array
    {
        $settings = $this->readSettings();

        return [
            'autoEnabled' => (bool) ($settings['autoEnabled'] ?? self::DEFAULT_SETTINGS['autoEnabled']),
        ];
    }

    /**
     * @return array{autoEnabled: bool}
     */
    public function setAutoEnabled(bool $enabled): array
    {
        $settings = $this->readSettings();
        $settings['autoEnabled'] = $enabled;
        $settings['updatedAt'] = (new \DateTimeImmutable())->format(DATE_ATOM);
        $this->writeSettings($settings);

        return [
            'autoEnabled' => $enabled,
        ];
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
        return $this->projectDir . '/var/config/webinterface_update.json';
    }
}
