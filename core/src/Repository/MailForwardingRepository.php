<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Domain;
use App\Module\Core\Domain\Entity\MailForwarding;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MailForwarding>
 */
final class MailForwardingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailForwarding::class);
    }

}
