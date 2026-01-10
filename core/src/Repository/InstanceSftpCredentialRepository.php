<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Instance;
use App\Entity\InstanceSftpCredential;
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
}
