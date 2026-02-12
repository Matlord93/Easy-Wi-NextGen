<?php

declare(strict_types=1);

namespace App\Module\Setup\Application;

/** @deprecated use KeyMaterialGenerator */
final class InstallEnvKeyGenerator
{
    private readonly KeyMaterialGenerator $generator;

    public function __construct(?KeyMaterialGenerator $generator = null)
    {
        $this->generator = $generator ?? new KeyMaterialGenerator();
    }

    public function generateAppSecret(): string
    {
        return $this->generator->generateAppSecret();
    }

    /**
     * @return array{active_key_id: string, keyset: string}
     */
    public function generateEncryptionKeyset(): array
    {
        $payload = $this->generator->generateEncryptionKeyset();

        return [
            'active_key_id' => $payload['activeKid'],
            'keyset' => $this->generator->buildCsvKeyset($payload['keys']),
        ];
    }
}
