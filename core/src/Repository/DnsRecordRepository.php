<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DnsRecord;
use App\Entity\Domain;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class DnsRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DnsRecord::class);
    }

    /**
     * @return DnsRecord[]
     */
    public function findByDomain(Domain $domain): array
    {
        return $this->findBy(['domain' => $domain], ['updatedAt' => 'DESC']);
    }

    /**
     * @return DnsRecord[]
     */
    public function findByCustomer(User $customer): array
    {
        return $this->createQueryBuilder('record')
            ->join('record.domain', 'domain')
            ->where('domain.customer = :customer')
            ->setParameter('customer', $customer)
            ->orderBy('record.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
