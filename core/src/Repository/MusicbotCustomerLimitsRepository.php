<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotCustomerLimits;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MusicbotCustomerLimits> */
final class MusicbotCustomerLimitsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MusicbotCustomerLimits::class);
    }

    public function findByCustomer(User $customer): ?MusicbotCustomerLimits
    {
        return $this->findOneBy(['customer' => $customer]);
    }
}
