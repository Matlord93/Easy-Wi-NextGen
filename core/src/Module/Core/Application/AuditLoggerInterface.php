<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\AuditLog;
use App\Module\Core\Domain\Entity\User;

interface AuditLoggerInterface
{
    public function log(?User $actor, string $action, array $payload): ?AuditLog;
}
