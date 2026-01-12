<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Instance;

final class InstanceFilesystemResolver
{
    public function resolveInstanceDir(Instance $instance, ?string $baseDir = null): string
    {
        $baseDir = $baseDir !== null && $baseDir !== '' ? $baseDir : $this->getDefaultBaseDir();
        $username = $this->buildInstanceUsername((string) $instance->getCustomer()->getId(), (string) $instance->getId());

        return rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $username;
    }

    private function getDefaultBaseDir(): string
    {
        $env = $_ENV['EASYWI_INSTANCE_BASE_DIR'] ?? $_SERVER['EASYWI_INSTANCE_BASE_DIR'] ?? null;
        if (is_string($env) && $env !== '') {
            return $env;
        }

        return '/home';
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
