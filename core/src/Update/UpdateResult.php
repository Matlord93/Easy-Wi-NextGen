<?php

declare(strict_types=1);

namespace App\Update;

final class UpdateResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly ?string $error,
        public readonly ?string $logPath,
        public readonly ?string $installedVersion,
        public readonly ?string $latestVersion,
    ) {
    }
}
