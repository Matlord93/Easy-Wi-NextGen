<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Notification;
use App\Module\Core\Domain\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

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
            ->setParameter('recipient', $recipient)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return Notification[]
     */
    public function findLatestForRecipient(User $recipient, int $limit = 30): array
    {
        return $this->createQueryBuilder('notification')
            ->andWhere('notification.recipient = :recipient')
            ->setParameter('recipient', $recipient)
            ->orderBy('notification.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
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
        return $this->createQueryBuilder('notification')
            ->delete()
            ->andWhere('notification.recipient = :recipient')
            ->setParameter('recipient', $recipient)
            ->getQuery()
            ->execute();
    }
}
