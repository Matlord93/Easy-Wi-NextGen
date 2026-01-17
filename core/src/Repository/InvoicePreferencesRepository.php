<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\InvoicePreferences;
use App\Module\Core\Domain\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class InvoicePreferencesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvoicePreferences::class);
    }

    public function findOneByCustomer(User $customer): ?InvoicePreferences
    {
        return $this->findOneBy(['customer' => $customer]);
    }
}
