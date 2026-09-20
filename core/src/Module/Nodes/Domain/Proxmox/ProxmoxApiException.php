<?php

declare(strict_types=1);

namespace App\Module\Nodes\Domain\Proxmox;

final class ProxmoxApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly ?string $requestPath = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
