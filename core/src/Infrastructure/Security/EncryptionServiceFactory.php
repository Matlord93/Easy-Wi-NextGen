<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Module\Core\Application\EncryptionService;

final class EncryptionServiceFactory
{
    public function __construct(
        private readonly EncryptionKeyLoader $keyLoader,
    ) {
    }

    public function create(): EncryptionService
    {
        $keyring = $this->keyLoader->loadKeyring();

        return new EncryptionService(
            $keyring['active_key_id'],
            $keyring['keyring'],
        );
    }
}
