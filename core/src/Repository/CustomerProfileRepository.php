<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\CustomerProfile;
use App\Module\Core\Domain\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class CustomerProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CustomerProfile::class);
    }

    public function findOneByCustomer(User $customer): ?CustomerProfile
    {
        return $this->findOneBy(['customer' => $customer]);
    }
}
