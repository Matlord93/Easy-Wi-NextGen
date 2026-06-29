<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application\Backup;

use App\Module\Musicbot\Domain\Entity\MusicbotInstance;

final class MusicbotMigrationService
{
    private const RUNTIME_EXCLUDE_KEYS = [
        'control_sock',
        'runtime_socket',
        'tmp_path',
        'log_path',
        'pid_file',
        'xvfb_display',
        'pulseaudio_sink',
        'ts3_state',
    ];

    public function prepareForMigration(
        MusicbotBackupManifest $manifest,
        string $targetNodeId,
        ?string $newServiceName = null,
        ?string $newInstallPath = null,
    ): MusicbotBackupManifest {
        $data = $manifest->data;

        if (isset($data['instance']) && is_array($data['instance'])) {
            $instance = $data['instance'];

            unset(
                $instance['install_path'],
                $instance['node_id'],
                $instance['runtime_payload'],
            );

            if ($newServiceName !== null && $newServiceName !== '') {
                $instance['service_name'] = $newServiceName;
            } else {
                unset($instance['service_name']);
            }

            $config = (array) ($instance['instance_config'] ?? []);
            foreach (self::RUNTIME_EXCLUDE_KEYS as $key) {
                unset($config[$key]);
            }
            $instance['instance_config'] = $config;

            $data['instance'] = $instance;
        }

        foreach (['runtime', 'tmp', 'logs', 'control_sock'] as $excluded) {
            unset($data[$excluded]);
        }

        $data['migration'] = [
            'target_node_id' => $targetNodeId,
            'prepared_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'original_service_name' => $manifest->serviceName,
            'original_instance_id' => $manifest->instanceId,
        ];

        $newServiceName = $newServiceName ?? $this->generateServiceName($manifest->serviceName);

        $migrated = new MusicbotBackupManifest(
            schemaVersion: $manifest->schemaVersion,
            backupType: MusicbotBackupType::Admin->value,
            instanceId: $manifest->instanceId,
            customerId: $manifest->customerId,
            serviceName: $newServiceName,
            appVersion: $manifest->appVersion,
            createdAt: new \DateTimeImmutable(),
            data: $data,
        );

        $payload = $migrated->toArray();
        unset($payload['checksum']);
        $checksum = hash('sha256', json_encode($payload) ?: '');

        return new MusicbotBackupManifest(
            schemaVersion: $migrated->schemaVersion,
            backupType: $migrated->backupType,
            instanceId: $migrated->instanceId,
            customerId: $migrated->customerId,
            serviceName: $migrated->serviceName,
            appVersion: $migrated->appVersion,
            createdAt: $migrated->createdAt,
            data: $migrated->data,
            checksum: $checksum,
        );
    }

    public function computeNewPaths(MusicbotInstance $instance, string $newInstallBase): array
    {
        $serviceName = $instance->getServiceName();

        return [
            'install_path' => rtrim($newInstallBase, '/').'/'.$serviceName,
            'service_name' => $serviceName,
            'data_path' => rtrim($newInstallBase, '/').'/'.$serviceName.'/data',
            'uploads_path' => rtrim($newInstallBase, '/').'/'.$serviceName.'/uploads',
            'playlists_path' => rtrim($newInstallBase, '/').'/'.$serviceName.'/playlists',
            'settings_path' => rtrim($newInstallBase, '/').'/'.$serviceName.'/settings',
        ];
    }

    private function generateServiceName(string $original): string
    {
        $base = preg_replace('/-[a-f0-9]{8}$/', '', $original) ?? $original;

        return $base.'-'.substr(bin2hex(random_bytes(4)), 0, 8);
    }
}
