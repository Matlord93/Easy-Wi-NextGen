<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

final class GamePluginSeedCatalog
{
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
                'github://alliedmodders/metamod-source/releases/latest?asset=mmsource-*-linux.tar.gz',
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
     * @return array<int, array<string, mixed>>
     */
    private function source1Plugins(): array
    {
        $mmsUrl = 'github://alliedmodders/metamod-source/releases/latest?asset=mmsource-*-linux.tar.gz';
        $smUrl  = 'github://alliedmodders/sourcemod/releases/latest?asset=sourcemod-*-linux.tar.gz';
        $mmsVer = '2.0.0-dev';
        $smVer  = '1.13.0-dev';

        $games = [
            'csgo_legacy' => 'csgo',
            'css' => 'cstrike',
            'tf2' => 'tf',
            'hl2dm' => 'hl2mp',
            'dods' => 'dod',
            'l4d' => 'left4dead',
            'l4d2' => 'left4dead2',
            'garrys_mod' => 'garrysmod',
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
            $this->plugin('minecraft_paper_all', 'EssentialsX', 'latest', '', 'https://github.com/EssentialsX/Essentials/releases/latest/download/EssentialsX.jar', 'Core commands, economy, and moderation suite.', 'copy', 'plugins'),
            $this->plugin('minecraft_paper_all', 'LuckPerms', '5.4.151', '', 'https://download.luckperms.net/1567/bukkit/loader/LuckPerms-Bukkit-5.4.151.jar', 'Advanced permissions management plugin.', 'copy', 'plugins'),
            $this->plugin('minecraft_paper_all', 'ViaVersion', 'latest', '', 'https://github.com/ViaVersion/ViaVersion/releases/latest/download/ViaVersion.jar', 'Protocol compatibility layer — lets different client versions join.', 'copy', 'plugins'),
            $this->plugin('minecraft_paper_all', 'PlaceholderAPI', 'latest', '', 'https://github.com/PlaceholderAPI/PlaceholderAPI/releases/latest/download/PlaceholderAPI.jar', 'Placeholder expansion support for chat, scoreboards, and more.', 'copy', 'plugins'),
            $this->plugin('minecraft_paper_all', 'WorldEdit', 'latest', '', 'https://github.com/EngineHub/WorldEdit/releases/latest/download/worldedit-bukkit.jar', 'In-game map editor and builder tools.', 'copy', 'plugins'),
            $this->plugin('minecraft_paper_all', 'Vault', '1.7.3', '', 'https://github.com/MilkBowl/Vault/releases/download/1.7.3/Vault.jar', 'Economy and permissions API bridge.', 'copy', 'plugins'),
            $this->plugin('minecraft_paper_all', 'Geyser', 'latest', '', 'https://download.geysermc.org/v2/projects/geyser/versions/latest/builds/latest/downloads/spigot', 'Allows Bedrock Edition clients to connect to Java servers.', 'copy', 'plugins'),
            $this->plugin('minecraft_paper_all', 'Floodgate', 'latest', '', 'https://download.geysermc.org/v2/projects/floodgate/versions/latest/builds/latest/downloads/spigot', 'Authenticate Bedrock players without a Java account (use with Geyser).', 'copy', 'plugins'),
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
