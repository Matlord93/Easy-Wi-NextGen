<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Ticket;
use App\Module\Core\Domain\Entity\TicketAttachment;
use App\Module\Core\Domain\Entity\TicketMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TicketAttachment>
 */
final class TicketAttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TicketAttachment::class);
    }

    /**
     * @param TicketMessage[] $messages
     *
     * @return TicketAttachment[]
     */
    public function findByMessages(array $messages): array
    {
        if ($messages === []) {
            return [];
        }

        return EntityQueryResult::all($this->createQueryBuilder('attachment')
            ->andWhere('attachment.message IN (:messages)')
            ->setParameter('messages', $messages)
            ->orderBy('attachment.createdAt', 'ASC')
            ->getQuery(), \App\Module\Core\Domain\Entity\TicketAttachment::class);
    }

}
