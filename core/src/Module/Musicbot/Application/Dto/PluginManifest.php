<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application\Dto;

final readonly class PluginManifest
{
    /**
     * @param string[] $permissions
     * @param string[] $supportedPlatforms
     * @param string[] $requiredFeatures
     * @param string[] $requiredPermissions
     * @param array<string, mixed> $settingsSchema
     * @param string[] $events
     * @param string[] $actions
     * @param array<string, mixed> $panelExtensions
     */
    public function __construct(
        public string $identifier,
        public string $name,
        public string $version,
        public string $author = '',
        public string $description = '',
        public array $permissions = [],
        public array $supportedPlatforms = [],
        public array $configSchema = [],
        public array $panelExtensions = [],
        public array $requiredFeatures = [],
        public array $requiredPermissions = [],
        public array $settingsSchema = [],
        public array $events = [],
        public array $actions = [],
        public bool $enabledByDefault = false,
        public bool $firstParty = true,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'plugin_id' => $this->identifier,
            'identifier' => $this->identifier,
            'name' => $this->name,
            'version' => $this->version,
            'author' => $this->author,
            'description' => $this->description,
            'permissions' => $this->permissions,
            'supported_platforms' => $this->supportedPlatforms,
            'required_features' => $this->requiredFeatures,
            'required_permissions' => $this->requiredPermissions,
            'settings_schema' => $this->settingsSchema,
            'config_schema' => $this->configSchema,
            'events' => $this->events,
            'actions' => $this->actions,
            'enabled_by_default' => $this->enabledByDefault,
            'first_party' => $this->firstParty,
            'panel_extensions' => $this->panelExtensions,
        ];
    }
}
