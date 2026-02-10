<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\AuditLog;
use App\Module\Core\Domain\Entity\User;
use App\Repository\AuditLogRepository;
use Doctrine\ORM\EntityManagerInterface;

final class AuditLogger
{
    private const MAX_PAYLOAD_JSON_BYTES = 65535;

    public function __construct(
        private readonly AuditLogRepository $auditLogRepository,
        private readonly AuditLogHasher $auditLogHasher,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function log(?User $actor, string $action, array $payload): AuditLog
    {
        $auditLog = new AuditLog($actor, $action, $this->normalizePayload($payload));
        $previousHash = $this->auditLogRepository->findLatestHash();

        $auditLog->setHashPrev($previousHash);
        $auditLog->setHashCurrent($this->auditLogHasher->compute($previousHash, $auditLog));

        $this->entityManager->persist($auditLog);

        return $auditLog;
    }

    private function normalizePayload(array $payload): array
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($encoded)) {
            return [
                '_error' => 'payload_not_serializable',
            ];
        }

        $bytes = strlen($encoded);
        if ($bytes <= self::MAX_PAYLOAD_JSON_BYTES) {
            return $payload;
        }

        return [
            '_truncated' => true,
            '_max_bytes' => self::MAX_PAYLOAD_JSON_BYTES,
            '_original_bytes' => $bytes,
            'preview' => mb_substr($encoded, 0, 4000),
        ];
    }
}
