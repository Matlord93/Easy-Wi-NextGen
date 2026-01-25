<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\ShopCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class ShopCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShopCategory::class);
    }
}
