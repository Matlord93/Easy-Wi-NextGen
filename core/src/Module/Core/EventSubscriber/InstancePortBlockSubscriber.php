<?php

declare(strict_types=1);

namespace App\Module\Core\EventSubscriber;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Ports\Infrastructure\Repository\PortBlockRepository;
use App\Module\Core\Application\AuditLogger;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;

final class InstancePortBlockSubscriber implements EventSubscriber
{
    public function __construct(
        private readonly PortBlockRepository $portBlockRepository,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getSubscribedEvents(): array
    {
        return [Events::preRemove];
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

        $portBlock->releaseInstance();
        $this->entityManager->persist($portBlock);

        $this->auditLogger->log(null, 'port_block.released', [
            'port_block_id' => $portBlock->getId(),
            'instance_id' => $entity->getId(),
            'customer_id' => $entity->getCustomer()->getId(),
        ]);
    }
}
