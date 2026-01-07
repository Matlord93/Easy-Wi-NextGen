<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Agent;
use App\Entity\LogIndex;
use App\Entity\User;
use App\Repository\LogIndexRepository;
use Doctrine\ORM\EntityManagerInterface;

final class LogIndexManager
{
    public function __construct(
        private readonly LogIndexRepository $logIndexRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function upsertPointer(
        ?User $actor,
        ?Agent $agent,
        string $source,
        string $scopeType,
        string $scopeId,
        string $logName,
        string $filePath,
        int $byteOffset,
        ?\DateTimeImmutable $indexedAt = null,
    ): LogIndex {
        $index = $this->logIndexRepository->findIdentity($source, $scopeType, $scopeId, $logName, $agent);
        $isNew = $index === null;

        if ($isNew) {
            $index = new LogIndex($source, $scopeType, $scopeId, $logName, $filePath, $agent, $byteOffset);
            $this->entityManager->persist($index);
        } else {
            if ($index->getFilePath() !== $filePath) {
                $index->setFilePath($filePath);
            }
        }

        $index->markIndexed($byteOffset, $indexedAt);

        $this->auditLogger->log($actor, 'log_index.pointer_updated', [
            'log_index_id' => $index->getId(),
            'source' => $source,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'log_name' => $logName,
            'file_path' => $filePath,
            'byte_offset' => $byteOffset,
            'agent_id' => $agent?->getId(),
            'created' => $isNew,
        ]);

        return $index;
    }
}
