<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup;

final class BackupTargetValidator
{
    public function validate(BackupStorageTarget $target): void
    {
        match ($target->type()) {
            'local' => $this->validateLocal($target),
            'sftp', 'ssh', 'smb' => $this->validateNetwork($target),
            'webdav', 'nextcloud' => $this->validateWebdav($target),
            default => throw new \InvalidArgumentException('Unsupported backup target type '.$target->type()),
        };
    }

    private function validateLocal(BackupStorageTarget $target): void
    {
        $path = trim((string) ($target->config()['path'] ?? ''));
        if ($path === '' || str_contains($path, '..')) {
            throw new \InvalidArgumentException('Invalid local backup path.');
        }
    }

    private function validateNetwork(BackupStorageTarget $target): void
    {
        $host = trim((string) ($target->config()['host'] ?? ''));
        if ($host === '') {
            throw new \InvalidArgumentException('Network backup target requires host.');
        }
    }

    private function validateWebdav(BackupStorageTarget $target): void
    {
        $url = trim((string) ($target->config()['url'] ?? ''));
        UrlSafetyGuard::assertSafeHttpsEndpoint($url);

        $secrets = $target->secrets();
        $hasToken = is_string($secrets['token'] ?? null) && $secrets['token'] !== '';
        $hasUserPass = is_string($secrets['username'] ?? null) && $secrets['username'] !== ''
            && is_string($secrets['password'] ?? null) && $secrets['password'] !== '';

        if (!$hasToken && !$hasUserPass) {
            throw new \InvalidArgumentException('WebDAV/Nextcloud target requires token or username/password secret.');
        }
    }
}
