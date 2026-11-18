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
final class MailDomainRepository extends ServiceEntityRepository implements MailDomainRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailDomain::class);
    }

    #[\Override]
    public function findOneByDomain(Domain $domain): ?MailDomain
    {
        return $this->findOneBy(['domain' => $domain]);
    }

    #[\Override]
    public function findOneByDomainName(string $domainName): ?MailDomain
    {
        return $this->findOneBy(['domainName' => MailDomain::normalizeDomainName($domainName)]);
    }

}
