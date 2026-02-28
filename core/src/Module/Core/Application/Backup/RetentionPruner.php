<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup;

final class RetentionPruner
{
    /**
     * @param list<BackupRun> $runs
     * @return list<BackupRun>
     */
    public function prune(array $runs, RetentionPolicy $policy, \DateTimeImmutable $now): array
    {
        usort($runs, static fn (BackupRun $a, BackupRun $b): int => $b->createdAt() <=> $a->createdAt());

        $delete = [];
        $keepCount = $policy->keepCount();
        $keepDays = $policy->keepDays();
        $cutoff = $keepDays > 0 ? $now->modify(sprintf('-%d days', $keepDays)) : null;

        foreach ($runs as $index => $run) {
            $oldByCount = $keepCount > 0 && $index >= $keepCount;
            $oldByAge = $cutoff !== null && $run->createdAt() < $cutoff;

            if ($oldByCount || $oldByAge) {
                $delete[] = $run;
            }
        }

        return $delete;
    }

    /**
     * @param list<BackupRun> $runs
     * @return array{pruned: list<BackupRun>, deleted: list<string>, skipped: list<string>}
     */
    public function pruneWithAudit(array $runs, RetentionPolicy $policy, \DateTimeImmutable $now, ?string $allowedLocalPrefix = null): array
    {
        $pruned = $this->prune($runs, $policy, $now);
        $deleted = [];
        $skipped = [];

        foreach ($pruned as $run) {
            $path = $run->archivePath();
            if (!$this->isSafeDeletionPath($path, $allowedLocalPrefix)) {
                $skipped[] = sprintf('%s (unsafe-path)', $path);
                continue;
            }

            if (is_file($path)) {
                if (@unlink($path)) {
                    $deleted[] = $path;
                } else {
                    $skipped[] = sprintf('%s (delete-failed)', $path);
                }
                continue;
            }

            $skipped[] = sprintf('%s (not-found)', $path);
        }

        return ['pruned' => $pruned, 'deleted' => $deleted, 'skipped' => $skipped];
    }

    private function isSafeDeletionPath(string $path, ?string $allowedLocalPrefix): bool
    {
        if ($path === '' || str_contains($path, '://')) {
            return false;
        }

        $realPath = realpath($path);
        if ($realPath === false) {
            return false;
        }

        if ($allowedLocalPrefix === null || trim($allowedLocalPrefix) === '') {
            return true;
        }

        $realPrefix = realpath($allowedLocalPrefix);
        if ($realPrefix === false) {
            return false;
        }

        return str_starts_with($realPath, rtrim($realPrefix, '/').'/') || $realPath === rtrim($realPrefix, '/');
    }
}
