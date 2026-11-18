<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Event;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class SymfonyEventBus implements EventBusInterface
{
    public function __construct(private readonly EventDispatcherInterface $eventDispatcher, private readonly LoggerInterface $logger = new NullLogger())
    {
    }

    #[\Override]
    public function publish(DomainEvent $event): void
    {
        $this->eventDispatcher->dispatch($event, $event->getName());
        $this->logger->debug('domain.event.published', [
            'event' => $event->getName(),
            'occurred_at' => $event->getOccurredAt()->format(DATE_ATOM),
            'payload' => $event->getPayload(),
        ]);
    }
}
