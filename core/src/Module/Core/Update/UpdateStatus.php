<?php

declare(strict_types=1);

namespace App\Module\Core\Update;

final class UpdateStatus
{
    public function __construct(
        public readonly ?string $installedVersion,
        public readonly ?string $latestVersion,
        public readonly ?bool $updateAvailable,
        public readonly ?string $notes,
        public readonly ?string $error,
        public readonly ?string $assetUrl,
    ) {
    }
}
