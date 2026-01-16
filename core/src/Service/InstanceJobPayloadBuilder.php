<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Instance;

final class InstanceJobPayloadBuilder
{
    public function __construct(
        private readonly \App\Service\Installer\TemplateInstallResolver $templateInstallResolver,
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
        ];

        if ($instance->getLockedBuildId() !== null) {
            $payload['locked_build_id'] = $instance->getLockedBuildId();
        }
        if ($instance->getLockedVersion() !== null) {
            $payload['locked_version'] = $instance->getLockedVersion();
        }

        return $payload;
    }
}
