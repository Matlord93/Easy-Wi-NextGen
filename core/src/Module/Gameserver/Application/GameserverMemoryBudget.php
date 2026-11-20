<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

/** Customer RAM is the Java heap; native JVM allocations need their own process reserve. */
final class GameserverMemoryBudget
{
    public static function isJava(string $resolver): bool
    {
        return in_array($resolver, ['minecraft_vanilla', 'papermc_paper', 'minecraft_spigot', 'minecraft_bungeecord'], true);
    }

    public static function runtimeMiB(int $bookedMiB, string $resolver): int
    {
        return self::isJava($resolver) ? $bookedMiB + max(256, (int) ceil($bookedMiB / 4)) : $bookedMiB;
    }
}
