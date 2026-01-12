<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DdosProviderCredential;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class DdosProviderCredentialRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DdosProviderCredential::class);
    }

    public function findOneByCustomerAndProvider(User $customer, string $provider): ?DdosProviderCredential
    {
        return $this->findOneBy(['customer' => $customer, 'provider' => $provider]);
    }
}
