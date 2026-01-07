<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Invoice;
use App\Entity\Payment;
use App\Enum\PaymentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class PaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    public function sumSucceededForInvoice(Invoice $invoice): int
    {
        return (int) $this->createQueryBuilder('payment')
            ->select('COALESCE(SUM(payment.amountCents), 0)')
            ->andWhere('payment.invoice = :invoice')
            ->andWhere('payment.status = :status')
            ->setParameter('invoice', $invoice)
            ->setParameter('status', PaymentStatus::Succeeded)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
