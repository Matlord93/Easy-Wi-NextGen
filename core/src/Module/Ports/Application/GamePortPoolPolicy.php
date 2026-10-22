<?php

declare(strict_types=1);

namespace App\Module\Ports\Application;

use App\Module\Core\Domain\Entity\Template;
use App\Module\Ports\Domain\Entity\PortPool;

final class GamePortPoolPolicy
{
    public static function select(array $pools, Template $template): array
    {
        $family = in_array($template->getInstallResolver()['type'] ?? '', ['minecraft_vanilla', 'papermc_paper', 'minecraft_spigot', 'minecraft_bedrock', 'minecraft_bungeecord'], true) ? 'minecraft' : 'other';
        foreach (['game:'.$template->getGameKey(), $family, 'all'] as $scope) {
            // A configured dedicated pool never spills over into general ranges,
            // including when the administrator has disabled that pool.
            $matching = array_filter($pools, static fn (PortPool $pool): bool => $pool->getGameScope() === $scope);
            if ([] !== $matching) {
                return array_values(array_filter($matching, static fn (PortPool $pool): bool => $pool->isEnabled()));
            }
        }

        return [];
    }

    public static function allows(PortPool $pool, Template $template, array $allPools): bool
    {
        return in_array($pool, self::select($allPools, $template), true);
    }
}
