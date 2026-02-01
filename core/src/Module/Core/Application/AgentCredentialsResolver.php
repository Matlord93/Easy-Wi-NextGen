<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Agent;

final class AgentCredentialsResolver
{
    public function __construct(private readonly EncryptionService $encryptionService)
    {
    }

    public function resolveForAgent(Agent $agent): AgentCredentials
    {
        try {
            $secret = $this->encryptionService->decrypt($agent->getSecretPayload());
        } catch (\RuntimeException $exception) {
            throw new AgentConfigurationException('Agent-Konfiguration unvollständig: Secret konnte nicht gelesen werden.', previous: $exception);
        }

        if ($secret === '') {
            throw new AgentConfigurationException('Agent-Konfiguration unvollständig: Secret fehlt.');
        }

        return new AgentCredentials($agent->getId(), $secret);
    }
}
