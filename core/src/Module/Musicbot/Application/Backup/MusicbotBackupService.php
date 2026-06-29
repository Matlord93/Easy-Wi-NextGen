<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application\Backup;

use App\Module\Musicbot\Application\MusicbotSecretConfigServiceInterface;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;

final class MusicbotBackupService
{
    public function __construct(
        private readonly MusicbotSecretConfigServiceInterface $secretConfigService,
        private readonly MusicbotBackupDataProvider $dataProvider,
        private readonly string $appVersion = '1.0.0',
    ) {
    }

    public function createBackup(MusicbotInstance $instance, MusicbotBackupOptions $options): MusicbotBackupManifest
    {
        $data = [];

        $data['instance'] = $this->exportInstance($instance, $options);
        $data['connections'] = $this->exportConnections($instance, $options);
        $data['playlists'] = $this->exportPlaylists($instance);
        $data['radio_stations'] = $this->exportRadioStations($instance);
        $data['autodj'] = $this->exportAutoDj($instance);
        $data['plugins'] = $this->exportPlugins($instance);

        if ($options->includeQueue) {
            $data['queue'] = $this->exportQueue($instance);
        }

        if ($options->includeTracks || $options->type === MusicbotBackupType::Full) {
            $data['tracks'] = $this->exportTracks($instance);
        }

        if ($options->includeYoutubeHistory) {
            $data['youtube_history'] = [];
        }

        if ($options->includePluginLogs) {
            $data['plugin_logs'] = $this->exportPluginLogs($instance);
        }

        if ($options->includeCustomerLimits) {
            $data['customer_limits'] = $this->exportCustomerLimits($instance);
        }

        $manifest = new MusicbotBackupManifest(
            schemaVersion: MusicbotBackupManifest::SCHEMA_VERSION,
            backupType: $options->type->value,
            instanceId: (string) $instance->getId(),
            customerId: (string) $instance->getCustomer()->getId(),
            serviceName: $instance->getServiceName(),
            appVersion: $this->appVersion,
            createdAt: new \DateTimeImmutable(),
            data: $data,
        );

        $payload = $manifest->toArray();
        unset($payload['checksum']);
        $checksum = hash('sha256', json_encode($payload) ?: '');

        return new MusicbotBackupManifest(
            schemaVersion: $manifest->schemaVersion,
            backupType: $manifest->backupType,
            instanceId: $manifest->instanceId,
            customerId: $manifest->customerId,
            serviceName: $manifest->serviceName,
            appVersion: $manifest->appVersion,
            createdAt: $manifest->createdAt,
            data: $data,
            checksum: $checksum,
        );
    }

    public function serializeToJson(MusicbotBackupManifest $manifest): string
    {
        $json = json_encode($manifest->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Failed to serialize backup manifest to JSON.');
        }

        return $json;
    }

    public function deserializeFromJson(string $json): MusicbotBackupManifest
    {
        $raw = json_decode($json, true);
        if (!is_array($raw)) {
            throw new \InvalidArgumentException('Invalid backup JSON: could not decode.');
        }

        return MusicbotBackupManifest::fromArray($raw);
    }

