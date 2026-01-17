<?php

declare(strict_types=1);

namespace App\Module\Core\EventSubscriber;

use App\Module\Core\Domain\Event\EventBusInterface;
use App\Module\Core\Domain\Event\JobStateChangedEvent;
use App\Module\Core\Domain\Event\ResourceEvent;
use App\Module\Core\Domain\Event\ResourceEventSource;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Enum\JobStatus;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;

final class DomainEventSubscriber implements EventSubscriber
{
    public function __construct(private readonly EventBusInterface $eventBus)
    {
    }

    public function getSubscribedEvents(): array
    {
        return [
            Events::postPersist,
            Events::postUpdate,
            Events::postRemove,
        ];
    }

    public function postPersist(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof ResourceEventSource) {
            return;
        }

        $this->eventBus->publish(new ResourceEvent(
            'resource.created',
            $entity->getResourceType(),
            $entity->getResourceId(),
        ));
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof ResourceEventSource) {
            $this->eventBus->publish(new ResourceEvent(
                'resource.updated',
                $entity->getResourceType(),
                $entity->getResourceId(),
            ));
        }

        if (!$entity instanceof Job) {
            return;
        }

        $changeSet = $args->getObjectManager()->getUnitOfWork()->getEntityChangeSet($entity);
        if (!isset($changeSet['status'])) {
            return;
        }

        [$previous, $current] = $changeSet['status'];
        $this->eventBus->publish(new JobStateChangedEvent(
            $entity->getId(),
            $entity->getType(),
            $this->normalizeStatus($previous),
            $this->normalizeStatus($current),
        ));
    }

    public function postRemove(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof ResourceEventSource) {
            return;
        }

        $this->eventBus->publish(new ResourceEvent(
            'resource.deleted',
            $entity->getResourceType(),
            $entity->getResourceId(),
        ));
    }

    private function normalizeStatus(mixed $status): string
    {
        if ($status instanceof JobStatus) {
            return $status->value;
        }

        return (string) $status;
    }
}
