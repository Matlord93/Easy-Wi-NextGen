<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

final class AgentCredentials
{
    public function __construct(
        private readonly string $agentId,
        private readonly string $secret,
    ) {
    }

    public function getAgentId(): string
    {
        return $this->agentId;
    }

    public function getSecret(): string
    {
        return $this->secret;
    }
}
