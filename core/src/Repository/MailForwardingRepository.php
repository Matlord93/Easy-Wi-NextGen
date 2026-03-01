<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Domain;
use App\Module\Core\Domain\Entity\MailForwarding;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class MailForwardingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailForwarding::class);
    }

    /** @return MailForwarding[] */
    public function findEnabledByDomain(Domain $domain): array
    {
        return $this->findBy(['domain' => $domain, 'enabled' => true], ['id' => 'ASC']);
    }
}
