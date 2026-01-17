<?php

declare(strict_types=1);

namespace App\Module\Core\Update;

final class UpdateManifest
{
    public function __construct(
        public readonly string $latest,
        public readonly string $assetUrl,
        public readonly ?string $sha256,
        public readonly ?string $notes,
    ) {
    }
}
