<?php

declare(strict_types=1);

namespace App\Module\HostingPanel\Application\Security;

use App\Module\HostingPanel\Domain\Entity\Agent;
use Doctrine\ORM\EntityManagerInterface;

class AgentTokenAuthenticator
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function authenticate(string $agentUuid, string $bearerToken): ?Agent
    {
        $agent = $this->entityManager->getRepository(Agent::class)->findOneBy(['agentUuid' => $agentUuid]);
        if (!$agent instanceof Agent) {
            return null;
        }

        $hash = hash('sha256', $bearerToken);

        return hash_equals($agent->getTokenHash(), $hash) ? $agent : null;
    }
}
