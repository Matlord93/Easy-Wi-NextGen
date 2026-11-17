<?php

declare(strict_types=1);

namespace App\Module\Unifi\EventSubscriber;

use App\Module\Ports\Domain\Entity\PortAllocation;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Unifi\Application\UnifiSyncOutbox;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;

#[AsDoctrineListener(event: Events::postPersist, connection: 'default')]
#[AsDoctrineListener(event: Events::postUpdate, connection: 'default')]
#[AsDoctrineListener(event: Events::preRemove, connection: 'default')]
#[AsDoctrineListener(event: Events::postRemove, connection: 'default')]
final class PortAllocationSyncSubscriber implements EventSubscriber
{
    /** @var \WeakMap<object, mixed> */
    private \WeakMap $removedInstanceIds;

    public function __construct(private readonly UnifiSyncOutbox $outbox)
    {
        $this->removedInstanceIds = new \WeakMap();
    }

    /** @return list<string> */
    #[\Override]
    public function getSubscribedEvents(): array
    {
        return [Events::postPersist, Events::postUpdate, Events::preRemove, Events::postRemove];
    }

    public function postPersist(LifecycleEventArgs $args): void
    {
        $this->enqueueAllocation($args);
    }

    public function postUpdate(LifecycleEventArgs $args): void
    {
        $this->enqueueAllocation($args);
    }

    public function preRemove(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof PortAllocation) {
            $this->removedInstanceIds[$entity] = $entity->getInstance()->getId();
        } elseif ($entity instanceof Instance) {
            $this->removedInstanceIds[$entity] = $entity->getId();
        }
    }

    public function postRemove(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof PortAllocation && !$entity instanceof Instance) {
            return;
        }
        $instanceId = self::persistedId($this->removedInstanceIds[$entity] ?? null)
            ?? ($entity instanceof Instance ? $entity->getId() : $entity->getInstance()->getId());
        unset($this->removedInstanceIds[$entity]);
        if ($instanceId !== null) {
            $this->outbox->enqueue($instanceId);
        }
    }

    private function enqueueAllocation(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof PortAllocation) {
            return;
        }
        $instanceId = $entity->getInstance()->getId();
        if ($instanceId !== null) {
            $this->outbox->enqueue($instanceId);
        }
    }

    private static function persistedId(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }
}
