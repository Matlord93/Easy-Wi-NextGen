<?php

declare(strict_types=1);

namespace App\Domain\Event;

interface EventBusInterface
{
    public function publish(DomainEvent $event): void;
}
