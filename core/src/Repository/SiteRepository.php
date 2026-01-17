<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Site;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class SiteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Site::class);
    }

    public function findOneByHost(string $host): ?Site
    {
        return $this->findOneBy(['host' => $host]);
    }

    public function findDefault(): ?Site
    {
        return $this->findOneBy([], ['id' => 'ASC']);
    }
}
