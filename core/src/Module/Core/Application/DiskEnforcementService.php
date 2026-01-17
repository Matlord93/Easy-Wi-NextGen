<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Enum\InstanceDiskState;

final class DiskEnforcementService
{
    public const BLOCK_MESSAGE = 'Speicherlimit erreicht. Bitte Dateien löschen oder Speicher erhöhen.';
    public const NODE_BLOCK_MESSAGE = 'Die Node befindet sich im Disk Protect Mode. Aktionen sind vorübergehend blockiert.';

    public function __construct(
        private readonly NodeDiskProtectionService $nodeDiskProtectionService,
        private readonly InstanceDiskStateResolver $diskStateResolver,
    ) {
    }

    public function guardInstanceAction(Instance $instance, \DateTimeImmutable $now): ?string
    {
        if ($this->nodeDiskProtectionService->isProtectionActive($instance->getNode(), $now)) {
            return self::NODE_BLOCK_MESSAGE;
        }

        $state = $instance->getDiskState();
        if ($state === InstanceDiskState::OverLimit || $state === InstanceDiskState::HardBlock) {
            return self::BLOCK_MESSAGE;
        }

        return null;
    }

    public function guardNodeProvisioning(\App\Module\Core\Domain\Entity\Agent $node, \DateTimeImmutable $now): ?string
    {
        if ($this->nodeDiskProtectionService->isProtectionActive($node, $now)) {
            return self::NODE_BLOCK_MESSAGE;
        }

        return null;
    }

    public function guardUpload(Instance $instance, int $uploadBytes): ?string
    {
        $limit = $instance->getDiskLimitBytes();
        if ($limit <= 0) {
            return null;
        }

        $used = $instance->getDiskUsedBytes();
        if ($used + $uploadBytes > $limit) {
            return self::BLOCK_MESSAGE;
        }

        return null;
    }

    public function getUsagePercent(Instance $instance): float
    {
        return $this->diskStateResolver->resolvePercent($instance);
    }
}
