<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\SinusbotNode;

interface AgentEndpointResolverInterface
{
    public function resolveForNode(SinusbotNode $node): AgentEndpoint;

    public function resolveForAgent(Agent $agent): AgentEndpoint;

    public function isLoopback(Agent $agent): bool;
}
