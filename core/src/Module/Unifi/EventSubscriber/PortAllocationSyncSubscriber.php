<?php

declare(strict_types=1);

namespace App\Module\Unifi\EventSubscriber;

use App\Message\UnifiSyncInstancePortsMessage;
use App\Module\Ports\Domain\Entity\PortAllocation;
use Doctrine\Common\EventSubscriber;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Messenger\MessageBusInterface;

final class PortAllocationSyncSubscriber implements EventSubscriber
{
    public function __construct(private readonly MessageBusInterface $messageBus)
    {
    }

    public function getSubscribedEvents(): array
    {
        return [Events::postPersist, Events::postUpdate, Events::postRemove];
    }

    public function postPersist(LifecycleEventArgs $args): void
    {
        $this->dispatchIfAllocation($args);
    }

    public function postUpdate(LifecycleEventArgs $args): void
    {
        $this->dispatchIfAllocation($args);
    }

    public function postRemove(LifecycleEventArgs $args): void
    {
        $this->dispatchIfAllocation($args);
    }

    private function dispatchIfAllocation(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof PortAllocation) {
            return;
        }

        $instanceId = $entity->getInstance()->getId();
        if ($instanceId === null) {
            return;
        }

        $this->messageBus->dispatch(new UnifiSyncInstancePortsMessage($instanceId));
    }
}
