<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Enum\InstanceDiskState;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DiskEnforcementService
{
    public function __construct(
        private readonly NodeDiskProtectionService $nodeDiskProtectionService,
        private readonly InstanceDiskStateResolver $diskStateResolver,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function guardInstanceAction(Instance $instance, \DateTimeImmutable $now): ?string
    {
        if ($this->nodeDiskProtectionService->isProtectionActive($instance->getNode(), $now)) {
            return $this->translator->trans('disk_enforcement_node_block');
        }

        $state = $instance->getDiskState();
        if ($state === InstanceDiskState::OverLimit || $state === InstanceDiskState::HardBlock) {
            return $this->translator->trans('disk_enforcement_block');
        }

        return null;
    }

    public function guardNodeProvisioning(\App\Module\Core\Domain\Entity\Agent $node, \DateTimeImmutable $now): ?string
    {
        if ($this->nodeDiskProtectionService->isProtectionActive($node, $now)) {
            return $this->translator->trans('disk_enforcement_node_block');
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
            return $this->translator->trans('disk_enforcement_block');
        }

        return null;
    }

    public function getUsagePercent(Instance $instance): float
    {
        return $this->diskStateResolver->resolvePercent($instance);
    }
}
