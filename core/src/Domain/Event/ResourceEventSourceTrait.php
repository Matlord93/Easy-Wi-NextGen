<?php

declare(strict_types=1);

namespace App\Domain\Event;

trait ResourceEventSourceTrait
{
    public function getResourceType(): string
    {
        return (new \ReflectionClass($this))->getShortName();
    }

    public function getResourceId(): string
    {
        return (string) $this->getId();
    }
}
