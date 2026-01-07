<?php

declare(strict_types=1);

namespace App\Domain\Event;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class SymfonyEventBus implements EventBusInterface
{
    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    public function publish(DomainEvent $event): void
    {
        $this->eventDispatcher->dispatch($event, $event->getName());
    }
}
