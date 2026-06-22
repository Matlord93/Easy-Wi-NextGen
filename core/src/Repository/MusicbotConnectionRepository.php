<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotConnection;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MusicbotConnection> */
class MusicbotConnectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MusicbotConnection::class);
    }

    /** @return MusicbotConnection[] */
    public function findByCustomer(User $customer): array
    {
        return $this->createQueryBuilder('connection')
            ->innerJoin('connection.musicbotInstance', 'instance')
            ->andWhere('instance.customer = :customer')
            ->setParameter('customer', $customer)
            ->orderBy('connection.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneForCustomer(int $id, User $customer): ?MusicbotConnection
    {
        return $this->createQueryBuilder('connection')
            ->innerJoin('connection.musicbotInstance', 'instance')
            ->andWhere('connection.id = :id')
            ->andWhere('instance.customer = :customer')
            ->setParameter('id', $id)
            ->setParameter('customer', $customer)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return MusicbotConnection[] */
    public function findEnabledConnections(MusicbotInstance $instance): array
    {
        return $this->findBy(['musicbotInstance' => $instance, 'enabled' => true], ['id' => 'ASC']);
    }
}
