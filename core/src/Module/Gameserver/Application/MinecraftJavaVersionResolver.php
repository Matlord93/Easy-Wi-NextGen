<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

final class MinecraftJavaVersionResolver
{
    public const JAVA_BIN_BY_VERSION = [
        '8' => 'java8',
        '16' => 'java16',
        '17' => 'java17',
        '21' => 'java21',
    ];

    public function resolve(?string $mcVersion, ?string $configuredJavaVersion = null): string
    {
        $configured = trim((string) $configuredJavaVersion);
        if (in_array($configured, ['8', '16', '17', '21'], true)) {
            return $configured;
        }

        $version = trim((string) $mcVersion);
        if ($version === '' || strtolower($version) === 'latest') {
            return '21';
        }

        if (!preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?/', $version, $matches)) {
            return '21';
        }

        $major = (int) $matches[1];
        $minor = (int) $matches[2];
        $patch = isset($matches[3]) ? (int) $matches[3] : 0;

        if ($major === 1 && ($minor < 17 || ($minor === 16 && $patch <= 5))) {
            return '8';
        }
        if ($major === 1 && $minor === 17) {
            return '16';
        }
        if ($major === 1 && ($minor < 20 || ($minor === 20 && $patch <= 4))) {
            return '17';
        }

        return '21';
    }

    public function javaBin(?string $mcVersion, ?string $configuredJavaVersion = null): string
    {
        return self::JAVA_BIN_BY_VERSION[$this->resolve($mcVersion, $configuredJavaVersion)] ?? 'java21';
    }
}
