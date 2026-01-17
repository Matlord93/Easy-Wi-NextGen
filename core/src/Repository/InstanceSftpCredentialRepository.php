<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\InstanceSftpCredential;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class InstanceSftpCredentialRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InstanceSftpCredential::class);
    }

    public function findOneByInstance(Instance $instance): ?InstanceSftpCredential
    {
        return $this->findOneBy(['instance' => $instance]);
    }

    /**
     * @param Instance[] $instances
     *
     * @return InstanceSftpCredential[]
     */
    public function findByInstances(array $instances): array
    {
        if ($instances === []) {
            return [];
        }

        return $this->createQueryBuilder('credential')
            ->where('credential.instance IN (:instances)')
            ->setParameter('instances', $instances)
            ->getQuery()
            ->getResult();
    }
}
