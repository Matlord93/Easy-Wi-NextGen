<?php

declare(strict_types=1);

namespace App\Module\AgentOrchestrator\Application;

use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\Core\Domain\Entity\Agent;

interface AgentJobDispatcherInterface
{
    /** @param array<string, mixed> $payload */
    public function dispatch(Agent $node, string $type, array $payload): AgentJob;
}
