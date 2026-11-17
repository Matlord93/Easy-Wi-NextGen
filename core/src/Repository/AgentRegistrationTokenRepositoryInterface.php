<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\AgentRegistrationToken;

interface AgentRegistrationTokenRepositoryInterface
{
    public function findActiveByHash(string $tokenHash): ?AgentRegistrationToken;
}
