<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

interface MusicbotSecretConfigServiceInterface
{
    /**
     * @param array<string, string> $rawSecrets
     * @return array<string, string>
     */
    public function encrypt(array $rawSecrets): array;

    /**
     * @param array<string, mixed> $existingEncrypted
     * @param array<string, mixed> $updates
     * @return array<string, string>
     */
    public function mergeSecretUpdates(array $existingEncrypted, array $updates): array;

    /**
     * @param array<string, mixed> $secrets
     * @return array<string, string>
     */
    public function mask(array $secrets): array;
}
