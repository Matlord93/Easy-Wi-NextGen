<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DunningReminder;
use App\Entity\Invoice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class DunningReminderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DunningReminder::class);
    }

    public function findLatestForInvoice(Invoice $invoice): ?DunningReminder
    {
        return $this->createQueryBuilder('reminder')
            ->andWhere('reminder.invoice = :invoice')
            ->setParameter('invoice', $invoice)
            ->orderBy('reminder.level', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
