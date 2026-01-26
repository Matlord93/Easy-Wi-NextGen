<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Ports\Infrastructure\Repository\PortBlockRepository;

final class InstanceJobPayloadBuilder
{
    public function __construct(
        private readonly TemplateInstallResolver $templateInstallResolver,
        private readonly PortBlockRepository $portBlockRepository,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function buildSniperInstallPayload(Instance $instance): array
    {
        return $this->buildBasePayload($instance);
    }

    /**
     * @return array<string, string>
     */
    public function buildSniperUpdatePayload(Instance $instance, ?string $targetBuildId = null, ?string $targetVersion = null): array
    {
        $payload = $this->buildBasePayload($instance);

        if ($targetBuildId !== null) {
            $payload['target_build_id'] = $targetBuildId;
        }
        if ($targetVersion !== null) {
            $payload['target_version'] = $targetVersion;
        }

        return $payload;
    }

    /**
     * @return array<string, string>
     */
    private function buildBasePayload(Instance $instance): array
    {
        $template = $instance->getTemplate();
        $payload = [
            'instance_id' => (string) ($instance->getId() ?? ''),
            'customer_id' => (string) $instance->getCustomer()->getId(),
            'template_id' => (string) $template->getId(),
            'game_key' => $template->getGameKey(),
            'display_name' => $template->getDisplayName(),
            'steam_app_id' => $template->getSteamAppId() !== null ? (string) $template->getSteamAppId() : '',
            'sniper_profile' => $template->getSniperProfile() ?? '',
            'node_id' => $instance->getNode()->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'install_command' => $this->templateInstallResolver->resolveInstallCommand($instance),
            'update_command' => $this->templateInstallResolver->resolveUpdateCommand($instance),
            'start_params' => $template->getStartParams(),
            'required_ports' => implode(',', $template->getRequiredPortLabels()),
            'env_vars' => $this->buildEnvVars($instance),
            'secrets' => $this->buildSecretPlaceholders($instance),
        ];

        $payload = array_merge($payload, $this->buildPortPayload($instance));

        if ($instance->getLockedBuildId() !== null) {
            $payload['locked_build_id'] = $instance->getLockedBuildId();
        }
        if ($instance->getLockedVersion() !== null) {
            $payload['locked_version'] = $instance->getLockedVersion();
        }

        return $payload;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function buildPortPayload(Instance $instance): array
    {
        $portBlock = $this->portBlockRepository->findByInstance($instance);
        if ($portBlock === null) {
            return [
                'ports' => [],
                'port_reservations' => [],
            ];
        }

        $ports = $portBlock->getPorts();
        $reservations = [];
        $requiredPorts = $instance->getTemplate()->getRequiredPorts();
        foreach ($requiredPorts as $index => $definition) {
            if (!isset($ports[$index])) {
                continue;
            }

            $reservations[] = [
                'role' => (string) ($definition['name'] ?? 'port'),
                'protocol' => (string) ($definition['protocol'] ?? 'udp'),
                'port' => $ports[$index],
            ];
        }

        return [
            'ports' => $ports,
            'port_reservations' => $reservations,
        ];
    }

    /**
     * @return array<int, array{key: string, value: string}>
     */
    private function buildEnvVars(Instance $instance): array
    {
        $vars = [];
        $envVars = $instance->getTemplate()->getEnvVars();
        foreach ($envVars as $entry) {
            $key = trim((string) ($entry['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $vars[$key] = (string) ($entry['value'] ?? '');
        }

        $setupVarKeys = [];
        foreach ($instance->getSetupVars() as $key => $value) {
            $normalizedKey = trim((string) $key);
            if ($normalizedKey === '') {
                continue;
            }
            $setupVarKeys[$normalizedKey] = true;
            $vars[$normalizedKey] = (string) $value;
        }

        if ($instance->getSteamAccount() !== null && $instance->getSteamAccount() !== '') {
            $vars['STEAM_ACCOUNT'] = $instance->getSteamAccount();
        }
        if ($instance->getGslKey() !== null && $instance->getGslKey() !== '') {
            $vars['STEAM_GSLT'] = $instance->getGslKey();
        }
        if ($instance->getServerName() !== null && $instance->getServerName() !== '') {
            $vars['SERVER_NAME'] = $instance->getServerName();
        }

        if (!isset($setupVarKeys['SERVER_MEMORY']) && $instance->getRamLimit() > 0) {
            $vars['SERVER_MEMORY'] = (string) $instance->getRamLimit();
        }

        foreach (['SERVER_PASSWORD', 'RCON_PASSWORD', 'ADMIN_PASSWORD', 'SERVER_ADMIN_PASSWORD'] as $passwordKey) {
            if (!isset($setupVarKeys[$passwordKey]) && isset($vars[$passwordKey]) && strtolower($vars[$passwordKey]) === 'change-me') {
                unset($vars[$passwordKey]);
            }
        }

        $normalized = [];
        foreach ($vars as $key => $value) {
            $normalized[] = [
                'key' => $key,
                'value' => $value,
            ];
        }

        return $normalized;
    }

    /**
     * @return array<int, array{key: string, placeholder: string}>
     */
    private function buildSecretPlaceholders(Instance $instance): array
    {
        $placeholders = [];
        foreach ($instance->getSetupSecrets() as $key => $_payload) {
            $normalizedKey = trim((string) $key);
            if ($normalizedKey === '') {
                continue;
            }
            $placeholders[] = [
                'key' => $normalizedKey,
                'placeholder' => sprintf('{{secret:%s}}', $normalizedKey),
            ];
        }

        return $placeholders;
    }
}
