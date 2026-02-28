<?php

declare(strict_types=1);

namespace App\Module\HostingPanel\Application\Job;

interface PanelJobTypeInterface
{
    public function type(): string;

    /** @return array<string, mixed> */
    public function payload(): array;
}
