<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Ticket;
use App\Entity\User;
use App\Enum\TicketStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class TicketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ticket::class);
    }

    /**
     * @return Ticket[]
     */
    public function findByCustomer(User $customer): array
    {
        return $this->findBy(['customer' => $customer], ['lastMessageAt' => 'DESC']);
    }

    public function deleteClosedBefore(\DateTimeImmutable $cutoff): int
    {
        return $this->createQueryBuilder('ticket')
            ->delete()
            ->andWhere('ticket.status = :status')
            ->andWhere('ticket.updatedAt < :cutoff')
            ->setParameter('status', TicketStatus::Closed)
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }
}
