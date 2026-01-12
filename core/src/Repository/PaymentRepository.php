<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Invoice;
use App\Entity\Payment;
use App\Entity\User;
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

    /**
     * @return Payment[]
     */
    public function findByCustomer(User $customer): array
    {
        return $this->createQueryBuilder('payment')
            ->innerJoin('payment.invoice', 'invoice')
            ->andWhere('invoice.customer = :customer')
            ->setParameter('customer', $customer)
            ->orderBy('payment.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Payment[]
     */
    public function findForExport(?int $year = null): array
    {
        $queryBuilder = $this->createQueryBuilder('payment')
            ->orderBy('payment.createdAt', 'ASC');

        if ($year !== null) {
            $start = new \DateTimeImmutable(sprintf('%d-01-01 00:00:00', $year));
            $end = $start->modify('+1 year');
            $queryBuilder
                ->andWhere('payment.createdAt >= :start')
                ->andWhere('payment.createdAt < :end')
                ->setParameter('start', $start)
                ->setParameter('end', $end);
        }

        return $queryBuilder->getQuery()->getResult();
    }
}
