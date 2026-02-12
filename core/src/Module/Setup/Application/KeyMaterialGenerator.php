<?php

declare(strict_types=1);

namespace App\Module\Setup\Application;

final class KeyMaterialGenerator
{
    public function generateAppSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * @return array{activeKid: string, keys: array<string, string>}
     */
    public function generateEncryptionKeyset(): array
    {
        $activeKid = 'v1';

        return [
            'activeKid' => $activeKid,
            'keys' => [
                $activeKid => base64_encode(random_bytes(32)),
            ],
        ];
    }

    /**
     * @param array<string, string> $keys
     */
    public function buildCsvKeyset(array $keys): string
    {
        $entries = [];
        foreach ($keys as $kid => $material) {
            $kid = trim($kid);
            $material = trim($material);
            if ($kid === '' || $material === '') {
                continue;
            }

            $entries[] = sprintf('%s:%s', $kid, $material);
        }

        return implode(',', $entries);
    }
}
