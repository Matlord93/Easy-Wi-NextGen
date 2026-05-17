<?php

declare(strict_types=1);

namespace App\Module\Setup\Application;

final class InstallEnvKeyGenerator
{
    public function __construct(
        private readonly KeyMaterialGenerator $keyMaterialGenerator = new KeyMaterialGenerator(),
    ) {
    }

    public function generateAppSecret(): string
    {
        return $this->keyMaterialGenerator->generateAppSecret();
    }

    /**
     * @return array{active_key_id: string, keyset: string}
     */
    public function generateEncryptionKeyset(): array
    {
        $keyset = $this->keyMaterialGenerator->generateEncryptionKeyset();

        return [
            'active_key_id' => $keyset['activeKid'],
            'keyset' => $this->keyMaterialGenerator->buildCsvKeyset($keyset['keys']),
        ];
    }
}
