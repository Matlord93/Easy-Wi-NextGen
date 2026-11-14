<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Template;
use App\Infrastructure\Config\JsonArray;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/** @psalm-import-type TemplateSeed from GameTemplateSeedCatalog */
final class GameTemplateSeeder
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly GameTemplateSeedCatalog $catalog,
        private readonly GamePluginSeeder $pluginSeeder,
    ) {
    }

    /**
     * @return array{templates: int, templates_updated: int, plugins: int, plugins_updated: int,
     * skipped_missing_template: int, missing_game_keys: array<int, string>}
     */
    public function seed(?EntityManagerInterface $entityManager = null, bool $updateExisting = false): array
    {
        $entityManager ??= $this->registry->getManager();
        if (!$entityManager instanceof EntityManagerInterface) {
            throw new \LogicException('Game templates require an ORM entity manager.');
        }
        $templateStats = $this->seedTemplatesOnly($entityManager);
        $pluginResult = $this->pluginSeeder->seed($entityManager, $updateExisting);

        return [
            'templates' => $templateStats['created'],
            'templates_updated' => $templateStats['updated'],
            'plugins' => $pluginResult['plugins'],
            'plugins_updated' => $pluginResult['updated'],
            'skipped_missing_template' => $pluginResult['skipped_missing_template'],
            'missing_game_keys' => $pluginResult['missing_game_keys'],
        ];
    }

    /**
     * @return array{created: int, updated: int}
     */
    public function seedTemplatesOnly(?EntityManagerInterface $entityManager = null, ?array $onlyGameKeys = null, bool $createOnly = false): array
    {
        $entityManager ??= $this->registry->getManager();
        if (!$entityManager instanceof EntityManagerInterface) {
            throw new \LogicException('Game templates require an ORM entity manager.');
        }
        $templateRepository = $entityManager->getRepository(Template::class);
        $templatesCreated = 0;
        $templatesUpdated = 0;
        foreach ($this->catalog->listTemplates() as $templateData) {
            $gameKey = ($templateData['game_key'] ?? '');
            if ($gameKey === '') {
                continue;
            }

            if ($onlyGameKeys !== null && !in_array($gameKey, $onlyGameKeys, true)) { continue; }
            $existingTemplate = $templateRepository->findOneBy(['gameKey' => $gameKey]);
            if ($createOnly && $existingTemplate instanceof Template) { continue; }

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

            $sharedPaths = $templateData['shared_paths'] ?? [];
            if ($sharedPaths !== []) {
                $requirements['shared_paths'] = $sharedPaths;
            }

            if ($existingTemplate instanceof Template) {
                $this->updateTemplateFromSeed(
                    $existingTemplate,
                    $templateData,
                    $requiredPorts,
                    $envVars,
                    $configFiles,
                    $pluginPaths,
                    $fastdlSettings,
                    $installResolver,
                    $allowedSwitchFlags,
                    $requirementVars,
                    $requirementSecrets,
                    $supportedOs,
                    $portProfile,
                    $requirements,
                );
                $templatesUpdated++;
                continue;
            }

            $template = new Template(
                $gameKey,
                ($templateData['display_name'] ?? $gameKey),
                $templateData['description'] ?? null,
                is_int($steamAppId) ? $steamAppId : null,
                $templateData['sniper_profile'] ?? null,
                $requiredPorts,
                ($templateData['start_params'] ?? ''),
                $envVars,
                $configFiles,
                $pluginPaths,
                $fastdlSettings,
                ($templateData['install_command'] ?? ''),
                ($templateData['update_command'] ?? ''),
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

        try {
            $entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // A concurrent installer request already seeded the templates; discard our pending inserts.
            $entityManager->clear();
            $templatesCreated = 0;
        }

        return ['created' => $templatesCreated, 'updated' => $templatesUpdated];
    }

    /**
     * @param TemplateSeed $templateData
     * @param array<int, array<string, mixed>> $requiredPorts
     * @param array<int, array<string, mixed>> $envVars
     * @param array<int, array<string, mixed>> $configFiles
     * @param array<int, string> $pluginPaths
     * @param array<string, mixed> $fastdlSettings
     * @param array<string, mixed> $installResolver
     * @param array<int, string> $allowedSwitchFlags
     * @param array<int, array<string, mixed>> $requirementVars
     * @param array<int, array<string, mixed>> $requirementSecrets
     * @param array<int, string> $supportedOs
     * @param array<int, array<string, mixed>> $portProfile
     * @param array<string, mixed> $requirements
     */
    private function updateTemplateFromSeed(
        Template $template,
        array $templateData,
        array $requiredPorts,
        array $envVars,
        array $configFiles,
        array $pluginPaths,
        array $fastdlSettings,
        array $installResolver,
        array $allowedSwitchFlags,
        array $requirementVars,
        array $requirementSecrets,
        array $supportedOs,
        array $portProfile,
        array $requirements,
    ): void {
        $steamAppId = $templateData['steam_app_id'] ?? null;

        $template->setDisplayName(($templateData['display_name'] ?? $template->getGameKey()));
        $template->setDescription($templateData['description'] ?? null);
        $template->setSteamAppId(is_int($steamAppId) ? $steamAppId : null);
        $template->setSniperProfile($templateData['sniper_profile'] ?? null);
        $template->setRequiredPorts($requiredPorts);
        $template->setStartParams(($templateData['start_params'] ?? ''));
        $template->setEnvVars($envVars);
        $template->setConfigFiles($configFiles);
        $template->setPluginPaths($pluginPaths);
        $template->setFastdlSettings($fastdlSettings);
        $template->setInstallCommand(($templateData['install_command'] ?? ''));
        $template->setUpdateCommand(($templateData['update_command'] ?? ''));
        $template->setInstallResolver($installResolver);
        $template->setAllowedSwitchFlags($allowedSwitchFlags);
        $template->setRequirementVars($requirementVars);
        $template->setRequirementSecrets($requirementSecrets);
        $template->setSupportedOs($supportedOs);
        $template->setPortProfile($portProfile);
        $template->setRequirements($requirements);
    }

    /**
     * @return array<int, string>
     */
    private function resolveSupportedOs(string $gameKey): array
    {
        return str_ends_with($gameKey, '_windows') ? ['windows'] : ['linux'];
    }

    /**
     * @param array<array-key, mixed> $requiredPorts
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
        foreach (array_keys($requiredPorts) as $index) {
            $port = JsonArray::fromValue($requiredPorts[$index]);
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
     * @return array{required_vars: array<int, string>, required_secrets: array<int, string>,
     * steam_install_mode: string, customer_allowed_vars: array<int, string>, customer_allowed_secrets:
     * array<int, string>, query?: array<string, string>}
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

        $requirements = [
            'required_vars' => $requiredVars,
            'required_secrets' => $requiredSecrets,
            'steam_install_mode' => $this->resolveSteamInstallMode($gameKey, $steamAppId),
            'customer_allowed_vars' => $envVarKeys,
            'customer_allowed_secrets' => $requiredSecrets,
        ];

        $queryDefaults = $this->resolveQueryDefaults($gameKey, $steamAppId);
        if ($queryDefaults !== null) {
            $requirements['query'] = $queryDefaults;
        }

        return $requirements;
    }

    /**
     * @return array<string, string>|null
     */
    private function resolveQueryDefaults(string $gameKey, ?int $steamAppId): ?array
    {
        if ($steamAppId === null) {
            return null;
        }

        if ($this->isSource2Template($gameKey)) {
            return [
                'type' => 'steam_a2s',
                'query_port_behavior' => 'explicit',
            ];
        }

        return [
            'type' => 'steam_a2s',
            'query_port_behavior' => 'same_as_game_port',
        ];
    }

    /**
     * @param array<array-key, mixed> $envVars
     * @return array<int, string>
     */
    private function extractEnvVarKeys(array $envVars): array
    {
        $keys = [];
        foreach (array_keys($envVars) as $index) {
            $entry = JsonArray::fromValue($envVars[$index]);
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
     * @param array<array-key, mixed> $requirementSecrets
     * @return array<int, string>
     */
    private function normalizeSecretKeys(array $requirementSecrets, string $gameKey): array
    {
        $keys = [];
        foreach (array_keys($requirementSecrets) as $index) {
            $entry = JsonArray::fromValue($requirementSecrets[$index]);
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
     * @param array<array-key, mixed> $requirementVars
     * @return array<int, string>
     */
    private function normalizeRequirementKeys(array $requirementVars): array
    {
        $keys = [];
        foreach (array_keys($requirementVars) as $index) {
            $entry = JsonArray::fromValue($requirementVars[$index]);
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

    private function isSource2Template(string $gameKey): bool
    {
        $normalized = strtolower(trim($gameKey));

        return $normalized === 'cs2' || str_starts_with($normalized, 'cs2_') || str_contains($normalized, '_cs2') || str_contains($normalized, 'source2');
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
            'minecraft_spigot_all',
            'minecraft_bungeecord_all',
            'hytale',
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
