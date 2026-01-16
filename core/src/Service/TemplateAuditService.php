<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Template;
use App\Repository\TemplateRepository;
use Doctrine\ORM\EntityManagerInterface;

final class TemplateAuditService
{
    public function __construct(
        private readonly TemplateRepository $templateRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<int, Template>
     */
    public function getTemplates(): array
    {
        return $this->templateRepository->findAll();
    }

    /**
     * @param array<int, Template> $templates
     * @return array<int, array{base_key: string, steam_app_id: int|null, templates: array<int, Template>}>
     */
    public function findOsDuplicateGroups(array $templates): array
    {
        $groups = [];
        foreach ($templates as $template) {
            $steamAppId = $template->getSteamAppId();
            $baseKey = $this->resolveBaseGameKey($template->getGameKey());
            $os = $this->resolveTemplateOs($template);
            if ($os === null) {
                continue;
            }

            $groupKey = sprintf('%s:%s', $steamAppId ?? 'null', $baseKey);
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'base_key' => $baseKey,
                    'steam_app_id' => $steamAppId,
                    'templates' => [],
                    'os' => [],
                ];
            }

            $groups[$groupKey]['templates'][] = $template;
            $groups[$groupKey]['os'][$os] = true;
        }

        $filtered = [];
        foreach ($groups as $group) {
            if (count($group['templates']) < 2) {
                continue;
            }
            if (count($group['os']) < 2) {
                continue;
            }
            $filtered[] = [
                'base_key' => $group['base_key'],
                'steam_app_id' => $group['steam_app_id'],
                'templates' => $group['templates'],
            ];
        }

