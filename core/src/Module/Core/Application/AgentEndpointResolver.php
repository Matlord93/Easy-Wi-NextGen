<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\SinusbotNode;

final class AgentEndpointResolver
{
    public function resolveForNode(SinusbotNode $node): AgentEndpoint
    {
        return $this->resolveForAgent($node->getAgent());
    }

    public function resolveForAgent(Agent $agent): AgentEndpoint
    {
        $baseUrl = $agent->getServiceBaseUrl();
        if ($baseUrl === '') {
            throw new AgentConfigurationException('Agent-Konfiguration unvollständig: Service-URL fehlt.');
        }

        return new AgentEndpoint($baseUrl);
    }
}
