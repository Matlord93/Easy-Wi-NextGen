<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Ticket;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\TicketStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Ticket>
 */
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
        return EntityQueryResult::all($this->createQueryBuilder('ticket')
            ->addSelect('customer')
            ->innerJoin('ticket.customer', 'customer')
            ->andWhere('ticket.customer = :customer')
            ->setParameter('customer', QueryParameter::entityId($customer->getId(), \App\Module\Core\Domain\Entity\User::class))
            ->orderBy('ticket.lastMessageAt', 'DESC')
            ->getQuery(), \App\Module\Core\Domain\Entity\Ticket::class);
    }

    /**
     * @return Ticket[]
     */
    public function findForAdminList(): array
    {
        return EntityQueryResult::all($this->createQueryBuilder('ticket')
            ->addSelect('customer', 'assignedTo')
            ->innerJoin('ticket.customer', 'customer')
            ->leftJoin('ticket.assignedTo', 'assignedTo')
            ->orderBy('ticket.lastMessageAt', 'DESC')
            ->getQuery(), \App\Module\Core\Domain\Entity\Ticket::class);
    }

    /**
     * Returns the operational admin queue for one support user.
     *
     * The queue contains only unassigned open tickets plus tickets that are
     * already assigned to the current admin while still requiring attention.
     *
     * @return Ticket[]
     */
    public function findVisibleForAdminQueue(User $admin): array
    {
        return EntityQueryResult::all($this->createQueryBuilder('ticket')
            ->addSelect('customer', 'assignedTo')
            ->innerJoin('ticket.customer', 'customer')
            ->leftJoin('ticket.assignedTo', 'assignedTo')
            ->andWhere('(ticket.status = :openStatus AND ticket.assignedTo IS NULL) OR (ticket.status IN (:activeStatuses) AND ticket.assignedTo = :admin)')
            ->setParameter('openStatus', TicketStatus::Open)
            ->setParameter('activeStatuses', [TicketStatus::Open, TicketStatus::Pending])
            ->setParameter('admin', QueryParameter::entityId($admin->getId(), \App\Module\Core\Domain\Entity\User::class))
            ->orderBy('ticket.lastMessageAt', 'DESC')
            ->getQuery(), \App\Module\Core\Domain\Entity\Ticket::class);
    }

    public function deleteClosedBefore(\DateTimeImmutable $cutoff): int
    {
        return EntityQueryResult::affected($this->createQueryBuilder('ticket')
            ->delete()
            ->andWhere('ticket.status = :status')
            ->andWhere('ticket.updatedAt < :cutoff')
            ->setParameter('status', TicketStatus::Closed)
            ->setParameter('cutoff', $cutoff, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->getQuery());
    }
}