        return $filtered;
    }

    /**
     * @param array<int, Template> $templates
     * @return array<int, array{signature: string, templates: array<int, Template>}>
     */
    public function findCommandDuplicateGroups(array $templates): array
    {
        $groups = [];
        foreach ($templates as $template) {
            $signature = $this->buildCommandSignature($template);
            $groups[$signature][] = $template;
        }

        $filtered = [];
        foreach ($groups as $signature => $entries) {
            if (count($entries) < 2) {
                continue;
            }
            $filtered[] = [
                'signature' => $signature,
                'templates' => $entries,
            ];
        }

        return $filtered;
    }

    /**
     * @param array<int, Template> $templates
     * @return array<int, array{template: Template, issues: array<int, string>}>
     */
    public function findInvalidTemplates(array $templates): array
    {
        $invalid = [];
        foreach ($templates as $template) {
            $issues = [];
            $gameKey = $template->getGameKey();

            $requiredPorts = $template->getRequiredPorts();
            if (!is_array($requiredPorts) || $requiredPorts === []) {
                $issues[] = 'required_ports missing or empty.';
            } else {
                foreach ($requiredPorts as $index => $entry) {
                    if (!is_array($entry)) {
                        $issues[] = sprintf('required_ports entry %d must be an object.', $index + 1);
                        continue;
                    }
                    $name = trim((string) ($entry['name'] ?? ''));
                    $protocol = trim((string) ($entry['protocol'] ?? ''));
                    if ($name === '' || $protocol === '') {
                        $issues[] = sprintf('required_ports entry %d missing name/protocol.', $index + 1);
                    }
                }
            }

            $portProfile = $template->getPortProfile();
            if (!is_array($portProfile) || $portProfile === []) {
                $issues[] = 'port_profile missing or empty.';
            } else {
                foreach ($portProfile as $index => $entry) {
                    if (!is_array($entry)) {
                        $issues[] = sprintf('port_profile entry %d must be an object.', $index + 1);
                        continue;
                    }
                    $role = trim((string) ($entry['role'] ?? ''));
                    $protocol = trim((string) ($entry['protocol'] ?? ''));
                    $count = $entry['count'] ?? null;
                    if ($role === '' || $protocol === '') {
                        $issues[] = sprintf('port_profile entry %d missing role/protocol.', $index + 1);
                    }
                    if (!is_int($count) || $count < 1) {
                        $issues[] = sprintf('port_profile entry %d count must be >= 1.', $index + 1);
                    }
                }
            }

            if (trim($template->getInstallCommand()) === '') {
                $issues[] = 'install_command is empty.';
            }

            if (trim($template->getStartParams()) === '') {
                $issues[] = 'start_params is empty.';
            }

            $configFiles = $template->getConfigFiles();
            if (is_array($configFiles) && $configFiles !== []) {
                $paths = [];
                foreach ($configFiles as $index => $entry) {
                    if (!is_array($entry)) {
                        $issues[] = sprintf('config_files entry %d must be an object.', $index + 1);
                        continue;
                    }
                    $path = trim((string) ($entry['path'] ?? ''));
                    if ($path === '') {
                        $issues[] = sprintf('config_files entry %d missing path.', $index + 1);
                        continue;
                    }
                    $normalized = strtolower($path);
                    if (isset($paths[$normalized])) {
                        $issues[] = sprintf('config_files path duplicated: %s.', $path);
                    }
                    $paths[$normalized] = true;
                }
            }

            if ($issues !== []) {
                $invalid[] = [
                    'template' => $template,
                    'issues' => $issues,
                ];
            }
        }

        return $invalid;
    }

    /**
     * @param array<int, Template> $templates
     * @return array<int, Template>
     */
    public function findUnusedTemplates(array $templates): array
    {
        $usageTable = $this->resolveUsageTable();
        if ($usageTable === null) {
            return [];
        }

        $usage = $this->getUsageCounts($usageTable);
        $unused = [];
        foreach ($templates as $template) {
            $count = $usage[$template->getId() ?? 0] ?? 0;
            if ($count === 0) {
                $unused[] = $template;
            }
        }

        return $unused;
    }

    public function resolveUsageTable(): ?string
    {
        $schemaManager = $this->entityManager->getConnection()->createSchemaManager();
        $tableNames = array_map('strtolower', $schemaManager->listTableNames());

        foreach (['server_instances', 'instances'] as $table) {
            if (!in_array($table, $tableNames, true)) {
                continue;
            }

            $columns = $schemaManager->listTableColumns($table);
            if (array_key_exists('template_id', $columns)) {
                return $table;
            }
        }

        return null;
    }

    /**
     * @return array<int, int>
     */
    public function getUsageCounts(string $table): array
    {
        $connection = $this->entityManager->getConnection();
        $rows = $connection->fetchAllAssociative(sprintf('SELECT template_id, COUNT(*) AS total FROM %s GROUP BY template_id', $table));
        $usage = [];
        foreach ($rows as $row) {
            $usage[(int) $row['template_id']] = (int) $row['total'];
        }

        return $usage;
    }

    public function buildCommandSignature(Template $template): string
    {
        $signature = implode('|', [
            $this->normalizeCommand($template->getStartParams()),
            $this->normalizeCommand($template->getInstallCommand()),
            $this->normalizeCommand($template->getUpdateCommand()),
        ]);

        return hash('sha256', $signature);
    }

    public function buildExactSignature(Template $template): string
    {
        $payload = [
            'game_key' => $template->getGameKey(),
            'display_name' => $template->getDisplayName(),
            'description' => $template->getDescription(),
            'steam_app_id' => $template->getSteamAppId(),
            'sniper_profile' => $template->getSniperProfile(),
            'required_ports' => $template->getRequiredPorts(),
            'start_params' => $template->getStartParams(),
            'env_vars' => $template->getEnvVars(),
            'config_files' => $template->getConfigFiles(),
            'plugin_paths' => $template->getPluginPaths(),
            'fastdl_settings' => $template->getFastdlSettings(),
            'install_command' => $template->getInstallCommand(),
            'update_command' => $template->getUpdateCommand(),
            'install_resolver' => $template->getInstallResolver(),
            'allowed_switch_flags' => $template->getAllowedSwitchFlags(),
            'requirement_vars' => $template->getRequirementVars(),
            'requirement_secrets' => $template->getRequirementSecrets(),
            'supported_os' => $template->getSupportedOs(),
            'port_profile' => $template->getPortProfile(),
            'requirements' => $template->getRequirements(),
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function resolveBaseGameKey(string $gameKey): string
    {
        return preg_replace('/_(windows|linux)$/', '', $gameKey) ?? $gameKey;
    }

    public function resolveTemplateOs(Template $template): ?string
    {
        $supported = $template->getSupportedOs();
        if (is_array($supported) && count($supported) === 1) {
            $value = strtolower((string) $supported[0]);
            if (in_array($value, ['linux', 'windows'], true)) {
                return $value;
            }
        }

        $gameKey = $template->getGameKey();
        if (str_ends_with($gameKey, '_windows')) {
            return 'windows';
        }
        if (str_ends_with($gameKey, '_linux')) {
            return 'linux';
        }

        return null;
    }

    private function normalizeCommand(string $command): string
    {
        $normalized = strtolower($command);
        $normalized = str_replace(['\\', '"', "'"], ['', '', ''], $normalized);
        $normalized = preg_replace('/\.exe\b/', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\.sh\b/', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
        $normalized = trim($normalized);

        return $normalized;
    }
}
