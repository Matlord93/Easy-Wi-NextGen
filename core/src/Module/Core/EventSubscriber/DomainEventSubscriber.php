<?php

declare(strict_types=1);

namespace App\Module\Core\EventSubscriber;

use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Enum\JobStatus;
use App\Module\Core\Domain\Event\EventBusInterface;
use App\Module\Core\Domain\Event\JobStateChangedEvent;
use App\Module\Core\Domain\Event\ResourceEvent;
use App\Module\Core\Domain\Event\ResourceEventSource;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;

#[AsDoctrineListener(event: Events::preRemove)]
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postRemove)]
final class DomainEventSubscriber
{
    /** @var \WeakMap<object, mixed> */
    private \WeakMap $removedResourceIds;

    public function __construct(private readonly EventBusInterface $eventBus)
    {
        $this->removedResourceIds = new \WeakMap();
    }

    public function preRemove(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof ResourceEventSource) {
            // Doctrine clears generated identifiers before deferred postRemove callbacks.
            $this->removedResourceIds[$entity] = $entity->getResourceId();
        }
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

        $this->eventBus->publish(new JobStateChangedEvent(
            $entity->getId(),
            $entity->getType(),
            $this->normalizeStatus($changeSet['status'][0]),
            $this->normalizeStatus($changeSet['status'][1]),
        ));
    }

    public function postRemove(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof ResourceEventSource) {
            return;
        }

        $resourceId = self::storedResourceId($this->removedResourceIds[$entity] ?? null);
        if ($resourceId === null) {
            $resourceId = $entity->getResourceId();
        }
        unset($this->removedResourceIds[$entity]);
        $this->eventBus->publish(new ResourceEvent(
            'resource.deleted',
            $entity->getResourceType(),
            $resourceId,
        ));
    }

    private static function storedResourceId(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function normalizeStatus(mixed $status): string
    {
        if ($status instanceof JobStatus) {
            return $status->value;
        }

        return (string) $status;
    }
}
