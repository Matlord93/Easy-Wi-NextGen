<?php

declare(strict_types=1);

namespace App\Module\HostingPanel\Application\Audit;

use App\Module\HostingPanel\Domain\Entity\Agent;
use App\Module\HostingPanel\Domain\Entity\AuditLog;
use App\Module\HostingPanel\Domain\Entity\Module;
use App\Module\HostingPanel\Domain\Entity\Node;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;

#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postPersist)]
class AuditDoctrineSubscriber
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $uow = $entityManager->getUnitOfWork();

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

            $targetId = $this->resolveTargetId($entity, $uow);
            if ($targetId === null) {
                continue;
            }

            $log = new AuditLog('system', 'update', $entity::class, $targetId, $before, $after);
            $entityManager->persist($log);
            $uow->computeChangeSet($entityManager->getClassMetadata($log::class), $log);
        }
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$this->supports($entity)) {
            return;
        }

        $entityManager = $args->getObjectManager();
        $identifier = $entityManager->getUnitOfWork()->getSingleIdentifierValue($entity);
        if (!is_scalar($identifier)) {
            return;
        }

        $entityManager->getConnection()->insert('hp_audit_log', [
            'actor' => 'system',
            'action' => 'create',
            'target_type' => $entity::class,
            'target_id' => (string) $identifier,
            'before_state' => json_encode([], JSON_THROW_ON_ERROR),
            'after_state' => json_encode(['created' => true], JSON_THROW_ON_ERROR),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    private function resolveTargetId(object $entity, UnitOfWork $uow): ?string
    {
        $identifier = $uow->getSingleIdentifierValue($entity);
        if (is_scalar($identifier)) {
            return (string) $identifier;
        }

        return null;
    }

    private function supports(object $entity): bool
    {
        return $entity instanceof Node || $entity instanceof Agent || $entity instanceof Module;
    }
}
