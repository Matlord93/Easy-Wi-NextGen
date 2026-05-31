<?php

declare(strict_types=1);

namespace App\Module\Core\Update;

final class UpdateResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly ?string $error,
        public readonly ?string $logPath,
        public readonly ?string $installedVersion,
        public readonly ?string $latestVersion,
        // When pending=true the step is not finished yet; the tick processor
        // retries the same step on the next browser poll instead of advancing.
        public readonly bool $pending = false,
        // ID of the agent job that must complete before the update is done.
        public readonly ?string $reloadJobId = null,
    ) {
    }
}
