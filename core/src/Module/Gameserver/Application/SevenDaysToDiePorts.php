<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

use App\Module\Core\Domain\Entity\Template;
use App\Module\Ports\Domain\Entity\PortBlock;

final class SevenDaysToDiePorts
{
    public static function usesDefaultConfig(Template $template): bool
    {
        return 'seven_days_to_die' === $template->getGameKey()
            && 1 === preg_match('/^\{\{INSTANCE_DIR\}\}\/startserver\.sh\s+-configfile=serverconfig\.xml(?:\s+-(?:quit|batchmode|nographics|dedicated))*$/D', trim($template->getStartParams()));
    }

    public static function reservedGamePort(Template $template, ?PortBlock $block): ?int
    {
        return self::usesDefaultConfig($template) ? $block?->getStartPort() : null;
    }

    /**
     * @param list<array{role:string,protocol:string,port:int}> $reservations
     * @return list<array{role:string,protocol:string,port:int}>
     */
    public static function withTcpServices(Template $template, array $reservations): array
    {
        if (!self::usesDefaultConfig($template)) {
            return $reservations;
        }
        $ports = array_column($reservations, 'port', 'role');
        if (3 !== count($ports) || !isset($ports['game'], $ports['query'], $ports['rcon'])
            || $ports['query'] !== $ports['game'] + 1 || $ports['rcon'] !== $ports['game'] + 2) {
            return $reservations;
        }

        // The UDP followers already belong to the instance's exclusive block.
        // TCP listeners can use the same numbers as UDP without a conflict.
        foreach ($reservations as $index => $reservation) {
            if ('rcon' === $reservation['role']) {
                $reservations[$index] = ['role' => 'litenetlib', 'protocol' => 'udp', 'port' => $reservation['port']];
            }
        }
        $reservations[] = ['role' => 'game_tcp', 'protocol' => 'tcp', 'port' => $ports['game']];
        $reservations[] = ['role' => 'webdashboard', 'protocol' => 'tcp', 'port' => $ports['query']];
        $reservations[] = ['role' => 'telnet', 'protocol' => 'tcp', 'port' => $ports['rcon']];

        return $reservations;
    }
}
