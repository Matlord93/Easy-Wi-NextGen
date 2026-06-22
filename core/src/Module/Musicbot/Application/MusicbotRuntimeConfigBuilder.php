<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Musicbot\Domain\Entity\MusicbotConnection;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Enum\MusicbotPlatform;
use App\Repository\MusicbotConnectionRepository;
use App\Repository\MusicbotPluginRepository;
use App\Repository\MusicbotStreamSettingsRepository;

/**
 * Builds the runtime configuration payload for agent job dispatch.
 *
 * The returned payload is meant exclusively for transit to the agent so it can write
 * config.json on the target node. It MUST NOT be persisted to the database or logged.
 * Callers are responsible for ensuring the payload is only forwarded to the agent
 * and never stored in runtimePayload or AgentJob.payload beyond what the validator
 * strips via MusicbotSecretConfigService::sanitizePayload().
 *
 * DB / Secret Storage is the single Source of Truth. config.json on the node is
 * a derived, transient file generated from this payload.
 */
final class MusicbotRuntimeConfigBuilder
{
    public function __construct(
        private readonly MusicbotConnectionRepository $connectionRepository,
        private readonly MusicbotStreamSettingsRepository $streamSettingsRepository,
        private readonly MusicbotPluginRepository $pluginRepository,
        private readonly MusicbotSecretConfigService $secretConfigService,
    ) {
    }

    /**
     * Build the full runtime config payload for an instance.
     *
     * The array includes plaintext secrets for the agent. Do not persist this array.
     *
     * @return array<string, mixed>
     */
    public function build(MusicbotInstance $instance): array
    {
        $connections = $this->connectionRepository->findBy(
            ['musicbotInstance' => $instance],
            ['id' => 'ASC'],
        );

        return [
            'instance_id' => (string) $instance->getId(),
            'service_name' => $instance->getServiceName(),
            'install_path' => $instance->getInstallPath(),
            'discord' => $this->buildDiscordConfig($connections),
            'teamspeak' => $this->buildTeamspeakConfig($connections),
            'stream' => $this->buildStreamConfig($instance),
            'plugins' => $this->buildPluginConfigs($instance),
            'config_file_permissions' => '0600',
        ];
    }

    /**
     * Returns only non-secret fields safe for logging or API responses.
     *
     * @return array<string, mixed>
     */
    public function buildSanitized(MusicbotInstance $instance): array
    {
        return $this->secretConfigService->sanitizePayload($this->build($instance));
    }

    /** @param MusicbotConnection[] $connections @return array<string, mixed> */
    private function buildDiscordConfig(array $connections): array
    {
        $connection = $this->findConnection($connections, MusicbotPlatform::Discord);

        if ($connection === null || !$connection->isEnabled()) {
            return ['enabled' => false];
        }

        $config = $connection->getConnectionConfig();
        $secrets = $this->secretConfigService->normalizeForRuntime($connection->getSecretConfig());

        return [
            'enabled' => true,
            'application_id' => (string) ($config['application_id'] ?? ''),
            'guild_id' => (string) ($config['guild_id'] ?? ''),
            'voice_channel_id' => (string) ($config['voice_channel_id'] ?? ''),
            'text_channel_id' => (string) ($config['text_channel_id'] ?? ''),
            'command_mode' => (string) ($config['command_mode'] ?? 'placeholder'),
            'slash_commands_enabled' => (bool) ($config['slash_commands_enabled'] ?? false),
            'reconnect_policy' => (string) ($config['reconnect_policy'] ?? 'manual'),
            'bot_token' => $secrets['bot_token'] ?? '',
        ];
    }

    /** @param MusicbotConnection[] $connections @return array<string, mixed> */
    private function buildTeamspeakConfig(array $connections): array
    {
        $connection = $this->findConnection($connections, MusicbotPlatform::Teamspeak);

        if ($connection === null || !$connection->isEnabled()) {
            return ['enabled' => false];
        }

        $config = $connection->getConnectionConfig();
        $secrets = $this->secretConfigService->normalizeForRuntime($connection->getSecretConfig());

        return [
            'enabled' => true,
            'platform' => 'teamspeak',
            'profile' => (string) ($config['profile'] ?? 'ts3'),
            'backend' => (string) ($config['backend'] ?? 'ts3_client_compatible'),
            'backend_type' => (string) ($config['backend_type'] ?? 'placeholder'),
            'backend_path' => (string) ($config['backend_path'] ?? ''),
            'identity_path' => (string) ($config['identity_path'] ?? ''),
            'library_path' => (string) ($config['library_path'] ?? ''),
            'opus_library_path' => (string) ($config['opus_library_path'] ?? ''),
            'binary_path' => (string) ($config['binary_path'] ?? ''),
            'host' => (string) ($config['host'] ?? ''),
            'port' => (int) ($config['port'] ?? 9987),
            'nickname' => (string) ($config['nickname'] ?? ''),
            'channel_id' => (string) ($config['channel_id'] ?? ''),
            'command_prefix' => (string) ($config['command_prefix'] ?? '!'),
            'commands_enabled' => (bool) ($config['commands_enabled'] ?? true),
            'events_enabled' => (bool) ($config['events_enabled'] ?? true),
            'allowed_server_groups' => (array) ($config['allowed_server_groups'] ?? []),
            'dj_server_groups' => (array) ($config['dj_server_groups'] ?? []),
            'admin_server_groups' => (array) ($config['admin_server_groups'] ?? []),
            'server_password' => $secrets['server_password'] ?? '',
            'channel_password' => $secrets['channel_password'] ?? '',
        ];
    }

    /** @return array<string, mixed> */
    private function buildStreamConfig(MusicbotInstance $instance): array
    {
        $settings = $this->streamSettingsRepository->findByInstance($instance);

        if ($settings === null) {
            return ['enabled' => false];
        }

        return [
            'enabled' => $settings->isEnabled(),
            'public_slug' => $settings->getPublicSlug(),
            'access_mode' => $settings->getAccessMode()->value,
            'stream_title' => $settings->getStreamTitle(),
            'bitrate' => $settings->getBitrate(),
            'format' => $settings->getFormat(),
            'has_token' => $settings->getStreamTokenHash() !== null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function buildPluginConfigs(MusicbotInstance $instance): array
    {
        $plugins = $this->pluginRepository->findBy(['instance' => $instance]);
        $result = [];

        foreach ($plugins as $plugin) {
            if (!$plugin->isEnabled()) {
                continue;
            }
            $result[] = [
                'identifier' => $plugin->getIdentifier(),
                'version' => $plugin->getVersion(),
                'config' => $plugin->getConfig(),
            ];
        }

        return $result;
    }

    /** @param MusicbotConnection[] $connections */
    private function findConnection(array $connections, MusicbotPlatform $platform): ?MusicbotConnection
    {
        foreach ($connections as $connection) {
            if ($connection->getPlatform() === $platform) {
                return $connection;
            }
        }

        return null;
    }
}
