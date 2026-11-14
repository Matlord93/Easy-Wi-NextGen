<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Ticket;
use App\Module\Core\Domain\Entity\TicketMessage;
use App\Module\Core\Domain\Enum\TicketStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TicketMessage>
 */
final class TicketMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TicketMessage::class);
    }

    /**
     * @return TicketMessage[]
     */
    public function findByTicket(Ticket $ticket): array
    {
        return EntityQueryResult::all($this->createQueryBuilder('message')
            ->addSelect('author')
            ->innerJoin('message.author', 'author')
            ->andWhere('message.ticket = :ticket')
            ->setParameter('ticket', QueryParameter::entityId($ticket->getId(), \App\Module\Core\Domain\Entity\Ticket::class))
            ->orderBy('message.createdAt', 'ASC')
            ->getQuery(), \App\Module\Core\Domain\Entity\TicketMessage::class);
    }

    /**
     * @return TicketMessage[]
     */
    public function findPublicByTicket(Ticket $ticket): array
    {
        return EntityQueryResult::all($this->createQueryBuilder('message')
            ->addSelect('author')
            ->innerJoin('message.author', 'author')
            ->andWhere('message.ticket = :ticket')
            ->andWhere('message.internal = false')
            ->setParameter('ticket', QueryParameter::entityId($ticket->getId(), \App\Module\Core\Domain\Entity\Ticket::class))
            ->orderBy('message.createdAt', 'ASC')
            ->getQuery(), \App\Module\Core\Domain\Entity\TicketMessage::class);
    }

    public function deleteForClosedTicketsBefore(\DateTimeImmutable $cutoff): int
    {
        return EntityQueryResult::affected($this->createQueryBuilder('message')
            ->delete()
            ->andWhere('message.ticket IN (
                SELECT t.id FROM App\\Module\\Core\\Domain\\Entity\\Ticket t
                WHERE t.status = :status AND t.updatedAt < :cutoff
            )')
            ->setParameter('status', TicketStatus::Closed)
            ->setParameter('cutoff', $cutoff, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->getQuery());
    }
}
