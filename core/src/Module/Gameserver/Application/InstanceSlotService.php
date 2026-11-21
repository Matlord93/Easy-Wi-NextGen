<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

use App\Module\Core\Domain\Entity\Instance;

final class InstanceSlotService
{
    /**
     * @param array<string, string> $variables
     * @return array<string, string>
     */
    public static function applyStartupVariables(Instance $instance, array $variables): array
    {
        $startParams = $instance->getTemplate()->getStartParams();
        foreach (['MAX_PLAYERS', 'MAXPLAYERS', 'SLOTS', 'SV_MAXPLAYERS'] as $key) {
            if (isset($variables[$key]) || str_contains($startParams, '{{'.$key.'}}')) {
                $variables[$key] = (string) $instance->getCurrentSlots();
            }
        }

        return $variables;
    }

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
