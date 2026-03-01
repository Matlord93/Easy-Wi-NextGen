<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Domain;
use App\Module\Core\Domain\Entity\MailDomain;
use App\Module\Core\Domain\Entity\User;
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

    public function findOneForOwnerByDomainName(User $owner, string $domainName): ?MailDomain
    {
        return $this->findOneBy([
            'owner' => $owner,
            'domainName' => MailDomain::normalizeDomainName($domainName),
        ]);
    }

    /**
     * @return MailDomain[]
     */
    public function findByOwner(User $owner, int $limit = 100, int $offset = 0): array
    {
        return $this->createQueryBuilder('md')
            ->andWhere('md.owner = :owner')
            ->setParameter('owner', $owner)
            ->setMaxResults(max(1, min($limit, 500)))
            ->setFirstResult(max(0, $offset))
            ->orderBy('md.domainName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return MailDomain[]
     */
    public function findByOwnerAndStatus(User $owner, string $status): array
    {
        $normalizedStatus = strtolower(trim($status));

        return $this->createQueryBuilder('md')
            ->andWhere('md.owner = :owner')
            ->andWhere('md.dkimStatus = :status OR md.spfStatus = :status OR md.dmarcStatus = :status OR md.mxStatus = :status OR md.tlsStatus = :status')
            ->setParameter('owner', $owner)
            ->setParameter('status', $normalizedStatus)
            ->orderBy('md.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return MailDomain[]
     */
    public function findStaleDnsChecks(User $owner, \DateTimeImmutable $before, int $limit = 200): array
    {
        return $this->createQueryBuilder('md')
            ->andWhere('md.owner = :owner')
            ->andWhere('md.dnsLastCheckedAt IS NULL OR md.dnsLastCheckedAt < :before')
            ->setParameter('owner', $owner)
            ->setParameter('before', $before)
            ->setMaxResults(max(1, min($limit, 1000)))
            ->orderBy('md.dnsLastCheckedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
