<?php

declare(strict_types=1);

namespace App\Domain\Event;

interface ResourceEventSource
{
    public function getResourceType(): string;

    public function getResourceId(): string;
}
