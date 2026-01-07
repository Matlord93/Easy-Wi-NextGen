<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BackupDefinition;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class BackupDefinitionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BackupDefinition::class);
    }

    /**
     * @return BackupDefinition[]
     */
    public function findByCustomer(User $customer): array
    {
        return $this->findBy(['customer' => $customer], ['updatedAt' => 'DESC']);
    }
}
