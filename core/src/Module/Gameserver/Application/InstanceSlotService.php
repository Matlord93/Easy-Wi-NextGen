<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

use App\Module\Core\Domain\Entity\Instance;

final class InstanceSlotService
{
    public function enforceSlots(Instance $instance, ?int $requestedSlots): int
    {
        $maxSlots = $instance->getMaxSlots();
        $current = $instance->getCurrentSlots();

        if ($instance->isLockSlots()) {
            $instance->setCurrentSlots($maxSlots);
            return $maxSlots;
        }

        if ($requestedSlots === null) {
            $requestedSlots = $current;
        }

        if ($requestedSlots > $maxSlots) {
            $requestedSlots = $maxSlots;
        }

        $instance->setCurrentSlots($requestedSlots);

        return $requestedSlots;
    }
}
