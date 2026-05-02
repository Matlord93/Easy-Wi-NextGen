<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

final class GameTemplateSeedCatalog
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function listTemplates(): array
    {
        $fastdl = $this->defaultFastdlSettings();

        return [
            $this->template(
                'cs2',
                'Counter-Strike 2 Dedicated Server',
                'SteamCMD install with Metamod + CounterStrikeSharp ready.',
                730,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ],
                '{{INSTANCE_DIR}}/game/cs2.sh -dedicated +ip 0.0.0.0 -port {{PORT_GAME}} -maxplayers {{MAX_PLAYERS}} +map {{MAP}} -tickrate {{TICKRATE}} +servercfgfile server.cfg -condebug +sv_logfile 1 +game_type {{GAME_TYPE}} +game_mode {{GAME_MODE}} +sv_setsteamaccount {{STEAM_GSLT}} -authkey {{AUTHKEY}} +host_workshop_map {{WORKSHOP_MAP}}',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi CS2'],
                    ['key' => 'SERVER_PASSWORD', 'value' => ''],
                    ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
                    ['key' => 'STEAM_GSLT', 'value' => ''],
                    ['key' => 'MAX_PLAYERS', 'value' => '16'],
                    ['key' => 'MAP', 'value' => 'de_dust2'],
                    ['key' => 'TICKRATE', 'value' => '128'],
                    ['key' => 'GAME_TYPE', 'value' => '0'],
                    ['key' => 'GAME_MODE', 'value' => '0'],
                    ['key' => 'AUTHKEY', 'value' => ''],
                    ['key' => 'WORKSHOP_MAP', 'value' => ''],
                ],
                [
                    [
                        'path' => 'game/csgo/cfg/server.cfg',
                        'description' => 'Base server configuration',
                        'contents' => "hostname \"{{SERVER_NAME}}\"\nrcon_password \"{{RCON_PASSWORD}}\"\nsv_password \"{{SERVER_PASSWORD}}\"                // optional: Passwort für Spieler (leer = öffentlich)\ngame_type {{GAME_TYPE}}\ngame_mode {{GAME_MODE}}\n",
                    ],
                ],
                [
                    'game/csgo/addons/metamod',
                    'game/csgo/addons/counterstrikesharp',
                ],
                $fastdl,
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 730 validate +quit',
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 730 +quit',
                ['+map', '-maxplayers', '+game_type', '+game_mode'],
            ),
            $this->template(
                'csgo_legacy',
                'Counter-Strike: Global Offensive (Legacy)',
                'Legacy CS:GO server for legacy mod stacks.',
                740,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                    ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
                    ['name' => 'tv', 'label' => 'SourceTV', 'protocol' => 'udp'],
                ],
                '{{INSTANCE_DIR}}/srcds_run -game csgo -console -usercon -tickrate 128 -port {{PORT_GAME}} +sv_queryport {{PORT_QUERY}} +rcon_port {{PORT_RCON}} +tv_port {{PORT_TV}} +map de_dust2 +sv_setsteamaccount {{STEAM_GSLT}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}" -authkey {{AUTHKEY}} +host_workshop_map {{WORKSHOP_MAP}}',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi CSGO'],
                    ['key' => 'SERVER_PASSWORD', 'value' => ''],
                    ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
                    ['key' => 'STEAM_GSLT', 'value' => ''],
                    ['key' => 'AUTHKEY', 'value' => ''],
                    ['key' => 'WORKSHOP_MAP', 'value' => ''],
                ],
                [
                    [
                        'path' => 'csgo/cfg/server.cfg',
                        'description' => 'Base server configuration',
                        'contents' => "hostname \"{{SERVER_NAME}}\"\nrcon_password \"{{RCON_PASSWORD}}\"\nsv_password \"{{SERVER_PASSWORD}}\"\nsv_lan 0\n",
                    ],
                ],
                [
                    'csgo/addons/metamod',
                    'csgo/addons/sourcemod',
                ],
                $fastdl,
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 740 validate +quit',
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 740 +quit',
                ['+map', '+maxplayers', '+game_type', '+game_mode'],
            ),
            $this->template(
                'rust',
                'Rust Dedicated Server',
                'SteamCMD install with optional Oxide/uMod hooks.',
                258550,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                    ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
                ],
                '{{INSTANCE_DIR}}/RustDedicated -batchmode +server.port {{PORT_GAME}} +server.queryport {{PORT_QUERY}} +rcon.port {{PORT_RCON}} +server.hostname "{{SERVER_NAME}}" +rcon.password "{{RCON_PASSWORD}}" +server.maxplayers 50',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Rust'],
                    ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
                ],
                [
                    [
                        'path' => 'server/cfg/server.cfg',
                        'description' => 'Server configuration overrides',
                    ],
                ],
                [
                    'oxide/plugins',
                ],
                $fastdl,
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 258550 validate +quit',
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 258550 +quit',
                ['+server.level', '+server.seed'],
            ),
            $this->template(
                'ark',
                'ARK: Survival Evolved',
                'SteamCMD install with default Linux server config paths.',
                376030,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                    ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
                ],
                '{{INSTANCE_DIR}}/ShooterGameServer TheIsland?SessionName={{SERVER_NAME}}?Port={{PORT_GAME}}?QueryPort={{PORT_QUERY}}?RCONPort={{PORT_RCON}}?MaxPlayers=70?listen -log',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi ARK'],
                    ['key' => 'ADMIN_PASSWORD', 'value' => 'change-me'],
                ],
                [
                    [
                        'path' => 'ShooterGame/Saved/Config/LinuxServer/GameUserSettings.ini',
                        'description' => 'Server session settings',
                    ],
                    [
                        'path' => 'ShooterGame/Saved/Config/LinuxServer/Game.ini',
                        'description' => 'Gameplay rules',
                    ],
                ],
                [],
                $fastdl,
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 376030 validate +quit',
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 376030 +quit',
                [],
            ),
            $this->template(
                'valheim',
                'Valheim Dedicated Server',
                'SteamCMD install with fixed start params.',
                896660,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ],
                '{{INSTANCE_DIR}}/valheim_server.x86_64 -name "{{SERVER_NAME}}" -port {{PORT_GAME}} -world "{{WORLD_NAME}}" -password "{{SERVER_PASSWORD}}" -public 1',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Valheim'],
                    ['key' => 'WORLD_NAME', 'value' => 'Dedicated'],
                    ['key' => 'SERVER_PASSWORD', 'value' => 'change-me'],
                ],
                [],
                [],
                $fastdl,
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 896660 validate +quit',
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 896660 +quit',
                [],
            ),
            $this->template(
                'satisfactory',
                'Satisfactory Dedicated Server',
                'SteamCMD install with default server settings paths.',
                1690800,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ],
                '{{INSTANCE_DIR}}/FactoryServer.sh -log -unattended',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Satisfactory'],
                ],
                [
                    [
                        'path' => 'FactoryGame/Saved/Config/LinuxServer/ServerSettings.ini',
                        'description' => 'Server settings',
                    ],
                ],
                [],
                $fastdl,
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 1690800 validate +quit',
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 1690800 +quit',
                [],
            ),
            $this->template(
                'palworld',
                'Palworld Dedicated Server',
                'SteamCMD install with default Palworld settings.',
                2394010,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ],
                '{{INSTANCE_DIR}}/PalServer.sh -useperfthreads -NoAsyncLoadingThread -UseMultithreadForDS -port {{PORT_GAME}} -queryport {{PORT_QUERY}} -servername "{{SERVER_NAME}}" -serverpassword "{{SERVER_PASSWORD}}"',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Palworld'],
                    ['key' => 'SERVER_PASSWORD', 'value' => 'change-me'],
                ],
                [
                    [
                        'path' => 'Pal/Saved/Config/LinuxServer/PalWorldSettings.ini',
                        'description' => 'Palworld server settings',
                    ],
                ],
                [],
                $fastdl,
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 2394010 validate +quit',
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 2394010 +quit',
                [],
            ),
            $this->template(
                'palworld_windows',
                'Palworld Dedicated Server (Windows)',
                'SteamCMD install with Windows server binary.',
                2394010,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ],
                '{{INSTANCE_DIR}}/PalServer.exe -port {{PORT_GAME}} -queryport {{PORT_QUERY}} -servername "{{SERVER_NAME}}" -serverpassword "{{SERVER_PASSWORD}}"',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Palworld'],
                    ['key' => 'SERVER_PASSWORD', 'value' => 'change-me'],
                ],
                [
                    [
                        'path' => 'Pal/Saved/Config/WindowsServer/PalWorldSettings.ini',
                        'description' => 'Palworld server settings',
                    ],
                ],
                [],
                $fastdl,
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 2394010 validate +quit',
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 2394010 +quit',
                [],
            ),
            $this->template(
                'valheim_windows',
                'Valheim Dedicated Server (Windows)',
                'SteamCMD install with Windows server executable.',
                896660,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ],
                '{{INSTANCE_DIR}}/valheim_server.exe -name "{{SERVER_NAME}}" -port {{PORT_GAME}} -world "{{WORLD_NAME}}" -password "{{SERVER_PASSWORD}}" -public 1',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Valheim'],
                    ['key' => 'WORLD_NAME', 'value' => 'Dedicated'],
                    ['key' => 'SERVER_PASSWORD', 'value' => 'change-me'],
                ],
                [],
                [],
                $fastdl,
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 896660 validate +quit',
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 896660 +quit',
                [],
            ),
            $this->template(
                'satisfactory_windows',
                'Satisfactory Dedicated Server (Windows)',
                'SteamCMD install with Windows server executable.',
                1690800,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ],
                '{{INSTANCE_DIR}}/FactoryServer.exe -log -unattended',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Satisfactory'],
                ],
                [
                    [
                        'path' => 'FactoryGame/Saved/Config/WindowsServer/ServerSettings.ini',
                        'description' => 'Server settings',
                    ],
                ],
                [],
                $fastdl,
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 1690800 validate +quit',
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 1690800 +quit',
                [],
            ),
            $this->template(
                'dayz',
                'DayZ Dedicated Server',
                'SteamCMD install with basic serverDZ.cfg.',
                223350,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ],
                '{{INSTANCE_DIR}}/DayZServer_x64 -config=serverDZ.cfg -port={{PORT_GAME}}',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi DayZ'],
                ],
                [
                    [
                        'path' => 'serverDZ.cfg',
                        'description' => 'Server configuration',
                    ],
                ],
                [
                    'mods',
                ],
                $fastdl,
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 223350 validate +quit',
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 223350 +quit',
                [],
            ),
            $this->template(
                'dayz_windows',
                'DayZ Dedicated Server (Windows)',
                'SteamCMD install with Windows server binary.',
                223350,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ],
                '{{INSTANCE_DIR}}/DayZServer_x64.exe -config=serverDZ.cfg -port={{PORT_GAME}}',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi DayZ'],
                ],
                [
                    [
                        'path' => 'serverDZ.cfg',
                        'description' => 'Server configuration',
                    ],
                ],
                [
                    'mods',
                ],
                $fastdl,
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 223350 validate +quit',
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 223350 +quit',
                [],
            ),
            $this->template(
                'v_rising',
                'V Rising Dedicated Server',
                'SteamCMD install with standard server config.',
                1829350,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ],
                '{{INSTANCE_DIR}}/VRisingServer -persistentDataPath ./save-data -serverName "{{SERVER_NAME}}"',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi V Rising'],
                ],
                [
                    [
                        'path' => 'save-data/Settings/ServerHostSettings.json',
                        'description' => 'Server host settings',
                    ],
                ],
                [],
                $fastdl,
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 1829350 validate +quit',
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 1829350 +quit',
                [],
            ),
            $this->template(
                'v_rising_windows',
                'V Rising Dedicated Server (Windows)',
                'SteamCMD install with Windows server binary.',
                1829350,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ],
                '{{INSTANCE_DIR}}/VRisingServer.exe -persistentDataPath .\\save-data -serverName "{{SERVER_NAME}}"',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi V Rising'],
                ],
                [
                    [
                        'path' => 'save-data/Settings/ServerHostSettings.json',
                        'description' => 'Server host settings',
                    ],
                ],
                [],
                $fastdl,
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 1829350 validate +quit',
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 1829350 +quit',
                [],
            ),
            $this->template(
                'enshrouded_windows',
                'Enshrouded Dedicated Server (Windows)',
                'SteamCMD install with Windows server binary.',
                2278520,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ],
                '{{INSTANCE_DIR}}/enshrouded_server.exe -log',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Enshrouded'],
                ],
                [
                    [
                        'path' => 'enshrouded_server.json',
                        'description' => 'Server configuration',
                    ],
                ],
                [],
                $fastdl,
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 2278520 validate +quit',
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 2278520 +quit',
                [],
            ),
            $this->template(
                'garrys_mod',
                "Garry's Mod Dedicated Server",
                'SteamCMD install with standard Source DS layout.',
                4020,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                    ['name' => 'tv', 'label' => 'SourceTV', 'protocol' => 'udp'],
                ],
                '{{INSTANCE_DIR}}/srcds_run -game garrysmod -console -port {{PORT_GAME}} +sv_queryport {{PORT_QUERY}} +tv_port {{PORT_TV}} +map gm_construct +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}" +sv_password "{{SERVER_PASSWORD}}" -authkey {{AUTHKEY}} +host_workshop_map {{WORKSHOP_MAP}}',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Garry\'s Mod'],
                    ['key' => 'MAX_PLAYERS', 'value' => '24'],
                    ['key' => 'SERVER_PASSWORD', 'value' => ''],
                    ['key' => 'AUTHKEY', 'value' => ''],
                    ['key' => 'WORKSHOP_MAP', 'value' => ''],
                ],
                [
                    [
                        'path' => 'garrysmod/cfg/server.cfg',
                        'description' => 'Base server configuration',
                        'contents' => "hostname \"{{SERVER_NAME}}\"\nsv_lan 0\n",
                    ],
                ],
                [
                    'garrysmod/addons',
                ],
                $fastdl,
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 4020 validate +quit',
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 4020 +quit',
                ['+map', '+maxplayers', '+game_type', '+game_mode'],
            ),
            $this->template(
                'terraria',
                'Terraria Dedicated Server',
                'SteamCMD install with config-based server setup.',
                105600,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ],
                '{{INSTANCE_DIR}}/TerrariaServer.bin.x86_64 -config serverconfig.txt',
                [
                    ['key' => 'WORLD_NAME', 'value' => 'EasyWiWorld'],
                    ['key' => 'MAX_PLAYERS', 'value' => '16'],
                    ['key' => 'SERVER_PASSWORD', 'value' => ''],
                ],
                [
                    [
                        'path' => 'serverconfig.txt',
                        'description' => 'Terraria server configuration',
                        'contents' => "world=worlds/{{WORLD_NAME}}.wld\nmaxplayers={{MAX_PLAYERS}}\nport={{PORT_GAME}}\npassword={{SERVER_PASSWORD}}\n",
                    ],
                ],
                [],
                $fastdl,
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 105600 validate +quit',
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 105600 +quit',
                [],
            ),
            $this->template(
                'seven_days_to_die',
                '7 Days to Die Dedicated Server',
                'SteamCMD install with XML-based configuration.',
                294420,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                    ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
                ],
                '{{INSTANCE_DIR}}/startserver.sh -configfile=serverconfig.xml -quit -batchmode -nographics -dedicated',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi 7DTD'],
                    ['key' => 'MAX_PLAYERS', 'value' => '12'],
                ],
                [
                    [
                        'path' => 'serverconfig.xml',
                        'description' => 'Server configuration',
                    ],
                ],
                [
                    'Mods',
                ],
                $fastdl,
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 294420 validate +quit',
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 294420 +quit',
                [],
            ),
            $this->template(
                'factorio',
                'Factorio Dedicated Server',
                'SteamCMD install with JSON server settings.',
                427520,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ],
                '{{INSTANCE_DIR}}/bin/x64/factorio --start-server save.zip --server-settings server-settings.json --port {{PORT_GAME}}',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Factorio'],
                ],
                [
                    [
                        'path' => 'server-settings.json',
                        'description' => 'Server settings',
                        'contents' => "{\n  \"name\": \"{{SERVER_NAME}}\",\n  \"description\": \"Easy-Wi Factorio Server\",\n  \"max_players\": 20\n}\n",
                    ],
                ],
                [],
                $fastdl,
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 427520 validate +quit',
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 427520 +quit',
                [],
            ),
            $this->template(
                'project_zomboid',
                'Project Zomboid Dedicated Server',
                'SteamCMD install with standard Linux server scripts.',
                380870,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ],
                '{{INSTANCE_DIR}}/start-server.sh -servername "{{SERVER_NAME}}" -adminpassword "{{ADMIN_PASSWORD}}"',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Zomboid'],
                    ['key' => 'ADMIN_PASSWORD', 'value' => 'change-me'],
                ],
                [
                    [
                        'path' => 'Zomboid/Server/server.ini',
                        'description' => 'Server configuration',
                    ],
                ],
                [
                    'Zomboid/mods',
                ],
                $fastdl,
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 380870 validate +quit',
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 380870 +quit',
                [],
            ),
            $this->template(
                'project_zomboid_windows',
                'Project Zomboid Dedicated Server (Windows)',
                'SteamCMD install with Windows server scripts.',
                380870,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ],
                '{{INSTANCE_DIR}}/StartServer64.bat -servername "{{SERVER_NAME}}" -adminpassword "{{ADMIN_PASSWORD}}"',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Zomboid'],
                    ['key' => 'ADMIN_PASSWORD', 'value' => 'change-me'],
                ],
                [
                    [
                        'path' => 'Zomboid/Server/server.ini',
                        'description' => 'Server configuration',
                    ],
                ],
                [
                    'Zomboid/mods',
                ],
                $fastdl,
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 380870 validate +quit',
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 380870 +quit',
                [],
            ),
            $this->template(
                'conan_exiles',
                'Conan Exiles Dedicated Server',
                'SteamCMD install with default Linux config paths.',
                443030,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ],
                '{{INSTANCE_DIR}}/ConanSandboxServer -log -Port={{PORT_GAME}} -QueryPort={{PORT_QUERY}}',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Conan Exiles'],
                ],
                [
                    [
                        'path' => 'ConanSandbox/Saved/Config/LinuxServer/ServerSettings.ini',
                        'description' => 'Server settings',
                    ],
                ],
                [
                    'ConanSandbox/Mods',
                ],
                $fastdl,
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 443030 validate +quit',
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 443030 +quit',
                [],
            ),
            $this->template(
                'conan_exiles_windows',
                'Conan Exiles Dedicated Server (Windows)',
                'SteamCMD install with Windows config paths.',
                443030,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ],
                '{{INSTANCE_DIR}}/ConanSandboxServer.exe -log -Port={{PORT_GAME}} -QueryPort={{PORT_QUERY}}',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Conan Exiles'],
                ],
                [
                    [
                        'path' => 'ConanSandbox/Saved/Config/WindowsServer/ServerSettings.ini',
                        'description' => 'Server settings',
                    ],
                ],
                [
                    'ConanSandbox/Mods',
                ],
                $fastdl,
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 443030 validate +quit',
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 443030 +quit',
                [],
            ),
            $this->template(
                'arma3',
                'Arma 3 Dedicated Server',
                'SteamCMD install with standard Linux server config.',
                233780,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ],
                '{{INSTANCE_DIR}}/arma3server_x64 -config=server.cfg -port={{PORT_GAME}} -name=server',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Arma 3'],
                ],
                [
                    [
                        'path' => 'server.cfg',
                        'description' => 'Server configuration',
                        'contents' => "hostname=\"{{SERVER_NAME}}\"\nmaxPlayers=40;\n",
                    ],
                ],
                [
                    'mods',
                ],
                $fastdl,
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 233780 validate +quit',
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 233780 +quit',
                [],
            ),
            $this->template(
                'arma3_windows',
                'Arma 3 Dedicated Server (Windows)',
                'SteamCMD install with Windows server config.',
                233780,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ],
                '{{INSTANCE_DIR}}/arma3server_x64.exe -config=server.cfg -port={{PORT_GAME}} -name=server',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Arma 3'],
                ],
                [
                    [
                        'path' => 'server.cfg',
                        'description' => 'Server configuration',
                        'contents' => "hostname=\"{{SERVER_NAME}}\"\nmaxPlayers=40;\n",
                    ],
                ],
                [
                    'mods',
                ],
                $fastdl,
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 233780 validate +quit',
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 233780 +quit',
                [],
            ),
            $this->template(
                'cs2_windows',
                'Counter-Strike 2 Dedicated Server (Windows)',
                'SteamCMD install with Windows server binary.',
                730,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ],
                '{{INSTANCE_DIR}}/game/bin/win64/cs2.exe -dedicated +ip 0.0.0.0 -port {{PORT_GAME}} -maxplayers {{MAX_PLAYERS}} +map {{MAP}} -tickrate {{TICKRATE}} +servercfgfile server.cfg -condebug +sv_logfile 1 +game_type {{GAME_TYPE}} +game_mode {{GAME_MODE}} +sv_setsteamaccount {{STEAM_GSLT}} -authkey {{AUTHKEY}} +host_workshop_map {{WORKSHOP_MAP}}',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi CS2'],
                    ['key' => 'SERVER_PASSWORD', 'value' => ''],
                    ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
                    ['key' => 'STEAM_GSLT', 'value' => ''],
                    ['key' => 'MAX_PLAYERS', 'value' => '16'],
                    ['key' => 'MAP', 'value' => 'de_dust2'],
                    ['key' => 'TICKRATE', 'value' => '128'],
                    ['key' => 'GAME_TYPE', 'value' => '0'],
                    ['key' => 'GAME_MODE', 'value' => '0'],
                    ['key' => 'AUTHKEY', 'value' => ''],
                    ['key' => 'WORKSHOP_MAP', 'value' => ''],
                ],
                [
                    [
                        'path' => 'game/csgo/cfg/server.cfg',
                        'description' => 'Base server configuration',
                        'contents' => "hostname \"{{SERVER_NAME}}\"\nrcon_password \"{{RCON_PASSWORD}}\"\nsv_password \"{{SERVER_PASSWORD}}\"                // optional: Passwort für Spieler (leer = öffentlich)\ngame_type {{GAME_TYPE}}\ngame_mode {{GAME_MODE}}\n",
                    ],
                ],
                [
                    'game/csgo/addons/metamod',
                    'game/csgo/addons/counterstrikesharp',
                ],
                $fastdl,
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 730 validate +quit',
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 730 +quit',
                ['+map', '-maxplayers', '+game_type', '+game_mode'],
            ),
            $this->template(
                'csgo_legacy_windows',
                'Counter-Strike: Global Offensive (Legacy, Windows)',
                'Legacy CSGO dedicated server template for Windows.',
                740,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                    ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
                    ['name' => 'tv', 'label' => 'SourceTV', 'protocol' => 'udp'],
                ],
                '{{INSTANCE_DIR}}/srcds.exe -game csgo -console -usercon -tickrate 128 -port {{PORT_GAME}} +sv_queryport {{PORT_QUERY}} +rcon_port {{PORT_RCON}} +tv_port {{PORT_TV}} +map de_dust2 +sv_setsteamaccount {{STEAM_GSLT}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}" -authkey {{AUTHKEY}} +host_workshop_map {{WORKSHOP_MAP}}',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi CSGO'],
                    ['key' => 'SERVER_PASSWORD', 'value' => ''],
                    ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
                    ['key' => 'STEAM_GSLT', 'value' => ''],
                    ['key' => 'AUTHKEY', 'value' => ''],
                    ['key' => 'WORKSHOP_MAP', 'value' => ''],
                ],
                [
                    [
                        'path' => 'csgo/cfg/server.cfg',
                        'description' => 'Base server configuration',
                        'contents' => "hostname \"{{SERVER_NAME}}\"\nrcon_password \"{{RCON_PASSWORD}}\"\nsv_password \"{{SERVER_PASSWORD}}\"\nsv_lan 0\n",
                    ],
                ],
                [
                    'csgo/addons/metamod',
                    'csgo/addons/sourcemod',
                ],
                $fastdl,
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 740 validate +quit',
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 740 +quit',
                ['+map', '+maxplayers'],
            ),
            $this->template(
                'tf2',
                'Team Fortress 2 Dedicated Server',
                'SteamCMD install with base server config.',
                232250,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                    ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
                ],
                '{{INSTANCE_DIR}}/srcds_run -game tf -port {{PORT_GAME}} +sv_queryport {{PORT_QUERY}} +rcon_port {{PORT_RCON}} +map ctf_2fort +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}" +sv_password "{{SERVER_PASSWORD}}" -authkey {{AUTHKEY}} +host_workshop_map {{WORKSHOP_MAP}}',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi TF2'],
                    ['key' => 'MAX_PLAYERS', 'value' => '24'],
                    ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
                    ['key' => 'SERVER_PASSWORD', 'value' => ''],
                    ['key' => 'AUTHKEY', 'value' => ''],
                    ['key' => 'WORKSHOP_MAP', 'value' => ''],
                ],
                [
                    [
                        'path' => 'tf/cfg/server.cfg',
                        'description' => 'Base server configuration',
                    ],
                ],
                [
                    'tf/addons/metamod',
                    'tf/addons/sourcemod',
                ],
                $fastdl,
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 232250 validate +quit',
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 232250 +quit',
                ['+map', '+maxplayers'],
            ),
            $this->template(
                'tf2_windows',
                'Team Fortress 2 Dedicated Server (Windows)',
                'SteamCMD install with Windows server binary.',
                232250,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                    ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
                ],
                '{{INSTANCE_DIR}}/srcds.exe -game tf -port {{PORT_GAME}} +sv_queryport {{PORT_QUERY}} +rcon_port {{PORT_RCON}} +map ctf_2fort +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}" +sv_password "{{SERVER_PASSWORD}}" -authkey {{AUTHKEY}} +host_workshop_map {{WORKSHOP_MAP}}',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi TF2'],
                    ['key' => 'MAX_PLAYERS', 'value' => '24'],
                    ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
                    ['key' => 'SERVER_PASSWORD', 'value' => ''],
                    ['key' => 'AUTHKEY', 'value' => ''],
                    ['key' => 'WORKSHOP_MAP', 'value' => ''],
                ],
                [
                    [
                        'path' => 'tf/cfg/server.cfg',
                        'description' => 'Base server configuration',
                    ],
                ],
                [
                    'tf/addons/metamod',
                    'tf/addons/sourcemod',
                ],
                $fastdl,
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 232250 validate +quit',
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 232250 +quit',
                ['+map', '+maxplayers'],
            ),
            $this->template(
                'css',
                'Counter-Strike: Source Dedicated Server',
                'SteamCMD install with base server config.',
                232330,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                    ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
                    ['name' => 'tv', 'label' => 'SourceTV', 'protocol' => 'udp'],
                ],
                '{{INSTANCE_DIR}}/srcds_run -game cstrike -port {{PORT_GAME}} +sv_queryport {{PORT_QUERY}} +rcon_port {{PORT_RCON}} +map de_dust2 +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}" +sv_password "{{SERVER_PASSWORD}}" -authkey {{AUTHKEY}} +host_workshop_map {{WORKSHOP_MAP}}',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi CSS'],
                    ['key' => 'MAX_PLAYERS', 'value' => '24'],
                    ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
                    ['key' => 'SERVER_PASSWORD', 'value' => ''],
                    ['key' => 'AUTHKEY', 'value' => ''],
                    ['key' => 'WORKSHOP_MAP', 'value' => ''],
                ],
                [
                    [
                        'path' => 'cstrike/cfg/server.cfg',
                        'description' => 'Base server configuration',
                    ],
                ],
                [
                    'cstrike/addons/metamod',
                    'cstrike/addons/sourcemod',
                ],
                $fastdl,
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 232330 validate +quit',
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 232330 +quit',
                ['+map', '+maxplayers'],
            ),
            $this->template(
                'css_windows',
                'Counter-Strike: Source Dedicated Server (Windows)',
                'SteamCMD install with Windows server binary.',
                232330,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                    ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
                    ['name' => 'tv', 'label' => 'SourceTV', 'protocol' => 'udp'],
                ],
                '{{INSTANCE_DIR}}/srcds.exe -game cstrike -port {{PORT_GAME}} +sv_queryport {{PORT_QUERY}} +rcon_port {{PORT_RCON}} +map de_dust2 +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}" +sv_password "{{SERVER_PASSWORD}}" -authkey {{AUTHKEY}} +host_workshop_map {{WORKSHOP_MAP}}',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi CSS'],
                    ['key' => 'MAX_PLAYERS', 'value' => '24'],
                    ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
                    ['key' => 'SERVER_PASSWORD', 'value' => ''],
                    ['key' => 'AUTHKEY', 'value' => ''],
                    ['key' => 'WORKSHOP_MAP', 'value' => ''],
                ],
                [
                    [
                        'path' => 'cstrike/cfg/server.cfg',
                        'description' => 'Base server configuration',
                    ],
                ],
                [
                    'cstrike/addons/metamod',
                    'cstrike/addons/sourcemod',
                ],
                $fastdl,
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 232330 validate +quit',
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 232330 +quit',
                ['+map', '+maxplayers'],
            ),
            $this->template(
                'hl2dm',
                'Half-Life 2: Deathmatch Dedicated Server',
                'SteamCMD install with base server config.',
                232370,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                    ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
                ],
                '{{INSTANCE_DIR}}/srcds_run -game hl2mp -port {{PORT_GAME}} +sv_queryport {{PORT_QUERY}} +rcon_port {{PORT_RCON}} +map dm_lockdown +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}" +sv_password "{{SERVER_PASSWORD}}" -authkey {{AUTHKEY}} +host_workshop_map {{WORKSHOP_MAP}}',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi HL2DM'],
                    ['key' => 'MAX_PLAYERS', 'value' => '24'],
                    ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
                    ['key' => 'SERVER_PASSWORD', 'value' => ''],
                    ['key' => 'AUTHKEY', 'value' => ''],
                    ['key' => 'WORKSHOP_MAP', 'value' => ''],
                ],
                [
                    [
                        'path' => 'hl2mp/cfg/server.cfg',
                        'description' => 'Base server configuration',
                    ],
                ],
                [],
                $fastdl,
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 232370 validate +quit',
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 232370 +quit',
                ['+map', '+maxplayers'],
            ),
            $this->template(
                'hl2dm_windows',
                'Half-Life 2: Deathmatch Dedicated Server (Windows)',
                'SteamCMD install with Windows server binary.',
                232370,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                    ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
                ],
                '{{INSTANCE_DIR}}/srcds.exe -game hl2mp -port {{PORT_GAME}} +sv_queryport {{PORT_QUERY}} +rcon_port {{PORT_RCON}} +map dm_lockdown +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}" +sv_password "{{SERVER_PASSWORD}}" -authkey {{AUTHKEY}} +host_workshop_map {{WORKSHOP_MAP}}',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi HL2DM'],
                    ['key' => 'MAX_PLAYERS', 'value' => '24'],
                    ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
                    ['key' => 'SERVER_PASSWORD', 'value' => ''],
                    ['key' => 'AUTHKEY', 'value' => ''],
                    ['key' => 'WORKSHOP_MAP', 'value' => ''],
                ],
                [
                    [
                        'path' => 'hl2mp/cfg/server.cfg',
                        'description' => 'Base server configuration',
                    ],
                ],
                [],
                $fastdl,
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 232370 validate +quit',
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 232370 +quit',
                ['+map', '+maxplayers'],
            ),
            $this->template(
                'l4d2',
                'Left 4 Dead 2 Dedicated Server',
                'SteamCMD install with base server config.',
                222860,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                    ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
                ],
                '{{INSTANCE_DIR}}/srcds_run -game left4dead2 -port {{PORT_GAME}} +sv_queryport {{PORT_QUERY}} +rcon_port {{PORT_RCON}} +map c1m1_hotel +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}" +sv_password "{{SERVER_PASSWORD}}" -authkey {{AUTHKEY}} +host_workshop_map {{WORKSHOP_MAP}}',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi L4D2'],
                    ['key' => 'MAX_PLAYERS', 'value' => '8'],
                    ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
                    ['key' => 'SERVER_PASSWORD', 'value' => ''],
                    ['key' => 'AUTHKEY', 'value' => ''],
                    ['key' => 'WORKSHOP_MAP', 'value' => ''],
                ],
                [
                    [
                        'path' => 'left4dead2/cfg/server.cfg',
                        'description' => 'Base server configuration',
                    ],
                ],
                [
                    'left4dead2/addons/metamod',
                    'left4dead2/addons/sourcemod',
                ],
                $fastdl,
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +@sSteamCmdForcePlatformType windows +app_update 222860 validate +quit && steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +@sSteamCmdForcePlatformType linux +app_update 222860 validate +quit',
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +@sSteamCmdForcePlatformType windows +app_update 222860 +quit && steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +@sSteamCmdForcePlatformType linux +app_update 222860 +quit',
                ['+map', '+maxplayers'],
            ),
            $this->template(
                'l4d2_windows',
                'Left 4 Dead 2 Dedicated Server (Windows)',
                'SteamCMD install with Windows server binary.',
                222860,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                    ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
                ],
                '{{INSTANCE_DIR}}/srcds.exe -game left4dead2 -port {{PORT_GAME}} +sv_queryport {{PORT_QUERY}} +rcon_port {{PORT_RCON}} +map c1m1_hotel +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}" +sv_password "{{SERVER_PASSWORD}}" -authkey {{AUTHKEY}} +host_workshop_map {{WORKSHOP_MAP}}',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi L4D2'],
                    ['key' => 'MAX_PLAYERS', 'value' => '8'],
                    ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
                    ['key' => 'SERVER_PASSWORD', 'value' => ''],
                    ['key' => 'AUTHKEY', 'value' => ''],
                    ['key' => 'WORKSHOP_MAP', 'value' => ''],
                ],
                [
                    [
                        'path' => 'left4dead2/cfg/server.cfg',
                        'description' => 'Base server configuration',
                    ],
                ],
                [
                    'left4dead2/addons/metamod',
                    'left4dead2/addons/sourcemod',
                ],
                $fastdl,
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 222860 validate +quit',
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 222860 +quit',
                ['+map', '+maxplayers'],
            ),
            $this->template(
                'l4d',
                'Left 4 Dead Dedicated Server',
                'SteamCMD install with base server config.',
                222840,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                    ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
                ],
                '{{INSTANCE_DIR}}/srcds_run -game left4dead -port {{PORT_GAME}} +sv_queryport {{PORT_QUERY}} +rcon_port {{PORT_RCON}} +map l4d_hospital01_apartment +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}" +sv_password "{{SERVER_PASSWORD}}" -authkey {{AUTHKEY}} +host_workshop_map {{WORKSHOP_MAP}}',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi L4D'],
                    ['key' => 'MAX_PLAYERS', 'value' => '8'],
                    ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
                    ['key' => 'SERVER_PASSWORD', 'value' => ''],
                    ['key' => 'AUTHKEY', 'value' => ''],
                    ['key' => 'WORKSHOP_MAP', 'value' => ''],
                ],
                [
                    [
                        'path' => 'left4dead/cfg/server.cfg',
                        'description' => 'Base server configuration',
                    ],
                ],
                [
                    'left4dead/addons/metamod',
                    'left4dead/addons/sourcemod',
                ],
                $fastdl,
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 222840 validate +quit',
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 222840 +quit',
                ['+map', '+maxplayers'],
            ),
            $this->template(
                'l4d_windows',
                'Left 4 Dead Dedicated Server (Windows)',
                'SteamCMD install with Windows server binary.',
                222840,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                    ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
                ],
                '{{INSTANCE_DIR}}/srcds.exe -game left4dead -port {{PORT_GAME}} +sv_queryport {{PORT_QUERY}} +rcon_port {{PORT_RCON}} +map l4d_hospital01_apartment +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}" +sv_password "{{SERVER_PASSWORD}}" -authkey {{AUTHKEY}} +host_workshop_map {{WORKSHOP_MAP}}',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi L4D'],
                    ['key' => 'MAX_PLAYERS', 'value' => '8'],
                    ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
                    ['key' => 'SERVER_PASSWORD', 'value' => ''],
                    ['key' => 'AUTHKEY', 'value' => ''],
                    ['key' => 'WORKSHOP_MAP', 'value' => ''],
                ],
                [
                    [
                        'path' => 'left4dead/cfg/server.cfg',
                        'description' => 'Base server configuration',
                    ],
                ],
                [
                    'left4dead/addons/metamod',
                    'left4dead/addons/sourcemod',
                ],
                $fastdl,
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 222840 validate +quit',
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 222840 +quit',
                ['+map', '+maxplayers'],
            ),
            $this->template(
                'dods',
                'Day of Defeat: Source Dedicated Server',
                'SteamCMD install with base server config.',
                232290,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                    ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
                ],
                '{{INSTANCE_DIR}}/srcds_run -game dod -port {{PORT_GAME}} +sv_queryport {{PORT_QUERY}} +rcon_port {{PORT_RCON}} +map dod_anzio +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}" +sv_password "{{SERVER_PASSWORD}}" -authkey {{AUTHKEY}} +host_workshop_map {{WORKSHOP_MAP}}',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi DoD:S'],
                    ['key' => 'MAX_PLAYERS', 'value' => '24'],
                    ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
                    ['key' => 'SERVER_PASSWORD', 'value' => ''],
                    ['key' => 'AUTHKEY', 'value' => ''],
                    ['key' => 'WORKSHOP_MAP', 'value' => ''],
                ],
                [
                    [
                        'path' => 'dod/cfg/server.cfg',
                        'description' => 'Base server configuration',
                    ],
                ],
                [
                    'dod/addons/metamod',
                    'dod/addons/sourcemod',
                ],
                $fastdl,
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 232290 validate +quit',
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 232290 +quit',
                ['+map', '+maxplayers'],
            ),
            $this->template(
                'dods_windows',
                'Day of Defeat: Source Dedicated Server (Windows)',
                'SteamCMD install with Windows server binary.',
                232290,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                    ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
                ],
                '{{INSTANCE_DIR}}/srcds.exe -game dod -port {{PORT_GAME}} +sv_queryport {{PORT_QUERY}} +rcon_port {{PORT_RCON}} +map dod_anzio +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}" +sv_password "{{SERVER_PASSWORD}}" -authkey {{AUTHKEY}} +host_workshop_map {{WORKSHOP_MAP}}',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi DoD:S'],
                    ['key' => 'MAX_PLAYERS', 'value' => '24'],
                    ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
                    ['key' => 'SERVER_PASSWORD', 'value' => ''],
                    ['key' => 'AUTHKEY', 'value' => ''],
                    ['key' => 'WORKSHOP_MAP', 'value' => ''],
                ],
                [
                    [
                        'path' => 'dod/cfg/server.cfg',
                        'description' => 'Base server configuration',
                    ],
                ],
                [
                    'dod/addons/metamod',
                    'dod/addons/sourcemod',
                ],
                $fastdl,
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 232290 validate +quit',
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 232290 +quit',
                ['+map', '+maxplayers'],
            ),
            $this->template(
                'minecraft_vanilla_all',
                'Minecraft Java (Vanilla)',
                'Vanilla Minecraft with selectable versions via catalog.',
                null,
                null,
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'tcp'],
                ],
                'java -Xms{{JAVA_XMS}} -Xmx{{JAVA_XMX}} -jar {{INSTANCE_DIR}}/server.jar nogui',
                [
                    ['key' => 'JAVA_XMS', 'value' => '1G'],
                    ['key' => 'JAVA_XMX', 'value' => '2G'],
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Minecraft'],
                    ['key' => 'MAX_PLAYERS', 'value' => '20'],
                    ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
                    ['key' => 'SERVER_PASSWORD', 'value' => ''],
                ],
                [
                    [
                        'path' => 'eula.txt',
                        'description' => 'Minecraft EULA acceptance',
                        'contents' => "eula=true\n",
                    ],
                    [
                        'path' => 'server.properties',
                        'description' => 'Base server settings',
                        'contents' => "motd={{SERVER_NAME}}\nview-distance=10\nserver-port={{SERVER_PORT}}\nmax-players={{MAX_PLAYERS}}\nenable-rcon=true\nrcon.password={{RCON_PASSWORD}}\nserver-password={{SERVER_PASSWORD}}\n",
                    ],
                ],
                [],
                $fastdl,
                'echo "Install handled by catalog resolver."',
                'echo "Update handled by catalog resolver."',
                [],
                ['type' => 'minecraft_vanilla'],
                ['linux', 'windows'],
            ),
            $this->template(
                'minecraft_paper_all',
                'Minecraft Java (Paper)',
                'PaperMC Minecraft with selectable versions/builds via catalog.',
                null,
                null,
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'tcp'],
                ],
                'java -Xms{{JAVA_XMS}} -Xmx{{JAVA_XMX}} -jar {{INSTANCE_DIR}}/server.jar nogui',
                [
                    ['key' => 'JAVA_XMS', 'value' => '1G'],
                    ['key' => 'JAVA_XMX', 'value' => '2G'],
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Minecraft'],
                    ['key' => 'MAX_PLAYERS', 'value' => '20'],
                    ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
                    ['key' => 'SERVER_PASSWORD', 'value' => ''],
                ],
                [
                    [
                        'path' => 'eula.txt',
                        'description' => 'Minecraft EULA acceptance',
                        'contents' => "eula=true\n",
                    ],
                    [
                        'path' => 'server.properties',
                        'description' => 'Base server settings',
                        'contents' => "motd={{SERVER_NAME}}\nview-distance=10\nserver-port={{SERVER_PORT}}\nmax-players={{MAX_PLAYERS}}\nenable-rcon=true\nrcon.password={{RCON_PASSWORD}}\nserver-password={{SERVER_PASSWORD}}\n",
                    ],
                ],
                [],
                $fastdl,
                'echo "Install handled by catalog resolver."',
                'echo "Update handled by catalog resolver."',
                [],
                ['type' => 'papermc_paper'],
                ['linux', 'windows'],
            ),
            $this->template(
                'minecraft_bedrock',
                'Minecraft Bedrock',
                'Bedrock dedicated server with EULA acceptance.',
                null,
                null,
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ],
                '{{INSTANCE_DIR}}/bedrock_server',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Bedrock'],
                    ['key' => 'SERVER_PASSWORD', 'value' => ''],
                ],
                [
                    [
                        'path' => 'server.properties',
                        'description' => 'Base server settings',
                        'contents' => "server-name={{SERVER_NAME}}\nserver-port={{PORT_GAME}}\nserver-portv6=19133\nmax-players=10\nonline-mode=true\n",
                    ],
                    [
                        'path' => 'allowlist.json',
                        'description' => 'Allowlist (optional)',
                        'contents' => "[]\n",
                    ],
                ],
                [],
                $fastdl,
                'curl -L -o bedrock-server.zip https://minecraft.azureedge.net/bin-linux/bedrock-server-1.20.62.02.zip && unzip -o bedrock-server.zip && chmod +x bedrock_server',
                'curl -L -o bedrock-server.zip https://minecraft.azureedge.net/bin-linux/bedrock-server-1.20.62.02.zip && unzip -o bedrock-server.zip && chmod +x bedrock_server',
                [],
                [],
                ['linux'],
            ),
            $this->template(
                'hytale',
                'Hytale Dedicated Server',
                'Hytale dedicated server with downloader-based installation.',
                null,
                null,
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'tcp'],
                ],
                'java $( (({{USE_AOT_CACHE}})) && printf %s "-XX:AOTCache=Server/HytaleServer.aot" ) -Xms128M $( (({{SERVER_MEMORY}})) && printf %s "-Xmx{{SERVER_MEMORY}}M" ) -jar Server/HytaleServer.jar $( (({{HYTALE_ALLOW_OP}})) && printf %s "--allow-op" ) $( (({{HYTALE_ACCEPT_EARLY_PLUGINS}})) && printf %s "--accept-early-plugins" ) $( (({{DISABLE_SENTRY}})) && printf %s "--disable-sentry" ) --auth-mode {{HYTALE_AUTH_MODE}} --assets Assets.zip --bind 0.0.0.0:{{PORT_GAME}}',
                [
                    ['key' => 'SERVER_MEMORY', 'value' => '2048'],
                    ['key' => 'USE_AOT_CACHE', 'value' => '1'],
                    ['key' => 'HYTALE_ALLOW_OP', 'value' => '0'],
                    ['key' => 'HYTALE_ACCEPT_EARLY_PLUGINS', 'value' => '0'],
                    ['key' => 'DISABLE_SENTRY', 'value' => '0'],
                    ['key' => 'HYTALE_AUTH_MODE', 'value' => 'authenticated'],
                    ['key' => 'HYTALE_PATCHLINE', 'value' => 'release'],
                    ['key' => 'INSTALL_SOURCEQUERY_PLUGIN', 'value' => '1'],
                    ['key' => 'QUERY_PORT', 'value' => ''],
                ],
                [],
                [],
                $fastdl,
                'cd {{INSTANCE_DIR}} && curl -L -o hytale-downloader.zip https://downloader.hytale.com/hytale-downloader.zip && unzip -o hytale-downloader.zip -d hytale-downloader && rm -f hytale-downloader.zip && mv hytale-downloader/hytale-downloader-linux-amd64 hytale-downloader/hytale-downloader-linux && chmod 555 hytale-downloader/hytale-downloader-linux && ./hytale-downloader/hytale-downloader-linux install --patchline {{HYTALE_PATCHLINE}} && latest_zip=$(ls -1t *.zip | head -n1) && unzip -o "$latest_zip" && rm -f "$latest_zip"',
                'cd {{INSTANCE_DIR}} && ./hytale-downloader/hytale-downloader-linux install --patchline {{HYTALE_PATCHLINE}} && latest_zip=$(ls -1t *.zip | head -n1) && unzip -o "$latest_zip" && rm -f "$latest_zip"',
                [],
            ),
            $this->template(
                'ark_windows',
                'ARK: Survival Evolved (Windows)',
                'SteamCMD install with Windows server binary.',
                376030,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                    ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
                ],
                '{{INSTANCE_DIR}}/ShooterGameServer.exe TheIsland?SessionName={{SERVER_NAME}}?Port={{PORT_GAME}}?QueryPort={{PORT_QUERY}}?RCONPort={{PORT_RCON}}?MaxPlayers=70?listen -log',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi ARK'],
                ],
                [
                    [
                        'path' => 'ShooterGame/Saved/Config/WindowsServer/GameUserSettings.ini',
                        'description' => 'Server session settings',
                    ],
                    [
                        'path' => 'ShooterGame/Saved/Config/WindowsServer/Game.ini',
                        'description' => 'Gameplay rules',
                    ],
                ],
                [],
                $fastdl,
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 376030 validate +quit',
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 376030 +quit',
                [],
            ),
            $this->template(
                'rust_windows',
                'Rust Dedicated Server (Windows)',
                'SteamCMD install with Windows server binary.',
                258550,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                    ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
                ],
                '{{INSTANCE_DIR}}/RustDedicated.exe -batchmode +server.port {{PORT_GAME}} +server.queryport {{PORT_QUERY}} +rcon.port {{PORT_RCON}} +server.hostname "{{SERVER_NAME}}" +rcon.password "{{RCON_PASSWORD}}" +server.maxplayers 50',
                [
                    ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Rust'],
                    ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
                ],
                [
                    [
                        'path' => 'server/cfg/server.cfg',
                        'description' => 'Server configuration overrides',
                    ],
                ],
                [
                    'oxide/plugins',
                ],
                $fastdl,
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 258550 validate +quit',
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 258550 +quit',
                ['+server.level', '+server.seed'],
            ),
            $this->template(
                'enshrouded',
                'Enshrouded Dedicated Server',
                'SteamCMD install with Linux server binary.',
                2278520,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ],
                '{{INSTANCE_DIR}}/enshrouded_server -log',
                [],
                [
                    [
                        'path' => 'enshrouded_server.json',
                        'description' => 'Server configuration',
                    ],
                ],
                [],
                $fastdl,
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 2278520 validate +quit',
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 2278520 +quit',
                [],
            ),
            $this->template(
                'squad',
                'Squad Dedicated Server',
                'SteamCMD install with default server config.',
                403240,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                    ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
                ],
                '{{INSTANCE_DIR}}/SquadGameServer.sh Port={{PORT_GAME}} QueryPort={{PORT_QUERY}} RCONPort={{PORT_RCON}} -log -MaxPlayers={{MAX_PLAYERS}}',
                [
                    ['key' => 'MAX_PLAYERS', 'value' => '80'],
                ],
                [
                    [
                        'path' => 'SquadGame/ServerConfig/Server.cfg',
                        'description' => 'Server configuration',
                    ],
                ],
                [],
                $fastdl,
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 403240 validate +quit',
                'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 403240 +quit',
                [],
            ),
            $this->template(
                'squad_windows',
                'Squad Dedicated Server (Windows)',
                'SteamCMD install with Windows server binary.',
                403240,
                'steam',
                [
                    ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                    ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                    ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
                ],
                '{{INSTANCE_DIR}}/SquadGameServer.exe Port={{PORT_GAME}} QueryPort={{PORT_QUERY}} RCONPort={{PORT_RCON}} -log -MaxPlayers={{MAX_PLAYERS}}',
                [
                    ['key' => 'MAX_PLAYERS', 'value' => '80'],
                ],
                [
                    [
                        'path' => 'SquadGame/ServerConfig/Server.cfg',
                        'description' => 'Server configuration',
                    ],
                ],
                [],
                $fastdl,
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 403240 validate +quit',
                'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 403240 +quit',
                [],
            ),
            $this->template(
                'fivem',
                'FiveM (FXServer)',
                'Download latest FXServer artifacts and run server.cfg.',
                null,
                null,
                [
                    ['name' => 'game_udp', 'label' => 'Game (UDP)', 'protocol' => 'udp'],
                    ['name' => 'game_tcp', 'label' => 'Game (TCP)', 'protocol' => 'tcp'],
                ],
                '{{INSTANCE_DIR}}/run.sh +exec server.cfg',
                [],
                [
                    [
                        'path' => 'server.cfg',
                        'description' => 'Base FXServer configuration',
                        'contents' => "endpoint_add_tcp \"0.0.0.0:{{PORT_GAME_TCP}}\"\nendpoint_add_udp \"0.0.0.0:{{PORT_GAME_UDP}}\"\nsv_hostname \"Easy-Wi FiveM\"\n",
                    ],
                ],
                [],
                $fastdl,
                'cd {{INSTANCE_DIR}} && mkdir -p ./ && curl -sSL https://runtime.fivem.net/artifacts/fivem/build_proot_linux/master/ | grep -oP \'href="[^"]+fx.tar.xz"\' | tail -1 | cut -d\'"\' -f2 | xargs -I {} curl -L -o fx.tar.xz https://runtime.fivem.net/artifacts/fivem/build_proot_linux/master/{} && tar -xf fx.tar.xz && chmod +x run.sh',
                'cd {{INSTANCE_DIR}} && mkdir -p ./ && curl -sSL https://runtime.fivem.net/artifacts/fivem/build_proot_linux/master/ | grep -oP \'href="[^"]+fx.tar.xz"\' | tail -1 | cut -d\'"\' -f2 | xargs -I {} curl -L -o fx.tar.xz https://runtime.fivem.net/artifacts/fivem/build_proot_linux/master/{} && tar -xf fx.tar.xz && chmod +x run.sh',
                [],
            ),
            $this->template(
                'fivem_windows',
                'FiveM (FXServer) (Windows)',
                'Download latest FXServer Windows artifacts and run server.cfg.',
                null,
                null,
                [
                    ['name' => 'game_udp', 'label' => 'Game (UDP)', 'protocol' => 'udp'],
                    ['name' => 'game_tcp', 'label' => 'Game (TCP)', 'protocol' => 'tcp'],
                ],
                '{{INSTANCE_DIR}}/FXServer.exe +exec server.cfg',
                [],
                [
                    [
                        'path' => 'server.cfg',
                        'description' => 'Base FXServer configuration',
                        'contents' => "endpoint_add_tcp \"0.0.0.0:{{PORT_GAME_TCP}}\"\nendpoint_add_udp \"0.0.0.0:{{PORT_GAME_UDP}}\"\nsv_hostname \"Easy-Wi FiveM\"\n",
                    ],
                ],
                [],
                $fastdl,
                'powershell -Command "$ProgressPreference = \'SilentlyContinue\'; $page = Invoke-WebRequest -UseBasicParsing https://runtime.fivem.net/artifacts/fivem/build_server_windows/master/; $match = ($page.Links | Where-Object href -match \'server.zip\' | Select-Object -Last 1).href; Invoke-WebRequest -UseBasicParsing -OutFile server.zip (\"https://runtime.fivem.net/artifacts/fivem/build_server_windows/master/$match\"); Expand-Archive -Force server.zip ."',
                'powershell -Command "$ProgressPreference = \'SilentlyContinue\'; $page = Invoke-WebRequest -UseBasicParsing https://runtime.fivem.net/artifacts/fivem/build_server_windows/master/; $match = ($page.Links | Where-Object href -match \'server.zip\' | Select-Object -Last 1).href; Invoke-WebRequest -UseBasicParsing -OutFile server.zip (\"https://runtime.fivem.net/artifacts/fivem/build_server_windows/master/$match\"); Expand-Archive -Force server.zip ."',
                [],
            ),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPlugins(): array
    {
        return array_merge(
            $this->cs2Plugins(),
            $this->source1Plugins(),
            $this->minecraftPaperPlugins(),
            $this->rustPlugins(),
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function cs2Plugins(): array
    {
        return [
            $this->plugin(
                'cs2',
                'MetaMod:Source',
                '2.0.0-dev',
                '',
                'https://mms.alliedmods.net/mmsdrop/2.0/mmsource-2.0.0-git1282-linux.tar.gz',
                'Core plugin loader for CS2 (Source 2).',
                'extract',
                'game/csgo',
            ),
            $this->plugin(
                'cs2',
                'CounterStrikeSharp',
                'latest',
                '',
                'https://github.com/roflmuffin/CounterStrikeSharp/releases/latest/download/counterstrikesharp-with-runtime-linux.zip',
                'Managed .NET plugin framework for CS2.',
                'extract',
                'game/csgo',
            ),
        ];
    }

    /**
     * MetaMod:Source + SourceMod entries for all Source 1 (GoldSrc/Source) game servers.
     * Both plugins use the same archive structure so they extract into the game-specific
     * subdirectory (e.g. "cstrike" for CSS, "tf" for TF2, …).
     *
     * @return array<int, array<string, mixed>>
     */
    private function source1Plugins(): array
    {
        $mmsUrl = 'https://mms.alliedmods.net/mmsdrop/1.12/mmsource-1.12.0-git1167-linux.tar.gz';
        $smUrl  = 'https://sm.alliedmods.net/smdrop/1.12/sourcemod-1.12.0-git7195-linux.tar.gz';
        $mmsVer = '1.12.0-dev';
        $smVer  = '1.12.0-dev';

        // [template_game_key => extract_subdir]
        $games = [
            'csgo_legacy' => 'csgo',
            'css'         => 'cstrike',
            'tf2'         => 'tf',
            'hl2dm'       => 'hl2mp',
            'dods'        => 'dod',
            'l4d'         => 'left4dead',
            'l4d2'        => 'left4dead2',
            'garrys_mod'  => 'garrysmod',
        ];

        $entries = [];
        foreach ($games as $gameKey => $subdir) {
            $entries[] = $this->plugin(
                $gameKey,
                'MetaMod:Source',
                $mmsVer,
                '',
                $mmsUrl,
                'Core plugin loader for Source engine servers.',
                'extract',
                $subdir,
            );
            $entries[] = $this->plugin(
                $gameKey,
                'SourceMod',
                $smVer,
                '',
                $smUrl,
                'Server administration and plugin framework for Source engine.',
                'extract',
                $subdir,
            );
        }

        return $entries;
    }

    /** @return array<int, array<string, mixed>> */
    private function minecraftPaperPlugins(): array
    {
        return [
            $this->plugin(
                'minecraft_paper_all',
                'EssentialsX',
                'latest',
                '',
                'https://github.com/EssentialsX/Essentials/releases/latest/download/EssentialsX.jar',
                'Core commands, economy, and moderation suite.',
                'copy',
                'plugins',
            ),
            $this->plugin(
                'minecraft_paper_all',
                'LuckPerms',
                '5.4.151',
                '',
                'https://download.luckperms.net/1567/bukkit/loader/LuckPerms-Bukkit-5.4.151.jar',
                'Advanced permissions management plugin.',
                'copy',
                'plugins',
            ),
            $this->plugin(
                'minecraft_paper_all',
                'ViaVersion',
                'latest',
                '',
                'https://github.com/ViaVersion/ViaVersion/releases/latest/download/ViaVersion.jar',
                'Protocol compatibility layer — lets different client versions join.',
                'copy',
                'plugins',
            ),
            $this->plugin(
                'minecraft_paper_all',
                'PlaceholderAPI',
                'latest',
                '',
                'https://github.com/PlaceholderAPI/PlaceholderAPI/releases/latest/download/PlaceholderAPI.jar',
                'Placeholder expansion support for chat, scoreboards, and more.',
                'copy',
                'plugins',
            ),
            $this->plugin(
                'minecraft_paper_all',
                'WorldEdit',
                'latest',
                '',
                'https://github.com/EngineHub/WorldEdit/releases/latest/download/worldedit-bukkit.jar',
                'In-game map editor and builder tools.',
                'copy',
                'plugins',
            ),
            $this->plugin(
                'minecraft_paper_all',
                'Vault',
                '1.7.3',
                '',
                'https://github.com/MilkBowl/Vault/releases/download/1.7.3/Vault.jar',
                'Economy and permissions API bridge.',
                'copy',
                'plugins',
            ),
            $this->plugin(
                'minecraft_paper_all',
                'Geyser',
                'latest',
                '',
                'https://download.geysermc.org/v2/projects/geyser/versions/latest/builds/latest/downloads/spigot',
                'Allows Bedrock Edition clients to connect to Java servers.',
                'copy',
                'plugins',
            ),
            $this->plugin(
                'minecraft_paper_all',
                'Floodgate',
                'latest',
                '',
                'https://download.geysermc.org/v2/projects/floodgate/versions/latest/builds/latest/downloads/spigot',
                'Authenticate Bedrock players without a Java account (use with Geyser).',
                'copy',
                'plugins',
            ),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function rustPlugins(): array
    {
        return [
            $this->plugin(
                'rust',
                'uMod (Oxide)',
                'latest',
                '',
                'https://umod.org/games/rust/download/develop',
                'Modding and plugin framework for Rust servers.',
                'extract',
                null,
            ),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $requiredPorts
     * @param array<int, array<string, mixed>> $envVars
     * @param array<int, array<string, mixed>> $configFiles
     * @param array<int, string> $pluginPaths
     * @param array<string, mixed> $fastdlSettings
     * @param array<int, string> $allowedSwitchFlags
     * @param array<string, mixed> $installResolver
     * @param array<int, string>|null $supportedOs
     * @return array<string, mixed>
     */
    private function template(
        string $gameKey,
        string $displayName,
        ?string $description,
        ?int $steamAppId,
        ?string $sniperProfile,
        array $requiredPorts,
        string $startParams,
        array $envVars,
        array $configFiles,
        array $pluginPaths,
        array $fastdlSettings,
        string $installCommand,
        string $updateCommand,
        array $allowedSwitchFlags,
        array $installResolver = [],
        ?array $supportedOs = null,
    ): array {
        return [
            'game_key' => $gameKey,
            'display_name' => $displayName,
            'description' => $description,
            'steam_app_id' => $steamAppId,
            'sniper_profile' => $sniperProfile,
            'required_ports' => $requiredPorts,
            'start_params' => $startParams,
            'env_vars' => $envVars,
            'config_files' => $configFiles,
            'plugin_paths' => $pluginPaths,
            'fastdl_settings' => $fastdlSettings,
            'install_command' => $installCommand,
            'update_command' => $updateCommand,
            'install_resolver' => $installResolver,
            'allowed_switch_flags' => $allowedSwitchFlags,
            'supported_os' => $supportedOs,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultFastdlSettings(): array
    {
        return [
            'enabled' => false,
            'base_url' => '',
            'root_path' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function plugin(
        string $templateGameKey,
        string $name,
        string $version,
        string $checksum,
        string $downloadUrl,
        ?string $description,
        string $installMode = 'extract',
        ?string $extractSubdir = null,
    ): array {
        return [
            'template_game_key' => $templateGameKey,
            'name' => $name,
            'version' => $version,
            'checksum' => $checksum,
            'download_url' => $downloadUrl,
            'description' => $description,
            'install_mode' => $installMode,
            'extract_subdir' => $extractSubdir,
        ];
    }
}
