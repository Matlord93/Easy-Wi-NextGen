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
        public readonly ?string $deltaFrom = null,
        public readonly ?string $deltaAssetUrl = null,
        public readonly ?string $deltaSha256 = null,
        /** @var string[] */
        public readonly array $deltaDeletes = [],
    ) {
    }
}
