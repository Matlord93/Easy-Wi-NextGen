<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Event;

interface EventBusInterface
{
    public function publish(DomainEvent $event): void;
}
