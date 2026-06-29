<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Musicbot\Application\Dto\PluginManifest;
use App\Module\Musicbot\Domain\Enum\MusicbotPluginPermission;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class PluginRegistryService
{
    private const IDENTIFIER_PATTERN = '/^[a-z0-9][a-z0-9._-]{2,119}$/';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /** @return PluginManifest[] */
    public function listManifests(): array
    {
        $manifests = $this->builtInManifests();
        foreach ($this->manifestPaths() as $path) {
            try {
                $manifest = $this->loadManifest($path);
            } catch (\InvalidArgumentException) {
                continue;
            }
            $manifests[$manifest->identifier] = $manifest;
        }

        ksort($manifests);

        return array_values($manifests);
    }

    public function findManifest(string $identifier): ?PluginManifest
    {
        $this->assertValidIdentifier($identifier);
        foreach ($this->listManifests() as $manifest) {
            if ($manifest->identifier === $identifier) {
                return $manifest;
            }
        }

        return null;
    }

    public function loadManifest(string $path): PluginManifest
    {
        $realPath = realpath($path);
        if ($realPath === false || !str_ends_with($realPath, DIRECTORY_SEPARATOR.'manifest.json')) {
            throw new \InvalidArgumentException('Invalid plugin manifest path.');
        }
        $allowedRoots = array_filter(array_map('realpath', $this->pluginRoots()));
        $isAllowed = false;
        foreach ($allowedRoots as $root) {
            if (str_starts_with($realPath, $root.DIRECTORY_SEPARATOR)) {
                $isAllowed = true;
                break;
            }
        }
        if (!$isAllowed) {
            throw new \InvalidArgumentException('Plugin manifest path is outside the plugin registry.');
        }

        $data = json_decode((string) file_get_contents($realPath), true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Plugin manifest must be JSON.');
        }

        return $this->manifestFromArray($data);
    }

    /** @param array<string, mixed> $data */
    public function manifestFromArray(array $data): PluginManifest
    {
        $identifier = strtolower(trim((string) ($data['identifier'] ?? '')));
        $this->assertValidIdentifier($identifier);
        $permissions = $this->validateStringList($data['permissions'] ?? $data['required_permissions'] ?? [], MusicbotPluginPermission::values(), 'permission');
        $platforms = $this->validateStringList($data['supported_platforms'] ?? [], ['teamspeak', 'discord', 'both'], 'platform');
        $events = $this->validateStringList($data['events'] ?? $data['plugin_api']['hooks'] ?? [], $this->supportedEvents(), 'event');
        $actions = $this->validateStringList($data['actions'] ?? [], $this->supportedActions(), 'action');
        $settingsSchema = is_array($data['settings_schema'] ?? null) ? $data['settings_schema'] : (is_array($data['config_schema'] ?? null) ? $data['config_schema'] : []);

        return new PluginManifest(
            $identifier,
            trim((string) ($data['name'] ?? $identifier)),
            trim((string) ($data['version'] ?? '0.0.0')),
            trim((string) ($data['author'] ?? '')),
            trim((string) ($data['description'] ?? '')),
            $permissions,
            $platforms,
            is_array($data['config_schema'] ?? null) ? $data['config_schema'] : $settingsSchema,
            is_array($data['panel_extensions'] ?? null) ? $data['panel_extensions'] : [],
            $this->validateStringList($data['required_features'] ?? [], ['playlists', 'webradio', 'youtube', 'uploads', 'autodj', 'plugins'], 'feature'),
            $permissions,
            $settingsSchema,
            $events,
            $actions,
            (bool) ($data['enabled_by_default'] ?? false),
            (bool) ($data['first_party'] ?? true),
        );
    }

    public function assertValidIdentifier(string $identifier): void
    {
        if (!preg_match(self::IDENTIFIER_PATTERN, $identifier) || str_contains($identifier, '..') || str_contains($identifier, '/') || str_contains($identifier, '\\')) {
            throw new \InvalidArgumentException('Invalid plugin identifier.');
        }
    }

    /** @return string[] */
    private function manifestPaths(): array
    {
        $paths = [];
        foreach ($this->pluginRoots() as $root) {
            if (!is_dir($root)) {
                continue;
            }
            foreach (glob($root.'/*/manifest.json') ?: [] as $path) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /** @return string[] */
    private function pluginRoots(): array
    {
        return [
            $this->projectDir.'/musicbot/plugins',
            $this->projectDir.'/var/musicbot/plugins',
        ];
    }

    /** @return array<string, PluginManifest> */
    private function builtInManifests(): array
    {
        $commonEvents = $this->supportedEvents();

        return [
            'easywi.welcome_song' => new PluginManifest(
                'easywi.welcome_song',
                'Welcome Song',
                '1.0.0',
                'Easy-Wi',
                'Plays a configured welcome track, playlist, or radio stream when a user joins the bot channel.',
                [MusicbotPluginPermission::PlaybackControl->value, MusicbotPluginPermission::QueueManage->value, MusicbotPluginPermission::PlaylistManage->value, MusicbotPluginPermission::EventsSubscribe->value],
                ['teamspeak'],
                [],
                [],
                ['playlists', 'uploads'],
                [MusicbotPluginPermission::PlaybackControl->value, MusicbotPluginPermission::QueueManage->value],
                ['type' => 'object', 'properties' => ['enabled' => ['type' => 'boolean'], 'track_id' => ['type' => 'integer'], 'playlist_id' => ['type' => 'integer'], 'radio_url' => ['type' => 'string'], 'only_when_idle' => ['type' => 'boolean'], 'cooldown_seconds' => ['type' => 'integer'], 'max_plays_per_user_per_day' => ['type' => 'integer'], 'allowed_channels' => ['type' => 'array'], 'ignored_server_groups' => ['type' => 'array'], 'volume_override' => ['type' => 'integer'], 'send_chat_message' => ['type' => 'boolean'], 'chat_message_text' => ['type' => 'string'], 'resume_previous_playback' => ['type' => 'boolean']]],
                ['user_joined_channel'],
                ['play_track', 'play_playlist', 'play_radio', 'set_volume', 'send_chat_message'],
                false,
                true,
            ),
            'easywi.idle_playlist' => new PluginManifest(
                'easywi.idle_playlist',
                'Idle Playlist',
                '1.0.0',
                'Easy-Wi',
                'Starts a playlist or Webradio stream after the queue is empty and playback is idle.',
                [MusicbotPluginPermission::PlaybackControl->value, MusicbotPluginPermission::QueueManage->value, MusicbotPluginPermission::PlaylistManage->value, MusicbotPluginPermission::EventsSubscribe->value],
                ['teamspeak', 'discord'],
                [],
                [],
                ['playlists'],
                [MusicbotPluginPermission::PlaybackControl->value, MusicbotPluginPermission::QueueManage->value],
                ['type' => 'object', 'properties' => ['enabled' => ['type' => 'boolean'], 'idle_seconds' => ['type' => 'integer'], 'playlist_id' => ['type' => 'integer'], 'radio_url' => ['type' => 'string'], 'shuffle' => ['type' => 'boolean'], 'repeat' => ['type' => 'boolean'], 'volume' => ['type' => 'integer'], 'allowed_time_window' => ['type' => 'string']]],
                ['queue_empty', 'playback_stopped', 'scheduled_tick'],
                ['play_playlist', 'play_radio', 'set_volume'],
                false,
                true,
            ),
        ];
    }

    /** @return string[] */
    public function supportedEvents(): array
    {
        return ['bot_started', 'bot_stopped', 'runtime_ready', 'user_joined_channel', 'user_left_channel', 'channel_empty', 'channel_not_empty', 'playback_started', 'playback_stopped', 'track_started', 'track_finished', 'queue_empty', 'queue_changed', 'chat_command_received', 'playback_action_requested', 'queue_action_requested', 'error_occurred', 'scheduled_tick'];
    }

    /** @return string[] */
    public function supportedActions(): array
    {
        return ['play_track', 'play_playlist', 'play_radio', 'play_youtube', 'queue_track', 'clear_queue', 'set_volume', 'send_chat_message', 'reconnect', 'trigger_autodj'];
    }

    /** @param mixed $value @param string[] $allowed @return string[] */
    private function validateStringList(mixed $value, array $allowed, string $label): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $item) {
            $item = strtolower(trim((string) $item));
            if ($label === 'event') { $item = str_replace('.', '_', $item); }
            if ($item === '') {
                continue;
            }
            if (!in_array($item, $allowed, true)) {
                throw new \InvalidArgumentException(sprintf('Unsupported plugin %s "%s".', $label, $item));
            }
            $result[] = $item;
        }

        return array_values(array_unique($result));
    }
}
