<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250310120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed additional game templates and popular plugins.';
    }

    public function up(Schema $schema): void
    {
        $this->insertTemplate(
            'palworld',
            'Palworld Dedicated Server',
            'SteamCMD install with default Palworld settings.',
            2394010,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
            ],
            './PalServer.sh -useperfthreads -NoAsyncLoadingThread -UseMultithreadForDS -port {{PORT_GAME}} -queryport {{PORT_QUERY}} -servername "{{SERVER_NAME}}" -serverpassword "{{SERVER_PASSWORD}}"',
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
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 2394010 validate +quit',
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 2394010 +quit',
            []
        );

        $this->insertTemplate(
            'palworld_windows',
            'Palworld Dedicated Server (Windows)',
            'SteamCMD install with Windows server binary.',
            2394010,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
            ],
            'PalServer.exe -port {{PORT_GAME}} -queryport {{PORT_QUERY}} -servername "{{SERVER_NAME}}" -serverpassword "{{SERVER_PASSWORD}}"',
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
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +login anonymous +force_install_dir C:\\easywi\\gs +app_update 2394010 validate +quit',
            'steamcmd.exe +login anonymous +force_install_dir C:\\easywi\\gs +app_update 2394010 +quit',
            []
        );

        $this->insertTemplate(
            'valheim_windows',
            'Valheim Dedicated Server (Windows)',
            'SteamCMD install with Windows server executable.',
            896660,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
            ],
            'valheim_server.exe -name "{{SERVER_NAME}}" -port {{PORT_GAME}} -world "{{WORLD_NAME}}" -password "{{SERVER_PASSWORD}}" -public 1',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Valheim'],
                ['key' => 'WORLD_NAME', 'value' => 'Dedicated'],
                ['key' => 'SERVER_PASSWORD', 'value' => 'change-me'],
            ],
            [],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +login anonymous +force_install_dir C:\\easywi\\gs +app_update 896660 validate +quit',
            'steamcmd.exe +login anonymous +force_install_dir C:\\easywi\\gs +app_update 896660 +quit',
            []
        );

        $this->insertTemplate(
            'satisfactory_windows',
            'Satisfactory Dedicated Server (Windows)',
            'SteamCMD install with Windows server executable.',
            1690800,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
            ],
            'FactoryServer.exe -log -unattended',
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
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +login anonymous +force_install_dir C:\\easywi\\gs +app_update 1690800 validate +quit',
            'steamcmd.exe +login anonymous +force_install_dir C:\\easywi\\gs +app_update 1690800 +quit',
            []
        );

        $this->insertTemplate(
            'dayz',
            'DayZ Dedicated Server',
            'SteamCMD install with basic serverDZ.cfg.',
            223350,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
            ],
            './DayZServer_x64 -config=serverDZ.cfg -port={{PORT_GAME}}',
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
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 223350 validate +quit',
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 223350 +quit',
            []
        );

        $this->insertTemplate(
            'dayz_windows',
            'DayZ Dedicated Server (Windows)',
            'SteamCMD install with Windows server binary.',
            223350,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
            ],
            'DayZServer_x64.exe -config=serverDZ.cfg -port={{PORT_GAME}}',
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
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +login anonymous +force_install_dir C:\\easywi\\gs +app_update 223350 validate +quit',
            'steamcmd.exe +login anonymous +force_install_dir C:\\easywi\\gs +app_update 223350 +quit',
            []
        );

        $this->insertTemplate(
            'v_rising',
            'V Rising Dedicated Server',
            'SteamCMD install with standard server config.',
            1829350,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
            ],
            './VRisingServer -persistentDataPath ./save-data -serverName "{{SERVER_NAME}}"',
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
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 1829350 validate +quit',
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 1829350 +quit',
            []
        );

        $this->insertTemplate(
            'v_rising_windows',
            'V Rising Dedicated Server (Windows)',
            'SteamCMD install with Windows server binary.',
            1829350,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
            ],
            'VRisingServer.exe -persistentDataPath .\\save-data -serverName "{{SERVER_NAME}}"',
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
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +login anonymous +force_install_dir C:\\easywi\\gs +app_update 1829350 validate +quit',
            'steamcmd.exe +login anonymous +force_install_dir C:\\easywi\\gs +app_update 1829350 +quit',
            []
        );

        $this->insertTemplate(
            'enshrouded_windows',
            'Enshrouded Dedicated Server (Windows)',
            'SteamCMD install with Windows server binary.',
            2278520,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
            ],
            'enshrouded_server.exe -log',
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
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +login anonymous +force_install_dir C:\\easywi\\gs +app_update 2278520 validate +quit',
            'steamcmd.exe +login anonymous +force_install_dir C:\\easywi\\gs +app_update 2278520 +quit',
            []
        );

        $this->insertTemplate(
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
            'srcds_run -game garrysmod -console +map gm_construct +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Garry\'s Mod'],
                ['key' => 'MAX_PLAYERS', 'value' => '24'],
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
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 4020 validate +quit',
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 4020 +quit',
            ['+map', '+maxplayers']
        );

        $this->insertTemplate(
            'terraria',
            'Terraria Dedicated Server',
            'SteamCMD install with config-based server setup.',
            105600,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
            ],
            './TerrariaServer.bin.x86_64 -config serverconfig.txt',
            [
                ['key' => 'WORLD_NAME', 'value' => 'EasyWiWorld'],
                ['key' => 'MAX_PLAYERS', 'value' => '16'],
                ['key' => 'SERVER_PASSWORD', 'value' => ''],
            ],
            [
                [
                    'path' => 'serverconfig.txt',
                    'description' => 'Terraria server configuration',
                    'contents' => "world=worlds/{{WORLD_NAME}}.wld\nmaxplayers={{MAX_PLAYERS}}\npassword={{SERVER_PASSWORD}}\n",
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 105600 validate +quit',
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 105600 +quit',
            []
        );

        $this->insertTemplate(
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
            './startserver.sh -configfile=serverconfig.xml -quit -batchmode -nographics -dedicated',
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
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 294420 validate +quit',
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 294420 +quit',
            []
        );

        $this->insertTemplate(
            'factorio',
            'Factorio Dedicated Server',
            'SteamCMD install with JSON server settings.',
            427520,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
            ],
            './bin/x64/factorio --start-server save.zip --server-settings server-settings.json',
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
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 427520 validate +quit',
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 427520 +quit',
            []
        );

        $this->insertTemplate(
            'project_zomboid',
            'Project Zomboid Dedicated Server',
            'SteamCMD install with standard Linux server scripts.',
            380870,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
            ],
            './start-server.sh -servername "{{SERVER_NAME}}" -adminpassword "{{ADMIN_PASSWORD}}"',
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
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 380870 validate +quit',
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 380870 +quit',
            []
        );

        $this->insertTemplate(
            'project_zomboid_windows',
            'Project Zomboid Dedicated Server (Windows)',
            'SteamCMD install with Windows server scripts.',
            380870,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
            ],
            'StartServer64.bat -servername "{{SERVER_NAME}}" -adminpassword "{{ADMIN_PASSWORD}}"',
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
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +login anonymous +force_install_dir C:\\easywi\\gs +app_update 380870 validate +quit',
            'steamcmd.exe +login anonymous +force_install_dir C:\\easywi\\gs +app_update 380870 +quit',
            []
        );

        $this->insertTemplate(
            'conan_exiles',
            'Conan Exiles Dedicated Server',
            'SteamCMD install with default Linux config paths.',
            443030,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
            ],
            './ConanSandboxServer -log -Port={{PORT_GAME}} -QueryPort={{PORT_QUERY}}',
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
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 443030 validate +quit',
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 443030 +quit',
            []
        );

        $this->insertTemplate(
            'conan_exiles_windows',
            'Conan Exiles Dedicated Server (Windows)',
            'SteamCMD install with Windows config paths.',
            443030,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
            ],
            'ConanSandboxServer.exe -log -Port={{PORT_GAME}} -QueryPort={{PORT_QUERY}}',
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
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +login anonymous +force_install_dir C:\\easywi\\gs +app_update 443030 validate +quit',
            'steamcmd.exe +login anonymous +force_install_dir C:\\easywi\\gs +app_update 443030 +quit',
            []
        );

        $this->insertTemplate(
            'arma3',
            'Arma 3 Dedicated Server',
            'SteamCMD install with standard Linux server config.',
            233780,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
            ],
            './arma3server_x64 -config=server.cfg -port={{PORT_GAME}} -name=server',
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
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 233780 validate +quit',
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 233780 +quit',
            []
        );

        $this->insertTemplate(
            'arma3_windows',
            'Arma 3 Dedicated Server (Windows)',
            'SteamCMD install with Windows server config.',
            233780,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
            ],
            'arma3server_x64.exe -config=server.cfg -port={{PORT_GAME}} -name=server',
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
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +login anonymous +force_install_dir C:\\easywi\\gs +app_update 233780 validate +quit',
            'steamcmd.exe +login anonymous +force_install_dir C:\\easywi\\gs +app_update 233780 +quit',
            []
        );

        $this->insertTemplate(
            'minecraft_java_1_19_4',
            'Minecraft Java (Paper 1.19.4)',
            'PaperMC install with EULA acceptance for 1.19.4.',
            null,
            null,
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'tcp'],
            ],
            'java -Xms{{JAVA_XMS}} -Xmx{{JAVA_XMX}} -jar server.jar nogui',
            [
                ['key' => 'JAVA_XMS', 'value' => '1G'],
                ['key' => 'JAVA_XMX', 'value' => '2G'],
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
                    'contents' => "motd=Easy-Wi Minecraft 1.19.4\nview-distance=10\n",
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'curl -L -o server.jar https://api.papermc.io/v2/projects/paper/versions/1.19.4/builds/1/downloads/paper-1.19.4-1.jar',
            'curl -L -o server.jar https://api.papermc.io/v2/projects/paper/versions/1.19.4/builds/1/downloads/paper-1.19.4-1.jar',
            []
        );

        $this->insertTemplate(
            'minecraft_java_1_18_2',
            'Minecraft Java (Paper 1.18.2)',
            'PaperMC install with EULA acceptance for 1.18.2.',
            null,
            null,
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'tcp'],
            ],
            'java -Xms{{JAVA_XMS}} -Xmx{{JAVA_XMX}} -jar server.jar nogui',
            [
                ['key' => 'JAVA_XMS', 'value' => '1G'],
                ['key' => 'JAVA_XMX', 'value' => '2G'],
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
                    'contents' => "motd=Easy-Wi Minecraft 1.18.2\nview-distance=10\n",
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'curl -L -o server.jar https://api.papermc.io/v2/projects/paper/versions/1.18.2/builds/1/downloads/paper-1.18.2-1.jar',
            'curl -L -o server.jar https://api.papermc.io/v2/projects/paper/versions/1.18.2/builds/1/downloads/paper-1.18.2-1.jar',
            []
        );

        $this->insertTemplate(
            'minecraft_java_windows',
            'Minecraft Java (Paper, Windows)',
            'PaperMC install with EULA acceptance on Windows.',
            null,
            null,
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'tcp'],
            ],
            'java -Xms{{JAVA_XMS}} -Xmx{{JAVA_XMX}} -jar server.jar nogui',
            [
                ['key' => 'JAVA_XMS', 'value' => '1G'],
                ['key' => 'JAVA_XMX', 'value' => '2G'],
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
                    'contents' => "motd=Easy-Wi Minecraft Windows\nview-distance=10\n",
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'powershell -Command \"Invoke-WebRequest -Uri https://api.papermc.io/v2/projects/paper/versions/1.20.4/builds/1/downloads/paper-1.20.4-1.jar -OutFile server.jar\"',
            'powershell -Command \"Invoke-WebRequest -Uri https://api.papermc.io/v2/projects/paper/versions/1.20.4/builds/1/downloads/paper-1.20.4-1.jar -OutFile server.jar\"',
            []
        );

        $this->insertTemplate(
            'minecraft_java_1_19_4_windows',
            'Minecraft Java (Paper 1.19.4, Windows)',
            'PaperMC install with EULA acceptance for 1.19.4 on Windows.',
            null,
            null,
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'tcp'],
            ],
            'java -Xms{{JAVA_XMS}} -Xmx{{JAVA_XMX}} -jar server.jar nogui',
            [
                ['key' => 'JAVA_XMS', 'value' => '1G'],
                ['key' => 'JAVA_XMX', 'value' => '2G'],
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
                    'contents' => "motd=Easy-Wi Minecraft 1.19.4\nview-distance=10\n",
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'powershell -Command \"Invoke-WebRequest -Uri https://api.papermc.io/v2/projects/paper/versions/1.19.4/builds/1/downloads/paper-1.19.4-1.jar -OutFile server.jar\"',
            'powershell -Command \"Invoke-WebRequest -Uri https://api.papermc.io/v2/projects/paper/versions/1.19.4/builds/1/downloads/paper-1.19.4-1.jar -OutFile server.jar\"',
            []
        );

        $this->insertTemplate(
            'minecraft_java_1_18_2_windows',
            'Minecraft Java (Paper 1.18.2, Windows)',
            'PaperMC install with EULA acceptance for 1.18.2 on Windows.',
            null,
            null,
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'tcp'],
            ],
            'java -Xms{{JAVA_XMS}} -Xmx{{JAVA_XMX}} -jar server.jar nogui',
            [
                ['key' => 'JAVA_XMS', 'value' => '1G'],
                ['key' => 'JAVA_XMX', 'value' => '2G'],
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
                    'contents' => "motd=Easy-Wi Minecraft 1.18.2\nview-distance=10\n",
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'powershell -Command \"Invoke-WebRequest -Uri https://api.papermc.io/v2/projects/paper/versions/1.18.2/builds/1/downloads/paper-1.18.2-1.jar -OutFile server.jar\"',
            'powershell -Command \"Invoke-WebRequest -Uri https://api.papermc.io/v2/projects/paper/versions/1.18.2/builds/1/downloads/paper-1.18.2-1.jar -OutFile server.jar\"',
            []
        );

        $this->insertTemplate(
            'cs2_windows',
            'Counter-Strike 2 Dedicated Server (Windows)',
            'SteamCMD install with Windows server binary.',
            730,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
                ['name' => 'tv', 'label' => 'SourceTV', 'protocol' => 'udp'],
            ],
            'srcds.exe -game cs2 -console -usercon -tickrate 128 +map de_dust2 +sv_setsteamaccount {{STEAM_GSLT}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi CS2'],
                ['key' => 'SERVER_PASSWORD', 'value' => ''],
                ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
                ['key' => 'STEAM_GSLT', 'value' => ''],
            ],
            [
                [
                    'path' => 'game/csgo/cfg/server.cfg',
                    'description' => 'Base server configuration',
                    'contents' => "hostname \"{{SERVER_NAME}}\"\nrcon_password \"{{RCON_PASSWORD}}\"\nsv_password \"{{SERVER_PASSWORD}}\"\nsv_lan 0\n",
                ],
            ],
            [
                'game/csgo/addons/metamod',
                'game/csgo/addons/counterstrikesharp',
            ],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +login anonymous +force_install_dir C:\\easywi\\gs +app_update 730 validate +quit',
            'steamcmd.exe +login anonymous +force_install_dir C:\\easywi\\gs +app_update 730 +quit',
            ['+map', '+maxplayers']
        );

        $this->insertTemplate(
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
            'srcds.exe -game csgo -console -usercon -tickrate 128 +map de_dust2 +sv_setsteamaccount {{STEAM_GSLT}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi CSGO'],
                ['key' => 'SERVER_PASSWORD', 'value' => ''],
                ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
                ['key' => 'STEAM_GSLT', 'value' => ''],
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
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +login anonymous +force_install_dir C:\\easywi\\gs +app_update 740 validate +quit',
            'steamcmd.exe +login anonymous +force_install_dir C:\\easywi\\gs +app_update 740 +quit',
            ['+map', '+maxplayers']
        );

        $this->insertTemplate(
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
            'srcds_run -game tf +map ctf_2fort +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi TF2'],
                ['key' => 'MAX_PLAYERS', 'value' => '24'],
                ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
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
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 232250 validate +quit',
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 232250 +quit',
            ['+map', '+maxplayers']
        );

        $this->insertTemplate(
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
            'srcds.exe -game tf +map ctf_2fort +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi TF2'],
                ['key' => 'MAX_PLAYERS', 'value' => '24'],
                ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
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
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +login anonymous +force_install_dir C:\\easywi\\gs +app_update 232250 validate +quit',
            'steamcmd.exe +login anonymous +force_install_dir C:\\easywi\\gs +app_update 232250 +quit',
            ['+map', '+maxplayers']
        );

        $this->insertTemplate(
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
            'srcds_run -game cstrike +map de_dust2 +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi CSS'],
                ['key' => 'MAX_PLAYERS', 'value' => '24'],
                ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
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
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 232330 validate +quit',
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 232330 +quit',
            ['+map', '+maxplayers']
        );

        $this->insertTemplate(
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
            'srcds.exe -game cstrike +map de_dust2 +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi CSS'],
                ['key' => 'MAX_PLAYERS', 'value' => '24'],
                ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
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
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +login anonymous +force_install_dir C:\\easywi\\gs +app_update 232330 validate +quit',
            'steamcmd.exe +login anonymous +force_install_dir C:\\easywi\\gs +app_update 232330 +quit',
            ['+map', '+maxplayers']
        );

        $this->insertTemplate(
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
            'srcds_run -game hl2mp +map dm_lockdown +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi HL2DM'],
                ['key' => 'MAX_PLAYERS', 'value' => '24'],
                ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
            ],
            [
                [
                    'path' => 'hl2mp/cfg/server.cfg',
                    'description' => 'Base server configuration',
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 232370 validate +quit',
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 232370 +quit',
            ['+map', '+maxplayers']
        );

        $this->insertTemplate(
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
            'srcds.exe -game hl2mp +map dm_lockdown +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi HL2DM'],
                ['key' => 'MAX_PLAYERS', 'value' => '24'],
                ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
            ],
            [
                [
                    'path' => 'hl2mp/cfg/server.cfg',
                    'description' => 'Base server configuration',
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +login anonymous +force_install_dir C:\\easywi\\gs +app_update 232370 validate +quit',
            'steamcmd.exe +login anonymous +force_install_dir C:\\easywi\\gs +app_update 232370 +quit',
            ['+map', '+maxplayers']
        );

        $this->insertTemplate(
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
            'srcds_run -game left4dead2 +map c1m1_hotel +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi L4D2'],
                ['key' => 'MAX_PLAYERS', 'value' => '8'],
                ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
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
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 222860 validate +quit',
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 222860 +quit',
            ['+map', '+maxplayers']
        );

        $this->insertTemplate(
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
            'srcds.exe -game left4dead2 +map c1m1_hotel +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi L4D2'],
                ['key' => 'MAX_PLAYERS', 'value' => '8'],
                ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
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
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +login anonymous +force_install_dir C:\\easywi\\gs +app_update 222860 validate +quit',
            'steamcmd.exe +login anonymous +force_install_dir C:\\easywi\\gs +app_update 222860 +quit',
            ['+map', '+maxplayers']
        );

        $this->insertTemplate(
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
            'srcds_run -game left4dead +map l4d_hospital01_apartment +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi L4D'],
                ['key' => 'MAX_PLAYERS', 'value' => '8'],
                ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
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
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 222840 validate +quit',
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 222840 +quit',
            ['+map', '+maxplayers']
        );

        $this->insertTemplate(
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
            'srcds.exe -game left4dead +map l4d_hospital01_apartment +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi L4D'],
                ['key' => 'MAX_PLAYERS', 'value' => '8'],
                ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
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
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +login anonymous +force_install_dir C:\\easywi\\gs +app_update 222840 validate +quit',
            'steamcmd.exe +login anonymous +force_install_dir C:\\easywi\\gs +app_update 222840 +quit',
            ['+map', '+maxplayers']
        );

        $this->insertTemplate(
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
            'srcds_run -game dod +map dod_anzio +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi DoD:S'],
                ['key' => 'MAX_PLAYERS', 'value' => '24'],
                ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
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
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 232290 validate +quit',
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 232290 +quit',
            ['+map', '+maxplayers']
        );

        $this->insertTemplate(
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
            'srcds.exe -game dod +map dod_anzio +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi DoD:S'],
                ['key' => 'MAX_PLAYERS', 'value' => '24'],
                ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
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
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +login anonymous +force_install_dir C:\\easywi\\gs +app_update 232290 validate +quit',
            'steamcmd.exe +login anonymous +force_install_dir C:\\easywi\\gs +app_update 232290 +quit',
            ['+map', '+maxplayers']
        );

        $this->insertPlugin(
            'minecraft_java',
            'EssentialsX',
            '2.20.1',
            'sha256:3f1a4a7991e2457c8b5e5a7b5b3f0e59a2f266f2fd5a1d7f6b82bbf83cb5e6c2',
            'https://github.com/EssentialsX/Essentials/releases/download/2.20.1/EssentialsX-2.20.1.jar',
            'Core commands and moderation suite.'
        );

        $this->insertPlugin(
            'minecraft_java',
            'ViaVersion',
            '5.0.0',
            'sha256:2c3f1b6c6c8e4c7e3c1d6d5c8b9c7e7f7e0a2f3b4c5d6e7f8a9b0c1d2e3f4a5',
            'https://github.com/ViaVersion/ViaVersion/releases/download/5.0.0/ViaVersion-5.0.0.jar',
            'Protocol compatibility layer for mixed client versions.'
        );

        $this->insertPlugin(
            'minecraft_java',
            'LuckPerms',
            '5.4.138',
            'sha256:0fdc75f2603c9b975a5e1f9bfe1b6d2e1c0e43e2a4efb0d4c39d296f04a5e1d8',
            'https://github.com/LuckPerms/LuckPerms/releases/download/v5.4.138/LuckPerms-Bukkit-5.4.138.jar',
            'Permissions management plugin.'
        );

        $this->insertPlugin(
            'minecraft_java',
            'PlaceholderAPI',
            '2.11.6',
            'sha256:4d1c2e3f4a5b6c7d8e9f0a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1',
            'https://github.com/PlaceholderAPI/PlaceholderAPI/releases/download/2.11.6/PlaceholderAPI-2.11.6.jar',
            'Placeholder support for chat and scoreboards.'
        );

        $this->insertPlugin(
            'minecraft_java',
            'WorldEdit',
            '7.3.4',
            'sha256:fbfc2c2a1f2f2a2d8b2e1846b3f6e0e6b8f2c6245a2f3c6a394e228bc41a786a',
            'https://github.com/EngineHub/WorldEdit/releases/download/7.3.4/worldedit-bukkit-7.3.4.jar',
            'In-game map editor and builder tools.'
        );

        $this->insertPlugin(
            'minecraft_java',
            'Vault',
            '1.7.3',
            'sha256:8f6b8b7bcd1fd6b2d9f9a16d637e9a3b61abdb928f8d410911a8b905c71e7021',
            'https://github.com/MilkBowl/Vault/releases/download/1.7.3/Vault.jar',
            'Economy and permissions bridge.'
        );

        $this->insertPlugin(
            'minecraft_java',
            'Geyser',
            '2.4.1',
            'sha256:8f0e1d2c3b4a59687766554433221100ffeeddccbbaa99887766554433221100',
            'https://github.com/GeyserMC/Geyser/releases/download/v2.4.1/Geyser-Spigot.jar',
            'Allow Bedrock clients to join Java servers.'
        );

        $this->insertPlugin(
            'minecraft_java',
            'Floodgate',
            '2.2.3',
            'sha256:1a2b3c4d5e6f7081928374655647382910ffeeddccbbaa998877665544332211',
            'https://github.com/GeyserMC/Floodgate/releases/download/v2.2.3/Floodgate-Spigot.jar',
            'Authentication bridge for Geyser Bedrock players.'
        );

        $this->insertPlugin(
            'cs2',
            'MetaMod:Source',
            '2.0.0',
            'sha256:2157a4a2a7a5b93e1d3e9c0a12a0b0b3f6b5f4d6f4e81375a944f9b6132f9b23',
            'https://mms.alliedmods.net/mmsdrop/2.0/mmsource-2.0.0-git1247-linux.tar.gz',
            'Core plugin loader for Source-based servers.'
        );

        $this->insertPlugin(
            'cs2',
            'CounterStrikeSharp',
            '1.0.197',
            'sha256:3c0a47f2e904d06a6ce7f2ad6a4c2969a9b76f513f3f067d09c8b4b9b9d7a14e',
            'https://github.com/roflmuffin/CounterStrikeSharp/releases/download/v1.0.197/counterstrikesharp-linux.zip',
            'Managed plugin framework for CS2.'
        );

        $this->insertPlugin(
            'rust',
            'uMod (Oxide)',
            '2.0.6334',
            'sha256:7b6f2c7b81e7a4c6f4868f0b2f3eb8d1a1b9c2b1c9d1a0d5a3e5f9b2e1c7f3a4',
            'https://umod.org/games/rust/download/develop',
            'Modding framework for Rust servers.'
        );
    }

    public function down(Schema $schema): void
    {
        $this->deletePlugin('minecraft_java', 'EssentialsX');
        $this->deletePlugin('minecraft_java', 'ViaVersion');
        $this->deletePlugin('minecraft_java', 'LuckPerms');
        $this->deletePlugin('minecraft_java', 'PlaceholderAPI');
        $this->deletePlugin('minecraft_java', 'WorldEdit');
        $this->deletePlugin('minecraft_java', 'Vault');
        $this->deletePlugin('minecraft_java', 'Geyser');
        $this->deletePlugin('minecraft_java', 'Floodgate');
        $this->deletePlugin('cs2', 'MetaMod:Source');
        $this->deletePlugin('cs2', 'CounterStrikeSharp');
        $this->deletePlugin('rust', 'uMod (Oxide)');

        $this->deleteTemplate('palworld');
        $this->deleteTemplate('palworld_windows');
        $this->deleteTemplate('garrys_mod');
        $this->deleteTemplate('terraria');
        $this->deleteTemplate('seven_days_to_die');
        $this->deleteTemplate('factorio');
        $this->deleteTemplate('valheim_windows');
        $this->deleteTemplate('satisfactory_windows');
        $this->deleteTemplate('dayz');
        $this->deleteTemplate('dayz_windows');
        $this->deleteTemplate('v_rising');
        $this->deleteTemplate('v_rising_windows');
        $this->deleteTemplate('enshrouded_windows');
        $this->deleteTemplate('minecraft_java_1_19_4');
        $this->deleteTemplate('minecraft_java_1_18_2');
        $this->deleteTemplate('minecraft_java_windows');
        $this->deleteTemplate('minecraft_java_1_19_4_windows');
        $this->deleteTemplate('minecraft_java_1_18_2_windows');
        $this->deleteTemplate('cs2_windows');
        $this->deleteTemplate('csgo_legacy_windows');
        $this->deleteTemplate('tf2');
        $this->deleteTemplate('tf2_windows');
        $this->deleteTemplate('css');
        $this->deleteTemplate('css_windows');
        $this->deleteTemplate('hl2dm');
        $this->deleteTemplate('hl2dm_windows');
        $this->deleteTemplate('l4d2');
        $this->deleteTemplate('l4d2_windows');
        $this->deleteTemplate('l4d');
        $this->deleteTemplate('l4d_windows');
        $this->deleteTemplate('dods');
        $this->deleteTemplate('dods_windows');
        $this->deleteTemplate('project_zomboid');
        $this->deleteTemplate('project_zomboid_windows');
        $this->deleteTemplate('conan_exiles');
        $this->deleteTemplate('conan_exiles_windows');
        $this->deleteTemplate('arma3');
        $this->deleteTemplate('arma3_windows');
    }

    private function insertTemplate(
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
    ): void {
        $sql = sprintf(
            'INSERT INTO game_templates (game_key, display_name, description, steam_app_id, sniper_profile, required_ports, start_params, env_vars, config_files, plugin_paths, fastdl_settings, install_command, update_command, allowed_switch_flags, created_at, updated_at) '
            . 'SELECT %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s '
            . 'WHERE NOT EXISTS (SELECT 1 FROM game_templates WHERE game_key = %s)',
            $this->quote($gameKey),
            $this->quote($displayName),
            $this->quote($description),
            $steamAppId === null ? 'NULL' : (string) $steamAppId,
            $this->quote($sniperProfile),
            $this->quoteJson($requiredPorts),
            $this->quote($startParams),
            $this->quoteJson($envVars),
            $this->quoteJson($configFiles),
            $this->quoteJson($pluginPaths),
            $this->quoteJson($fastdlSettings),
            $this->quote($installCommand),
            $this->quote($updateCommand),
            $this->quoteJson($allowedSwitchFlags),
            $this->currentTimestampExpression(),
            $this->currentTimestampExpression(),
            $this->quote($gameKey),
        );

        $this->addSql($sql);
    }

    private function insertPlugin(
        string $templateGameKey,
        string $name,
        string $version,
        string $checksum,
        string $downloadUrl,
        ?string $description,
    ): void {
        $sql = sprintf(
            'INSERT INTO game_template_plugins (template_id, name, version, checksum, download_url, description, created_at, updated_at) '
            . 'SELECT template.id, %s, %s, %s, %s, %s, %s, %s '
            . 'FROM game_templates template '
            . 'WHERE template.game_key = %s '
            . 'AND NOT EXISTS (SELECT 1 FROM game_template_plugins WHERE template_id = template.id AND name = %s)',
            $this->quote($name),
            $this->quote($version),
            $this->quote($checksum),
            $this->quote($downloadUrl),
            $this->quote($description),
            $this->currentTimestampExpression(),
            $this->currentTimestampExpression(),
            $this->quote($templateGameKey),
            $this->quote($name),
        );

        $this->addSql($sql);
    }

    private function deletePlugin(string $templateGameKey, string $name): void
    {
        $this->addSql(sprintf(
            'DELETE FROM game_template_plugins WHERE name = %s AND template_id IN (SELECT id FROM game_templates WHERE game_key = %s)',
            $this->quote($name),
            $this->quote($templateGameKey),
        ));
    }

    private function deleteTemplate(string $gameKey): void
    {
        $this->addSql(sprintf(
            'DELETE FROM game_templates WHERE game_key = %s',
            $this->quote($gameKey),
        ));
    }

    private function quote(?string $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        return $this->connection->quote($value);
    }

    private function quoteJson(array $value): string
    {
        return $this->connection->quote($this->jsonEncode($value));
    }

    private function jsonEncode(array $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '[]' : $encoded;
    }

    private function currentTimestampExpression(): string
    {
        return $this->isSqlite() ? 'CURRENT_TIMESTAMP' : 'CURRENT_TIMESTAMP()';
    }

    private function isSqlite(): bool
    {
        return $this->connection->getDatabasePlatform()->getName() === 'sqlite';
    }
}
