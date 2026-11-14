<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotQueueItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MusicbotQueueItem> */
class MusicbotQueueItemRepository extends ServiceEntityRepository implements MusicbotQueueItemRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MusicbotQueueItem::class);
    }

    /** @return MusicbotQueueItem[] */
    public function findByCustomer(User $customer): array
    {
        return EntityQueryResult::all($this->createQueryBuilder('queueItem')
            ->innerJoin('queueItem.instance', 'musicbotInstance')
            ->andWhere('musicbotInstance.customer = :customer')
            ->setParameter('customer', QueryParameter::entityId($customer->getId(), \App\Module\Core\Domain\Entity\User::class))
            ->orderBy('queueItem.createdAt', 'DESC')
            ->getQuery(), \App\Module\Musicbot\Domain\Entity\MusicbotQueueItem::class);
    }

    public function findOneForCustomer(int $id, User $customer): ?MusicbotQueueItem
    {
        return EntityQueryResult::one($this->createQueryBuilder('queueItem')
            ->innerJoin('queueItem.instance', 'musicbotInstance')
            ->andWhere('queueItem.id = :id')
            ->andWhere('musicbotInstance.customer = :customer')
            ->setParameter('id', $id)
            ->setParameter('customer', QueryParameter::entityId($customer->getId(), \App\Module\Core\Domain\Entity\User::class))
            ->getQuery(), \App\Module\Musicbot\Domain\Entity\MusicbotQueueItem::class);
    }

    /** @return MusicbotQueueItem[] */
    #[\Override]
    public function findQueueForInstanceOrdered(MusicbotInstance $instance): array
    {
        return $this->findBy(['instance' => $instance], ['position' => 'ASC', 'createdAt' => 'ASC']);
    }
}
