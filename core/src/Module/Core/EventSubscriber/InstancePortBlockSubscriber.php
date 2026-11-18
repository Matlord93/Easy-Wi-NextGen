<?php

declare(strict_types=1);

namespace App\Module\Core\EventSubscriber;

use App\Module\Core\Application\AuditLoggerInterface;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Ports\Infrastructure\Repository\PortBlockRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::preRemove)]
final class InstancePortBlockSubscriber
{
    public function __construct(
        private readonly PortBlockRepositoryInterface $portBlockRepository,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Instance) {
            return;
        }

        $portBlockId = $entity->getPortBlockId();
        if ($portBlockId === null) {
            return;
        }

        $portBlock = $this->portBlockRepository->find($portBlockId);
        if ($portBlock === null) {
            return;
        }

        if ($portBlock->getInstance()?->getId() !== $entity->getId()) {
            return;
        }

        $this->entityManager->remove($portBlock);

        $this->auditLogger->log(null, 'port_block.released', [
            'port_block_id' => $portBlock->getId(),
            'instance_id' => $entity->getId(),
            'customer_id' => $entity->getCustomer()->getId(),
        ]);
    }
}
