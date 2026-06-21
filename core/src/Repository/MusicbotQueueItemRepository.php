<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotQueueItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MusicbotQueueItem> */
final class MusicbotQueueItemRepository extends ServiceEntityRepository implements MusicbotQueueItemRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MusicbotQueueItem::class);
    }

    /** @return MusicbotQueueItem[] */
    public function findByCustomer(User $customer): array
    {
        return $this->createQueryBuilder('queueItem')
            ->innerJoin('queueItem.instance', 'instance')
            ->andWhere('instance.customer = :customer')
            ->setParameter('customer', $customer)
            ->orderBy('queueItem.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneForCustomer(int $id, User $customer): ?MusicbotQueueItem
    {
        return $this->createQueryBuilder('queueItem')
            ->innerJoin('queueItem.instance', 'instance')
            ->andWhere('queueItem.id = :id')
            ->andWhere('instance.customer = :customer')
            ->setParameter('id', $id)
            ->setParameter('customer', $customer)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return MusicbotQueueItem[] */
    public function findQueueForInstanceOrdered(MusicbotInstance $instance): array
    {
        return $this->findBy(['instance' => $instance], ['position' => 'ASC', 'createdAt' => 'ASC']);
    }
}
