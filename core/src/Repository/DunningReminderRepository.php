<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\DunningReminder;
use App\Module\Core\Domain\Entity\Invoice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DunningReminder>
 */
final class DunningReminderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DunningReminder::class);
    }

    public function findLatestForInvoice(Invoice $invoice): ?DunningReminder
    {
        return EntityQueryResult::one($this->createQueryBuilder('reminder')
            ->andWhere('reminder.invoice = :invoice')
            ->setParameter('invoice', QueryParameter::entityId($invoice->getId(), \App\Module\Core\Domain\Entity\Invoice::class))
            ->orderBy('reminder.level', 'DESC')
            ->setMaxResults(1)
            ->getQuery(), \App\Module\Core\Domain\Entity\DunningReminder::class);
    }
}
