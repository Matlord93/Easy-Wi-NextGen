<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\AuditLog;
use App\Module\Core\Domain\Entity\User;
use App\Repository\AuditLogRepository;
use Doctrine\ORM\EntityManagerInterface;

final class AuditLogger
{
    public function __construct(
        private readonly AuditLogRepository $auditLogRepository,
        private readonly AuditLogHasher $auditLogHasher,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function log(?User $actor, string $action, array $payload): AuditLog
    {
        $auditLog = new AuditLog($actor, $action, $payload);
        $previousHash = $this->auditLogRepository->findLatestHash();

        $auditLog->setHashPrev($previousHash);
        $auditLog->setHashCurrent($this->auditLogHasher->compute($previousHash, $auditLog));

        $this->entityManager->persist($auditLog);

        return $auditLog;
    }
}
