<?php

declare(strict_types=1);

namespace App\Module\HostingPanel\Application\Audit;

use App\Module\HostingPanel\Domain\Entity\Agent;
use App\Module\HostingPanel\Domain\Entity\AuditLog;
use App\Module\HostingPanel\Domain\Entity\Module;
use App\Module\HostingPanel\Domain\Entity\Node;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::onFlush)]
class AuditDoctrineSubscriber
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $uow = $entityManager->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if (!$this->supports($entity)) {
                continue;
            }

            $log = new AuditLog('system', 'create', $entity::class, (string) spl_object_id($entity), [], ['created' => true]);
            $entityManager->persist($log);
            $uow->computeChangeSet($entityManager->getClassMetadata($log::class), $log);
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$this->supports($entity)) {
                continue;
            }

            $changes = $uow->getEntityChangeSet($entity);
            $before = [];
            $after = [];
            foreach ($changes as $field => [$old, $new]) {
                $before[$field] = is_scalar($old) || $old === null ? $old : get_debug_type($old);
                $after[$field] = is_scalar($new) || $new === null ? $new : get_debug_type($new);
            }

            $log = new AuditLog('system', 'update', $entity::class, (string) spl_object_id($entity), $before, $after);
            $entityManager->persist($log);
            $uow->computeChangeSet($entityManager->getClassMetadata($log::class), $log);
        }
    }

    private function supports(object $entity): bool
    {
        return $entity instanceof Node || $entity instanceof Agent || $entity instanceof Module;
    }
}
