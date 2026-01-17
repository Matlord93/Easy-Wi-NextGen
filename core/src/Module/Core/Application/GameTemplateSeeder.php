<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\GamePlugin;
use App\Module\Core\Domain\Entity\Template;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\EntityManagerInterface;

final class GameTemplateSeeder
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly GameTemplateSeedCatalog $catalog,
    ) {
    }

    /**
     * @return array{templates: int, plugins: int}
     */
    public function seed(?EntityManagerInterface $entityManager = null): array
    {
        $entityManager = $entityManager ?? $this->registry->getManager();
        $templateRepository = $entityManager->getRepository(Template::class);
        $pluginRepository = $entityManager->getRepository(GamePlugin::class);

        $templatesCreated = 0;
        foreach ($this->catalog->listTemplates() as $templateData) {
            $gameKey = (string) ($templateData['game_key'] ?? '');
            if ($gameKey === '') {
                continue;
            }

            if ($templateRepository->findOneBy(['gameKey' => $gameKey]) !== null) {
                continue;
            }

            $requiredPorts = $templateData['required_ports'] ?? [];
            $envVars = $templateData['env_vars'] ?? [];
            $configFiles = $templateData['config_files'] ?? [];
            $pluginPaths = $templateData['plugin_paths'] ?? [];
            $fastdlSettings = $templateData['fastdl_settings'] ?? [];
            $allowedSwitchFlags = $templateData['allowed_switch_flags'] ?? [];
            $installResolver = $templateData['install_resolver'] ?? [];
            $requirementVars = $templateData['requirement_vars'] ?? [];
            $requirementSecrets = $templateData['requirement_secrets'] ?? [];

            $steamAppId = $templateData['steam_app_id'] ?? null;
            $supportedOs = $templateData['supported_os'] ?? $this->resolveSupportedOs($gameKey);
            $portProfile = $templateData['port_profile'] ?? $this->buildPortProfile($requiredPorts);
            $requirements = $templateData['requirements'] ?? $this->buildRequirements(
                $gameKey,
                is_int($steamAppId) ? $steamAppId : null,
                $envVars,
                $requirementVars,
                $requirementSecrets,
            );

            $template = new Template(
                $gameKey,
                (string) ($templateData['display_name'] ?? $gameKey),
                $templateData['description'] ?? null,
                is_int($steamAppId) ? $steamAppId : null,
                $templateData['sniper_profile'] ?? null,
                $requiredPorts,
                (string) ($templateData['start_params'] ?? ''),
                $envVars,
                $configFiles,
                $pluginPaths,
                $fastdlSettings,
                (string) ($templateData['install_command'] ?? ''),
                (string) ($templateData['update_command'] ?? ''),
                $installResolver,
                $allowedSwitchFlags,
                $requirementVars,
                $requirementSecrets,
                $supportedOs,
                $portProfile,
                $requirements,
            );

            $entityManager->persist($template);
            $templatesCreated++;
        }

        if ($templatesCreated > 0) {
            $entityManager->flush();
        }

        $pluginsCreated = 0;
        foreach ($this->catalog->listPlugins() as $pluginData) {
            $templateGameKey = (string) ($pluginData['template_game_key'] ?? '');
            $pluginName = (string) ($pluginData['name'] ?? '');
            if ($templateGameKey === '' || $pluginName === '') {
                continue;
            }

            $template = $templateRepository->findOneBy(['gameKey' => $templateGameKey]);
            if (!$template instanceof Template) {
                continue;
            }

            $existingPlugin = $pluginRepository->findOneBy([
                'template' => $template,
                'name' => $pluginName,
            ]);
            if ($existingPlugin !== null) {
                continue;
            }

            $plugin = new GamePlugin(
                $template,
                $pluginName,
                (string) ($pluginData['version'] ?? ''),
                (string) ($pluginData['checksum'] ?? ''),
                (string) ($pluginData['download_url'] ?? ''),
                $pluginData['description'] ?? null,
            );

            $entityManager->persist($plugin);
            $pluginsCreated++;
        }

        if ($pluginsCreated > 0) {
            $entityManager->flush();
        }

        return [
            'templates' => $templatesCreated,
            'plugins' => $pluginsCreated,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function resolveSupportedOs(string $gameKey): array
    {
        return str_ends_with($gameKey, '_windows') ? ['windows'] : ['linux'];
    }

    /**
     * @param array<int, array<string, mixed>> $requiredPorts
     * @return array<int, array<string, mixed>>
     */
    private function buildPortProfile(array $requiredPorts): array
    {
        $roleMap = [
            'game' => 'game',
            'query' => 'query',
            'rcon' => 'rcon',
            'tv' => 'tv',
            'voice' => 'voice',
            'filetransfer' => 'filetransfer',
        ];

        $profile = [];
        foreach ($requiredPorts as $port) {
            if (!is_array($port)) {
                continue;
            }
            $name = strtolower((string) ($port['name'] ?? 'game'));
            $role = $roleMap[$name] ?? $name;
            $protocol = (string) ($port['protocol'] ?? 'udp');
            $count = (int) ($port['count'] ?? 1);
            if ($count <= 0) {
                $count = 1;
            }

            $profile[] = [
                'role' => $role,
                'protocol' => $protocol,
                'count' => $count,
                'required' => isset($port['required']) ? (bool) $port['required'] : true,
                'contiguous' => isset($port['contiguous']) ? (bool) $port['contiguous'] : false,
            ];
        }

        return $profile;
    }

    /**
     * @param array<int, array<string, mixed>> $envVars
     * @param array<int, array<string, mixed>> $requirementVars
     * @param array<int, array<string, mixed>> $requirementSecrets
     * @return array<string, mixed>
     */
    private function buildRequirements(
        string $gameKey,
        ?int $steamAppId,
        array $envVars,
        array $requirementVars,
        array $requirementSecrets,
    ): array {
        $envVarKeys = $this->extractEnvVarKeys($envVars);
        $requiredVars = $this->normalizeRequirementKeys($requirementVars);
        if ($requiredVars === []) {
            $requiredVars = $envVarKeys;
        }
        $requiredSecrets = $this->normalizeSecretKeys($requirementSecrets, $gameKey);

        return [
            'required_vars' => $requiredVars,
            'required_secrets' => $requiredSecrets,
            'steam_install_mode' => $this->resolveSteamInstallMode($gameKey, $steamAppId),
            'customer_allowed_vars' => $envVarKeys,
            'customer_allowed_secrets' => $requiredSecrets,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $envVars
     * @return array<int, string>
     */
    private function extractEnvVarKeys(array $envVars): array
    {
        $keys = [];
        foreach ($envVars as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $key = trim((string) ($entry['key'] ?? ''));
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param array<int, array<string, mixed>> $requirementSecrets
     * @return array<int, string>
     */
    private function normalizeSecretKeys(array $requirementSecrets, string $gameKey): array
    {
        $keys = [];
        foreach ($requirementSecrets as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $key = trim((string) ($entry['key'] ?? ''));
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        if ($this->isCsTemplate($gameKey) && !in_array('STEAM_GSLT', $keys, true)) {
            $keys[] = 'STEAM_GSLT';
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param array<int, array<string, mixed>> $requirementVars
     * @return array<int, string>
     */
    private function normalizeRequirementKeys(array $requirementVars): array
    {
        $keys = [];
        foreach ($requirementVars as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $key = trim((string) ($entry['key'] ?? ''));
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    private function resolveSteamInstallMode(string $gameKey, ?int $steamAppId): string
    {
        if ($this->isMinecraftNoSteam($gameKey)) {
            return 'none';
        }

        return $steamAppId !== null ? 'anonymous' : 'none';
    }

    private function isMinecraftNoSteam(string $gameKey): bool
    {
        return in_array($gameKey, [
            'minecraft_paper',
            'minecraft_vanilla',
            'minecraft_paper_windows',
            'minecraft_vanilla_windows',
            'minecraft_paper_all',
            'minecraft_vanilla_all',
            'minecraft_bedrock',
        ], true);
    }

    private function isCsTemplate(string $gameKey): bool
    {
        return in_array($gameKey, [
            'cs2',
            'csgo_legacy',
            'cs2_windows',
            'csgo_legacy_windows',
        ], true);
    }
}