    public function validateManifest(MusicbotBackupManifest $manifest): void
    {
        if ($manifest->schemaVersion !== MusicbotBackupManifest::SCHEMA_VERSION) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported backup schema version "%s". Expected "%s".',
                $manifest->schemaVersion,
                MusicbotBackupManifest::SCHEMA_VERSION,
            ));
        }

        if ($manifest->instanceId === '') {
            throw new \InvalidArgumentException('Backup manifest is missing instance_id.');
        }

        if ($manifest->customerId === '') {
            throw new \InvalidArgumentException('Backup manifest is missing customer_id.');
        }
    }

    public function verifyChecksum(MusicbotBackupManifest $manifest): bool
    {
        $payload = $manifest->toArray();
        unset($payload['checksum']);
        $expected = hash('sha256', json_encode($payload) ?: '');

        return hash_equals($expected, $manifest->checksum);
    }

    /** @return array<string, mixed> */
    private function exportInstance(MusicbotInstance $instance, MusicbotBackupOptions $options): array
    {
        $config = $instance->getInstanceConfig();

        if ($options->maskSecrets || $options->type === MusicbotBackupType::Customer) {
            unset($config['runtime_control_token'], $config['internal_api_key']);
        }

        $data = [
            'name' => $instance->getName(),
            'status' => $instance->getStatus()->value,
            'service_name' => $instance->getServiceName(),
            'cpu_limit' => $instance->getCpuLimit(),
            'ram_limit' => $instance->getRamLimit(),
            'disk_limit' => $instance->getDiskLimit(),
            'instance_config' => $config,
        ];

        if ($options->type === MusicbotBackupType::Admin && !$options->maskSecrets) {
            $data['install_path'] = $instance->getInstallPath();
            $data['node_id'] = $instance->getNode()->getId();
            $data['runtime_payload'] = $instance->getRuntimePayload();
        }

        return $data;
    }

    /** @return list<array<string, mixed>> */
    private function exportConnections(MusicbotInstance $instance, MusicbotBackupOptions $options): array
    {
        $connections = $this->dataProvider->getConnections($instance);
        $result = [];

        foreach ($connections as $connection) {
            $secretConfig = $connection->getSecretConfig();

            if ($options->maskSecrets || $options->type === MusicbotBackupType::Customer) {
                $secretConfig = $this->secretConfigService->mask($secretConfig);
            }

            $result[] = [
                'platform' => $connection->getPlatform()->value,
                'enabled' => $connection->isEnabled(),
                'connection_config' => $connection->getConnectionConfig(),
                'secret_config' => $secretConfig,
            ];
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function exportPlaylists(MusicbotInstance $instance): array
    {
        $playlists = $this->dataProvider->getPlaylists($instance);
        $result = [];

        foreach ($playlists as $playlist) {
            $itemData = $this->dataProvider->getPlaylistItems($playlist);
            $items = [];

            foreach ($itemData as $item) {
                $items[] = [
                    'position' => $item->getPosition(),
                    'track_title' => $item->getTrack()->getTitle(),
                    'track_artist' => $item->getTrack()->getArtist(),
                    'track_sha256' => $item->getTrack()->getSha256(),
                    'metadata' => $item->getMetadata(),
                ];
            }

            $result[] = [
                'name' => $playlist->getName(),
                'visibility' => $playlist->getVisibility()->value,
                'description' => $playlist->getDescription(),
                'sort_order' => $playlist->getSortOrder(),
                'items' => $items,
            ];
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function exportRadioStations(MusicbotInstance $instance): array
    {
        $stations = $this->dataProvider->getRadioStations($instance);
        $result = [];

        foreach ($stations as $station) {
            $result[] = [
                'name' => $station->getName(),
                'stream_url' => $station->getStreamUrl(),
                'genre' => $station->getGenre(),
                'description' => $station->getDescription(),
                'homepage' => $station->getHomepage(),
                'logo_url' => $station->getLogoUrl(),
                'country' => $station->getCountry(),
                'language' => $station->getLanguage(),
                'is_active' => $station->isActive(),
            ];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function exportAutoDj(MusicbotInstance $instance): array
    {
        $settings = $this->dataProvider->getAutoDjSettings($instance);
        if ($settings === null) {
            return [];
        }

        return [
            'enabled' => $settings->isEnabled(),
            'mode' => $settings->getMode()->value,
            'avoid_repeats' => $settings->isAvoidRepeats(),
            'min_queue_size' => $settings->getMinQueueSize(),
            'shuffle' => $settings->isShuffle(),
            'repeat' => $settings->isRepeat(),
            'idle_seconds' => $settings->getIdleSeconds(),
            'volume_override' => $settings->getVolumeOverride(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function exportPlugins(MusicbotInstance $instance): array
    {
        $plugins = $this->dataProvider->getPlugins($instance);
        $result = [];

        foreach ($plugins as $plugin) {
            $result[] = [
                'identifier' => $plugin->getIdentifier(),
                'name' => $plugin->getName(),
                'version' => $plugin->getVersion(),
                'enabled' => $plugin->isEnabled(),
                'config' => $plugin->getConfig(),
                'permissions' => $plugin->getPermissions(),
            ];
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function exportQueue(MusicbotInstance $instance): array
    {
        $items = $this->dataProvider->getQueueItems($instance);
        $result = [];

        foreach ($items as $item) {
            $result[] = [
                'position' => $item->getPosition(),
                'track_title' => $item->getTrack()->getTitle(),
                'track_sha256' => $item->getTrack()->getSha256(),
                'status' => $item->getStatus(),
            ];
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function exportTracks(MusicbotInstance $instance): array
    {
        $tracks = $this->dataProvider->getTracks($instance);
        $result = [];

        foreach ($tracks as $track) {
            $result[] = [
                'title' => $track->getTitle(),
                'artist' => $track->getArtist(),
                'duration_seconds' => $track->getDurationSeconds(),
                'source_type' => $track->getSourceType()->value,
                'file_path' => $track->getFilePath(),
                'mime_type' => $track->getMimeType(),
                'sha256' => $track->getSha256(),
                'metadata' => $track->getMetadata(),
            ];
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function exportPluginLogs(MusicbotInstance $instance): array
    {
        $logs = $this->dataProvider->getPluginLogs($instance);
        $result = [];

        foreach ($logs as $log) {
            $result[] = [
                'plugin_id' => $log->getPluginId(),
                'event' => $log->getEvent(),
                'status' => $log->getStatus(),
                'message' => $log->getMessage(),
                'created_at' => $log->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function exportCustomerLimits(MusicbotInstance $instance): array
    {
        $limits = $this->dataProvider->getCustomerLimits($instance);
        if ($limits === null) {
            return [];
        }

        return [
            'max_musicbots' => $limits->getMaxMusicbots(),
            'max_playlists' => $limits->getMaxPlaylists(),
            'max_tracks' => $limits->getMaxTracks(),
            'max_storage_mb' => $limits->getMaxStorageMb(),
            'max_upload_size_mb' => $limits->getMaxUploadSizeMb(),
            'max_plugins' => $limits->getMaxPlugins(),
        ];
    }
}
