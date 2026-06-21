<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application\Dto;

final readonly class PluginManifest
{
    /**
     * @param string[] $permissions
     * @param string[] $supportedPlatforms
     * @param array<string, mixed> $configSchema
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
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'identifier' => $this->identifier,
            'name' => $this->name,
            'version' => $this->version,
            'author' => $this->author,
            'description' => $this->description,
            'permissions' => $this->permissions,
            'supported_platforms' => $this->supportedPlatforms,
            'config_schema' => $this->configSchema,
            'panel_extensions' => $this->panelExtensions,
        ];
    }
}
