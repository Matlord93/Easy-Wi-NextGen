<?php

declare(strict_types=1);

namespace App\Module\HostingPanel\Application\Adapter;

final readonly class PanelActionResult
{
    public function __construct(
        public bool $success,
        public array $data = [],
        public ?PanelAdapterError $error = null,
    ) {
    }

    public static function ok(array $data = []): self
    {
        return new self(success: true, data: $data);
    }

    public static function failed(PanelAdapterError $error): self
    {
        return new self(success: false, error: $error);
    }
}
