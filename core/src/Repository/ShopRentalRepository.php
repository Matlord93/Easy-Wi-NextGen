<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\ShopRental;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class ShopRentalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShopRental::class);
    }
}
