<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Ticket;
use App\Module\Core\Domain\Entity\TicketAttachment;
use App\Module\Core\Domain\Entity\TicketMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class TicketAttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TicketAttachment::class);
    }

    /**
     * @return TicketAttachment[]
     */
    public function findByMessage(TicketMessage $message): array
    {
        return $this->findBy(['message' => $message], ['createdAt' => 'ASC']);
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

        return $this->createQueryBuilder('attachment')
            ->andWhere('attachment.message IN (:messages)')
            ->setParameter('messages', $messages)
            ->orderBy('attachment.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return TicketAttachment[]
     */
    public function findByTicket(Ticket $ticket): array
    {
        return $this->findBy(['ticket' => $ticket], ['createdAt' => 'ASC']);
    }
}
