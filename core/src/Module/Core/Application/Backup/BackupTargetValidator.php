<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup;

use App\Module\Core\Application\Backup\Target\S3BackupEndpoint;
use App\Module\Core\Domain\ValueObject\SshHostFingerprint;

final class BackupTargetValidator
{
    public function validate(BackupStorageTarget $target): void
    {
        match ($target->type()) {
            'local', 'nfs' => $this->validateLocal($target),
            'sftp', 'ssh' => $this->validateSftp($target),
            'smb' => $this->validateSmb($target),
            's3' => $this->validateS3($target),
            'webdav', 'nextcloud' => $this->validateWebdav($target),
            default => throw new \InvalidArgumentException('Unsupported backup target type '.$target->type()),
        };
    }

    private function validateLocal(BackupStorageTarget $target): void
    {
        $configured = 'local' === $target->type()
            ? $target->config()['base_path'] ?? $target->config()['path'] ?? ''
            : $target->config()['path'] ?? $target->config()['base_path'] ?? '';
        $path = trim(self::configText($configured));
        if ('' === $path || '/' === $path || !str_starts_with($path, '/') || str_contains($path, "\0")
            || in_array('..', explode('/', str_replace('\\', '/', $path)), true)) {
            throw new \InvalidArgumentException('Invalid local backup path.');
        }
    }

    private function validateNetwork(BackupStorageTarget $target): void
    {
        $host = trim(self::configText($target->config()['host'] ?? ''));
        if ('' === $host || false !== strpbrk($host, "/\\\r\n\0") || str_starts_with($host, '-')) {
            throw new \InvalidArgumentException('Network backup target requires host.');
        }
    }

    private function validateSftp(BackupStorageTarget $target): void
    {
        $this->validateNetwork($target);
        $this->validateLocal($target);
        SshHostFingerprint::normalize(self::configText($target->config()['host_key_fingerprint'] ?? ''));
        $port = filter_var($target->config()['port'] ?? 22, FILTER_VALIDATE_INT);
        if (!is_int($port) || $port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('SFTP target port must be between 1 and 65535.');
        }
        $username = self::configText($target->secrets()['username'] ?? $target->config()['username'] ?? '');
        $key = self::configText($target->secrets()['private_key_path'] ?? $target->config()['private_key_path'] ?? '');
        if ('' === $username || ('' === $key && '' === self::configText($target->secrets()['password'] ?? ''))) {
            throw new \InvalidArgumentException('SFTP target requires username and password or private_key_path.');
        }
        if ('' !== $key && (!str_starts_with($key, '/etc/easywi/') || str_contains($key, "\0") || in_array('..', explode('/', $key), true))) {
            throw new \InvalidArgumentException('SFTP private keys must be under /etc/easywi.');
        }
    }

    private function validateSmb(BackupStorageTarget $target): void
    {
        $this->validateNetwork($target);
        $share = self::configText($target->config()['share'] ?? '');
        if ('' === $share || false !== strpbrk($share, "/\\\"';\r\n\0")) {
            throw new \InvalidArgumentException('SMB target requires a valid share.');
        }
        $path = str_replace('\\', '/', self::configText($target->config()['path'] ?? ''));
        if (in_array('..', explode('/', $path), true) || false !== strpbrk($path, "\"';\r\n\0")) {
            throw new \InvalidArgumentException('Invalid SMB target path.');
        }
        $username = self::configText($target->secrets()['username'] ?? $target->config()['username'] ?? '');
        $password = self::configText($target->secrets()['password'] ?? '');
        if ('' === $username || false !== strpbrk($username.$password, "\r\n\0")) {
            throw new \InvalidArgumentException('SMB target requires valid credentials.');
        }
        $port = filter_var($target->config()['port'] ?? 445, FILTER_VALIDATE_INT);
        if (!is_int($port) || $port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('SMB target port must be between 1 and 65535.');
        }
    }

    private function validateS3(BackupStorageTarget $target): void
    {
        if (1 !== preg_match('/^[a-z0-9][a-z0-9.-]*$/D', self::configText($target->config()['bucket'] ?? ''))
            || '' === self::configText($target->secrets()['access_key'] ?? '') || '' === self::configText($target->secrets()['secret_key'] ?? '')) {
            throw new \InvalidArgumentException('S3 target requires bucket, access_key and secret_key.');
        }
        $endpoint = self::configText($target->config()['endpoint'] ?? '');
        if ('' !== $endpoint) {
            S3BackupEndpoint::parse($endpoint);
        }
        $region = self::configText($target->config()['region'] ?? '') ?: 'us-east-1';
        if (1 !== preg_match('/^[a-z0-9-]+$/D', $region)) {
            throw new \InvalidArgumentException('Invalid S3 target region.');
        }
        $prefix = self::configText($target->config()['prefix'] ?? '');
        if (in_array('..', explode('/', $prefix), true) || in_array('.', explode('/', $prefix), true) || false !== strpbrk($prefix, "\\\0")) {
            throw new \InvalidArgumentException('Invalid S3 target prefix.');
        }
    }

    private function validateWebdav(BackupStorageTarget $target): void
    {
        $url = trim(self::configText($target->config()['url'] ?? ''));
        UrlSafetyGuard::assertSafeHttpsEndpoint($url);

        $secrets = $target->secrets();
        $hasToken = is_string($secrets['token'] ?? null) && '' !== $secrets['token'];
        $username = $secrets['username'] ?? $target->config()['username'] ?? null;
        $hasUserPass = is_string($username) && '' !== $username
            && is_string($secrets['password'] ?? null) && '' !== $secrets['password'];

        if (!$hasToken && !$hasUserPass) {
            throw new \InvalidArgumentException('WebDAV/Nextcloud target requires token or username/password secret.');
        }
        if ('nextcloud' === $target->type() && !str_contains($url, '/remote.php/dav/files/') && (!is_string($username) || '' === $username)) {
            throw new \InvalidArgumentException('Nextcloud target requires its DAV username.');
        }
    }

    private static function configText(mixed $value): string
    {
        if (null === $value || is_scalar($value)) {
            return (string) $value;
        }

        throw new \InvalidArgumentException('Backup configuration fields must contain scalar values.');
    }
}
