<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

use App\Module\Gameserver\Domain\DockerGameTemplate;

final class DockerGameTemplateCatalog
{
    /** @return array<string, DockerGameTemplate> */
    public function all(): array
    {
        return [
            'minecraft' => new DockerGameTemplate('minecraft', 'Minecraft Java', 'itzg/minecraft-server:latest', ['game' => 25565], ['EULA' => 'TRUE', 'TYPE' => 'PAPER'], '/data', 'server.properties'),
            'fivem' => new DockerGameTemplate('fivem', 'FiveM', 'spritsail/fivem:latest', ['game_tcp' => 30120, 'game_udp' => 30120], [], '/config', 'server.cfg'),
            'cs2' => new DockerGameTemplate('cs2', 'Counter-Strike 2', 'cm2network/cs2:latest', ['game_tcp' => 27015, 'game_udp' => 27015], ['SRCDS_TOKEN' => ''], '/home/steam/cs2-dedicated', 'game/csgo/cfg/server.cfg'),
            'rust' => new DockerGameTemplate('rust', 'Rust', 'didstopia/rust-server:latest', ['game_udp' => 28015, 'query_udp' => 28016, 'rcon_tcp' => 28016], [], '/steamcmd/rust', 'server/rust/cfg/server.cfg'),
        ];
    }

    public function get(string $key): DockerGameTemplate
    {
        return $this->all()[$key] ?? throw new \InvalidArgumentException(sprintf('Unknown Docker game template "%s".', $key));
    }
}
