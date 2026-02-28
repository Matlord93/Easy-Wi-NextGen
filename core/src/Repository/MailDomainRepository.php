<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Domain;
use App\Module\Core\Domain\Entity\MailDomain;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class MailDomainRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailDomain::class);
    }

    public function findOneByDomain(Domain $domain): ?MailDomain
    {
        return $this->findOneBy(['domain' => $domain]);
    }
}

