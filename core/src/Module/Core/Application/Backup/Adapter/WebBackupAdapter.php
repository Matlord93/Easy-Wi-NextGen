<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup\Adapter;

final class WebBackupAdapter implements BackupModuleAdapterInterface
{
    public function module(): string
    {
        return 'web';
    }

    public function snapshot(string $resourceId): BackupSnapshot
    {
        return new BackupSnapshot($resourceId, 'webspace-backup.tar');
    }

    public function restore(string $resourceId, string $archivePath, bool $dryRun = false): RestoreReport
    {
        if (!is_file($archivePath)) {
            return new RestoreReport($dryRun, false, 'Backup archive not found.');
        }

        try {
            $entries = $this->validatedEntries($archivePath);
            $archive = new \PharData($archivePath);
        } catch (\Throwable $e) {
            return new RestoreReport($dryRun, false, 'Backup archive validation failed: '.$e->getMessage());
        }

        if ($dryRun) {
            return new RestoreReport(true, true, sprintf('Dry-run restore validated archive with %d entries.', count($entries)));
        }

        $targetDir = rtrim($resourceId, '/');
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            return new RestoreReport(false, false, 'Unable to create restore directory.');
        }

        if (is_link($targetDir)) {
            return new RestoreReport(false, false, 'Restore directory cannot be a symlink.');
        }

        try {
            $this->assertNoSymlinkTargets($targetDir, $entries);
            $archive->extractTo($targetDir, null, true);
        } catch (\Throwable $e) {
            return new RestoreReport(false, false, 'Restore extraction failed: '.$e->getMessage());
        }

        return new RestoreReport(false, true, 'Restore completed.');
    }

    /**
     * @return list<string>
     */
    private function validatedEntries(string $archivePath): array
    {
        $namesOutput = shell_exec(sprintf('tar -tf %s 2>/dev/null', escapeshellarg($archivePath)));
        if (!is_string($namesOutput) || trim($namesOutput) === '') {
            throw new \InvalidArgumentException('Unable to inspect archive entries.');
        }

        $entries = [];
        foreach (preg_split('/\R/', trim($namesOutput)) as $entry) {
            if (!is_string($entry) || trim($entry) === '') {
                continue;
            }

            $normalized = str_replace('\\', '/', trim($entry));
            $this->assertSafeArchivePath($normalized);
            $entries[] = $normalized;
        }

        $verboseOutput = shell_exec(sprintf('tar -tvf %s 2>/dev/null', escapeshellarg($archivePath)));
        if (is_string($verboseOutput) && $verboseOutput !== '') {
            foreach (preg_split('/\R/', trim($verboseOutput)) as $line) {
                if (!is_string($line) || $line === '' || $line[0] !== 'l') {
                    continue;
                }

                throw new \InvalidArgumentException('Symlink entries are not allowed in backup archive.');
            }
        }

        return $entries;
    }

    /**
     * @param list<string> $entries
     */
    private function assertNoSymlinkTargets(string $targetDir, array $entries): void
    {
        foreach ($entries as $entry) {
            $segments = explode('/', $entry);
            array_pop($segments);
            $current = rtrim($targetDir, '/');
            foreach ($segments as $segment) {
                if ($segment === '' || $segment === '.') {
                    continue;
                }

                $current .= '/'.$segment;
                if (is_link($current)) {
                    throw new \InvalidArgumentException(sprintf('Restore target contains symlink path segment (%s).', $entry));
                }
            }
        }
    }

    private function assertSafeArchivePath(string $path): void
    {
        if ($path === '' || str_starts_with($path, '/')) {
            throw new \InvalidArgumentException(sprintf('Archive contains unsafe absolute path (%s).', $path));
        }

        if ((bool) preg_match('~^[A-Za-z]:[\\/]~', $path)) {
            throw new \InvalidArgumentException(sprintf('Archive contains unsafe absolute path (%s).', $path));
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                throw new \InvalidArgumentException(sprintf('Archive contains traversal entry (%s).', $path));
            }
        }
    }
}
