<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Domain;
use App\Module\Core\Domain\Entity\MailDomain;
use App\Module\Core\Domain\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MailDomain>
 */
class MailDomainRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailDomain::class);
    }

    public function findOneByDomain(Domain $domain): ?MailDomain
    {
        return $this->findOneBy(['domain' => $domain]);
    }

    public function findOneByDomainName(string $domainName): ?MailDomain
    {
        return $this->findOneBy(['domainName' => MailDomain::normalizeDomainName($domainName)]);
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
        return EntityQueryResult::all($this->createQueryBuilder('md')
            ->andWhere('md.owner = :owner')
            ->setParameter('owner', QueryParameter::entityId($owner->getId(), \App\Module\Core\Domain\Entity\User::class))
            ->setMaxResults(max(1, min($limit, 500)))
            ->setFirstResult(max(0, $offset))
            ->orderBy('md.domainName', 'ASC')
            ->getQuery(), \App\Module\Core\Domain\Entity\MailDomain::class);
    }

    /**
     * @return MailDomain[]
     */
    public function findByOwnerAndStatus(User $owner, string $status): array
    {
        $normalizedStatus = strtolower(trim($status));

        return EntityQueryResult::all($this->createQueryBuilder('md')
            ->andWhere('md.owner = :owner')
            ->andWhere('md.dkimStatus = :status OR md.spfStatus = :status OR md.dmarcStatus = :status OR md.mxStatus = :status OR md.tlsStatus = :status')
            ->setParameter('owner', QueryParameter::entityId($owner->getId(), \App\Module\Core\Domain\Entity\User::class))
            ->setParameter('status', $normalizedStatus)
            ->orderBy('md.updatedAt', 'DESC')
            ->getQuery(), \App\Module\Core\Domain\Entity\MailDomain::class);
    }

    /**
     * @return MailDomain[]
     */
    public function findStaleDnsChecks(User $owner, \DateTimeImmutable $before, int $limit = 200): array
    {
        return EntityQueryResult::all($this->createQueryBuilder('md')
            ->andWhere('md.owner = :owner')
            ->andWhere('md.dnsLastCheckedAt IS NULL OR md.dnsLastCheckedAt < :before')
            ->setParameter('owner', QueryParameter::entityId($owner->getId(), \App\Module\Core\Domain\Entity\User::class))
            ->setParameter('before', $before, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->setMaxResults(max(1, min($limit, 1000)))
            ->orderBy('md.dnsLastCheckedAt', 'ASC')
            ->getQuery(), \App\Module\Core\Domain\Entity\MailDomain::class);
    }
}
