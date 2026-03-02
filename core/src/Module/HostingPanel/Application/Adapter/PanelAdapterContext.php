<?php

declare(strict_types=1);

namespace App\Module\HostingPanel\Application\Adapter;

final readonly class PanelAdapterContext
{
    public function __construct(
        public string $panel,
        public string $version,
        public string $nodeId,
        public string $correlationId,
    ) {
    }
}
