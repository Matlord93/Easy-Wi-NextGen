<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

final class AgentEndpoint
{
    public function __construct(
        private readonly string $baseUrl,
    ) {
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }
}
