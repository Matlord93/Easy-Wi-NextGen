<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Instance;

final class InstanceFilesystemResolver
{
    public function __construct(
        private readonly AppSettingsService $settingsService,
    ) {
    }

    public function resolveInstanceDir(Instance $instance, ?string $baseDir = null): string
    {
        $baseDir = $baseDir !== null && $baseDir !== '' ? $baseDir : $this->getDefaultBaseDir();
        $username = $this->buildInstanceUsername((string) $instance->getCustomer()->getId(), (string) $instance->getId());

        return rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $username;
    }

    private function getDefaultBaseDir(): string
    {
        return $this->settingsService->getInstanceBaseDir();
    }

    private function buildInstanceUsername(string $customerId, string $instanceId): string
    {
        $sanitized = $this->sanitizeIdentifier($instanceId);
        if (strlen($sanitized) > 8) {
            $sanitized = substr($sanitized, 0, 8);
        }

        return sprintf('gs%s%s', $customerId, $sanitized);
    }

    private function sanitizeIdentifier(string $value): string
    {
        $value = strtolower($value);
        $result = '';
        $len = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $char = $value[$i];
            if (($char >= 'a' && $char <= 'z') || ($char >= '0' && $char <= '9')) {
                $result .= $char;
            }
        }

        return $result !== '' ? $result : 'instance';
    }
}
