<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\AgentBootstrapToken;
use Doctrine\DBAL\LockMode;

interface AgentBootstrapTokenRepositoryInterface
{
    public function findActiveByHash(string $tokenHash): ?AgentBootstrapToken;

    /** @return AgentBootstrapToken[] */
    public function findRecent(int $limit): array;

    /** @return AgentBootstrapToken|null */
    public function find(mixed $id, LockMode|int|null $lockMode = null, ?int $lockVersion = null): ?object;
}
