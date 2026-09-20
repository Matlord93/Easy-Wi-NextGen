<?php

declare(strict_types=1);

namespace App\Module\Nodes\Domain\Proxmox;

final readonly class ProxmoxConnection
{
    public function __construct(
        public string $baseUrl,
        public string $tokenId,
        #[\SensitiveParameter]
        public string $tokenSecret,
        public bool $verifyTls = true,
    ) {
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('A valid Proxmox base URL is required.');
        }

        if ('https' !== parse_url($baseUrl, PHP_URL_SCHEME)) {
            throw new \InvalidArgumentException('Proxmox connections must use HTTPS.');
        }

        if ('' === trim($tokenId) || !str_contains($tokenId, '!')) {
            throw new \InvalidArgumentException('The Proxmox token ID must use the user@realm!token format.');
        }

        if ('' === trim($tokenSecret)) {
            throw new \InvalidArgumentException('A Proxmox API token secret is required.');
        }
    }
}
