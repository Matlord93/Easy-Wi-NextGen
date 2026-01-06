<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditLog;

final class AuditLogHasher
{
    public function compute(?string $previousHash, AuditLog $auditLog): string
    {
        $payload = json_encode($auditLog->getPayload(), JSON_THROW_ON_ERROR);
        $actorId = $auditLog->getActor()?->getId() ?? 0;
        $data = implode('|', [
            $previousHash ?? '',
            $auditLog->getAction(),
            $payload,
            (string) $actorId,
            $auditLog->getCreatedAt()->format(DATE_ATOM),
        ]);

        return hash('sha256', $data);
    }
}
