<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Agent;

interface AgentCreatorInterface
{
    /** @param array{key_id:string,nonce:string,ciphertext:string} $secretPayload */
    public function create(string $agentId, array $secretPayload, ?string $name = null): Agent;
}
