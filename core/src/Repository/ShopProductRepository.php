<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\ShopProduct;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class ShopProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShopProduct::class);
    }
}
