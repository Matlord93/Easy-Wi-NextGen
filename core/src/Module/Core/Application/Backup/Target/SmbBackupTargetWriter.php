<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup\Target;

use App\Module\Core\Application\Backup\BackupStorageTarget;

/**
 * Uploads backup archives to an SMB/CIFS share via the smbclient CLI.
 *
 * Required target config keys:
 *   host   – SMB server hostname or IP
 *   share  – share name (without leading //)
 *   path   – (optional) sub-directory inside the share
 *
 * Required target secrets keys:
 *   username – SMB username (may include domain as DOMAIN\\user)
 *   password – SMB password
 */
final class SmbBackupTargetWriter implements BackupTargetWriterInterface
{
    public function supports(BackupStorageTarget $target): bool
    {
        return $target->type() === 'smb';
    }

    public function write(BackupStorageTarget $target, string $archiveName, string $sourceFile): string
    {
        $host     = (string) ($target->config()['host'] ?? '');
        $share    = (string) ($target->config()['share'] ?? '');
        $path     = trim((string) ($target->config()['path'] ?? ''), '/\\');
        $username = (string) ($target->secrets()['username'] ?? '');
        $password = (string) ($target->secrets()['password'] ?? '');

        if ($host === '' || $share === '') {
            throw new \InvalidArgumentException('SMB backup target requires host and share.');
        }

        // Validate that smbclient is available.
        $whichOutput = shell_exec('which smbclient 2>/dev/null');
        if (empty($whichOutput)) {
            throw new \RuntimeException('smbclient binary not found. Install the samba-client package.');
        }

        // Build the UNC path: //host/share
        $uncPath = sprintf('//%s/%s', $host, $share);

        // Build the remote directory path inside the share.
        $remoteDir = $path !== '' ? str_replace('/', '\\', $path) : '\\';

        // Use a temp file for the password to avoid it appearing in process list.
        $authFile  = tempnam(sys_get_temp_dir(), 'smb_auth_');
        if ($authFile === false) {
            throw new \RuntimeException('Failed to create smbclient auth temp file.');
        }

        try {
            $authContent = sprintf("username = %s\npassword = %s\n", $username, $password);
            if (file_put_contents($authFile, $authContent) === false) {
                throw new \RuntimeException('Failed to write smbclient auth file.');
            }
            chmod($authFile, 0600);

            $uploadCommand = $path !== ''
                ? sprintf('mkdir "%s"; cd "%s"; put "%s" "%s"', $remoteDir, $remoteDir, $sourceFile, $archiveName)
                : sprintf('put "%s" "%s"', $sourceFile, $archiveName);

            $cmd = sprintf(
                'smbclient %s -A %s -c %s 2>&1',
                escapeshellarg($uncPath),
                escapeshellarg($authFile),
                escapeshellarg($uploadCommand),
            );

            $output = [];
            $exitCode = 0;
            exec($cmd, $output, $exitCode);

            if ($exitCode !== 0) {
                throw new \RuntimeException(sprintf(
                    'smbclient upload failed (exit %d): %s',
                    $exitCode,
                    implode(' ', $output),
                ));
            }
        } finally {
            @unlink($authFile);
        }

        $remotePath = $path !== '' ? $path.'/'.$archiveName : $archiveName;

        return sprintf('smb://%s/%s/%s', $host, $share, $remotePath);
    }
}
