<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Notification;
use App\Module\Core\Domain\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
final class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    public function findUnreadCount(User $recipient): int
    {
        return (int) $this->createQueryBuilder('notification')
            ->select('COUNT(notification.id)')
            ->andWhere('notification.recipient = :recipient')
            ->andWhere('notification.readAt IS NULL')
            ->setParameter('recipient', QueryParameter::entityId($recipient->getId(), \App\Module\Core\Domain\Entity\User::class))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return Notification[]
     */
    public function findLatestForRecipient(User $recipient, int $limit = 30): array
    {
        return EntityQueryResult::all($this->createQueryBuilder('notification')
            ->andWhere('notification.recipient = :recipient')
            ->setParameter('recipient', QueryParameter::entityId($recipient->getId(), \App\Module\Core\Domain\Entity\User::class))
            ->orderBy('notification.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery(), \App\Module\Core\Domain\Entity\Notification::class);
    }

    public function findOneByEventKey(User $recipient, string $eventKey): ?Notification
    {
        return $this->findOneBy([
            'recipient' => $recipient,
            'eventKey' => $eventKey,
        ]);
    }

    public function deleteByRecipient(User $recipient): int
    {
        return EntityQueryResult::affected($this->createQueryBuilder('notification')
            ->delete()
            ->andWhere('notification.recipient = :recipient')
            ->setParameter('recipient', QueryParameter::entityId($recipient->getId(), \App\Module\Core\Domain\Entity\User::class))
            ->getQuery());
    }

    public function markAllReadForRecipient(User $recipient): int
    {
        return EntityQueryResult::affected($this->createQueryBuilder('notification')
            ->update()
            ->set('notification.readAt', ':readAt')
            ->andWhere('notification.recipient = :recipient')
            ->andWhere('notification.readAt IS NULL')
            ->setParameter('recipient', QueryParameter::entityId($recipient->getId(), \App\Module\Core\Domain\Entity\User::class))
            ->setParameter('readAt', new \DateTimeImmutable(), \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->getQuery());
    }
}
