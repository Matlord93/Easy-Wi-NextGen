<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup;

use App\Module\Core\Application\Backup\Target\NfsBackupTargetWriter;

/** Binds an archive to storage coordinates; credential rotation keeps the binding. */
final class BackupTargetIdentity
{
    public static function of(BackupStorageTarget $target): string
    {
        $config = $target->config();
        $keys = match ($target->type()) {
            'webdav', 'nextcloud' => ['url', 'remote_path', 'root_path'],
            's3' => ['endpoint', 'bucket', 'prefix', 'region'],
            'sftp', 'ssh' => ['host', 'port', 'path', 'base_path', 'host_key_fingerprint'],
            'smb' => ['host', 'port', 'share', 'path'],
            default => ['path', 'base_path'],
        };
        $coordinates = ['type' => $target->type()];
        foreach ($keys as $key) {
            $coordinates[$key] = $config[$key] ?? null;
        }
        if (in_array($target->type(), ['webdav', 'nextcloud', 'sftp', 'ssh', 'smb'], true)) {
            $coordinates['username'] = $target->secrets()['username'] ?? $config['username'] ?? null;
        }
        if (in_array($target->type(), ['webdav', 'nextcloud'], true) && isset($target->secrets()['token'])) {
            // A bearer token has no independently identifiable account. Changing it
            // therefore requires a fresh archive binding rather than trusting a new account.
            $coordinates['token_identity'] = hash('sha256', json_encode($target->secrets()['token'], JSON_THROW_ON_ERROR));
        }
        if ('s3' === $target->type()) {
            $coordinates['access_key'] = $target->secrets()['access_key'] ?? null;
        }
        if ('nfs' === $target->type()) {
            $path = $config['path'] ?? $config['base_path'] ?? null;
            if (!is_string($path)) {
                throw new \InvalidArgumentException('NFS requires a configured mount directory.');
            }
            $coordinates['mounted_export'] = NfsBackupTargetWriter::mountIdentity($path);
        }

        return hash('sha256', json_encode($coordinates, JSON_THROW_ON_ERROR));
    }
}
