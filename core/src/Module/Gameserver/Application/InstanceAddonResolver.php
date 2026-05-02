<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

use App\Module\Core\Domain\Entity\GamePlugin;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Enum\JobStatus;
use App\Repository\GamePluginRepository;
use App\Repository\JobRepository;

final class InstanceAddonResolver
{
    public function __construct(
        private readonly GamePluginRepository $gamePluginRepository,
        private readonly JobRepository $jobRepository,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function resolve(Instance $instance): array
    {
        $plugins = $this->gamePluginRepository->findByTemplateGameKey($instance->getTemplate());
        $installedVersions = $this->resolveInstalledVersions($instance);
        $addonJobStates = $this->resolveAddonJobStates($instance);

        return array_map(function (GamePlugin $plugin) use ($instance, $installedVersions, $addonJobStates): array {
            $pluginName = strtolower(trim($plugin->getName()));
            $installedVersion = $installedVersions[$pluginName] ?? null;
            $installedVersion = is_string($installedVersion) && trim($installedVersion) !== '' ? trim($installedVersion) : null;

            $compatibility = $this->resolveCompatibility($instance);
            $jobStatus = $addonJobStates[(int) ($plugin->getId() ?? 0)] ?? null;

            $installed = $installedVersion !== null;
            if ($jobStatus === 'removed') {
                $installed = false;
                $installedVersion = null;
            } elseif ($jobStatus === 'installed' && $installedVersion === null) {
                $installed = true;
                $installedVersion = $plugin->getVersion();
            }

            return [
                'id' => $plugin->getId(),
                'key' => $this->slugify($plugin->getName()),
                'name' => $plugin->getName(),
                'description' => $plugin->getDescription(),
                'version' => $plugin->getVersion(),
                'category' => 'template',
                'tags' => [],
                'requires_restart' => true,
                'compatible' => $compatibility['compatible'],
                'incompatible_reason' => $compatibility['incompatible_reason'],
                'installed' => $installed,
                'installed_version' => $installedVersion,
                'update_available' => $installed && $installedVersion !== $plugin->getVersion(),
                'source_template' => [
                    'id' => $plugin->getTemplate()->getId(),
                    'name' => $plugin->getTemplate()->getDisplayName(),
                ],
            ];
        }, $plugins);
    }

    public function findAddonForInstance(Instance $instance, int $addonId): ?GamePlugin
    {
        $plugin = $this->gamePluginRepository->find($addonId);
        if (!$plugin instanceof GamePlugin) {
            return null;
        }

        $pluginTemplate = $plugin->getTemplate();
        $instanceTemplate = $instance->getTemplate();

        if ($pluginTemplate->getId() !== null && $instanceTemplate->getId() !== null && $pluginTemplate->getId() === $instanceTemplate->getId()) {
            return $plugin;
        }

        if ($this->normalizeGameKey($pluginTemplate->getGameKey()) !== $this->normalizeGameKey($instanceTemplate->getGameKey())) {
            return null;
        }

        return $plugin;
    }

    /**
     * @return array<string, string>
     */
    private function resolveInstalledVersions(Instance $instance): array
    {
        $installedVersions = [];
        $installedRaw = $instance->getConfigOverrides()['addons'] ?? [];
        if (!is_array($installedRaw)) {
            return $installedVersions;
        }

        foreach ($installedRaw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = strtolower(trim((string) ($entry['name'] ?? '')));
            if ($name === '') {
                continue;
            }
            $installedVersions[$name] = trim((string) ($entry['version'] ?? ''));
        }

        return $installedVersions;
    }

    /**
     * @return array{compatible: bool, incompatible_reason: ?string}
     */
    private function resolveCompatibility(Instance $instance): array
    {
        $supportedOs = array_values(array_filter(array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            $instance->getTemplate()->getSupportedOs(),
        ), static fn (string $value): bool => $value !== ''));

        if ($supportedOs === []) {
            return ['compatible' => true, 'incompatible_reason' => null];
        }

        $nodeMetadata = $instance->getNode()->getMetadata() ?? [];
        $heartbeatStats = $instance->getNode()->getLastHeartbeatStats() ?? [];
        $nodeOs = strtolower(trim((string) ($nodeMetadata['os'] ?? $heartbeatStats['os'] ?? '')));

        if ($nodeOs !== '' && !in_array($nodeOs, $supportedOs, true)) {
            return [
                'compatible' => false,
                'incompatible_reason' => sprintf('Incompatible OS: %s. Supported: %s', $nodeOs, implode(', ', $supportedOs)),
            ];
        }

        return ['compatible' => true, 'incompatible_reason' => null];
    }

    /**
     * @return array<int, string>
     */
    private function resolveAddonJobStates(Instance $instance): array
    {
        $instanceId = $instance->getId() ?? 0;
        if ($instanceId <= 0) {
            return [];
        }

        $statesByPluginId = [];
        $latestByPluginId = [];
        $typeToState = [
            'instance.addon.install' => 'installed',
            'instance.addon.update' => 'installed',
            'instance.addon.remove' => 'removed',
        ];

        foreach ($typeToState as $type => $state) {
            foreach ($this->jobRepository->findLatestByType($type, 200) as $job) {
                $payload = $job->getPayload();
                if ((int) ($payload['instance_id'] ?? 0) !== $instanceId) {
                    continue;
                }

                $pluginId = (int) ($payload['plugin_id'] ?? 0);
                if ($pluginId <= 0 || $job->getStatus() !== JobStatus::Succeeded) {
                    continue;
                }

                $currentLatest = $latestByPluginId[$pluginId] ?? null;
                if ($currentLatest !== null && $currentLatest >= $job->getCreatedAt()) {
                    continue;
                }

                $latestByPluginId[$pluginId] = $job->getCreatedAt();
                $statesByPluginId[$pluginId] = $state;
            }
        }

        return $statesByPluginId;
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';

        return trim($value, '-');
    }

    private function normalizeGameKey(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
