<?php

declare(strict_types=1);

namespace App\Module\Nodes\Domain\Proxmox;

final readonly class ProxmoxConnection implements \JsonSerializable
{
    public function __construct(
        public string $baseUrl,
        public string $tokenId,
        #[\SensitiveParameter]
        public string $tokenSecret,
        public bool $verifyTls = true,
    ) {
        if (strlen($baseUrl) > 255 || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('A valid Proxmox base URL is required.');
        }

        if ('https' !== parse_url($baseUrl, PHP_URL_SCHEME)) {
            throw new \InvalidArgumentException('Proxmox connections must use HTTPS.');
        }

        $parts = parse_url($baseUrl);
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new \InvalidArgumentException('Use the Proxmox HTTPS origin without credentials or a path.');
        }

        if (strlen($tokenId) > 190 || !preg_match('/^[^\s@!=]+@[^\s@!=]+![^\s!=]+$/D', $tokenId)) {
            throw new \InvalidArgumentException('The Proxmox token ID must use the user@realm!token format.');
        }

        if ('' === trim($tokenSecret) || preg_match('/[\x00-\x20\x7f]/', $tokenSecret)) {
            throw new \InvalidArgumentException('A Proxmox API token secret is required.');
        }
    }

    public function jsonSerialize(): array
    {
        return ['baseUrl' => $this->baseUrl, 'tokenId' => $this->tokenId, 'tokenSecret' => '[redacted]', 'verifyTls' => $this->verifyTls];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
