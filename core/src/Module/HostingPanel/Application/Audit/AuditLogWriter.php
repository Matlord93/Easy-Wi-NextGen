<?php

declare(strict_types=1);

namespace App\Module\HostingPanel\Application\Audit;

use App\Module\HostingPanel\Domain\Entity\AuditLog;
use Doctrine\ORM\EntityManagerInterface;

class AuditLogWriter
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function write(string $actor, string $action, string $targetType, string $targetId, array $before, array $after): void
    {
        $this->entityManager->persist(new AuditLog($actor, $action, $targetType, $targetId, $before, $after));
    }
}
