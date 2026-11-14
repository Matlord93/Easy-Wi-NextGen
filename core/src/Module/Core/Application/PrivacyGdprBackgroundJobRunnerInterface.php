<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

interface PrivacyGdprBackgroundJobRunnerInterface
{
    public function run(int $exportProcessLimit = 25, int $exportCleanupLimit = 100, ?\DateTimeImmutable $now = null): PrivacyGdprBackgroundJobResult;

    public function processPendingExportsOnly(int $limit = 25): int;

    public function deleteExpiredExportsOnly(int $limit = 100, ?\DateTimeImmutable $now = null): int;
}
