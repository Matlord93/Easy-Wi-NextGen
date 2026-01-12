<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InvoiceArchive;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class InvoiceArchiveRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvoiceArchive::class);
    }

    /**
     * @param int[] $invoiceIds
     * @return int[]
     */
    public function findArchivedInvoiceIds(array $invoiceIds): array
    {
        if ($invoiceIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('archive')
            ->select('IDENTITY(archive.invoice) AS invoice_id')
            ->andWhere('archive.invoice IN (:invoiceIds)')
            ->setParameter('invoiceIds', $invoiceIds)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): int => (int) $row['invoice_id'], $rows);
    }

    /**
     * @param int[] $invoiceIds
     * @return array<int, array{archived_year: int, pdf_hash: string}>
     */
    public function findArchiveMetadataByInvoiceIds(array $invoiceIds): array
    {
        if ($invoiceIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('archive')
            ->select('IDENTITY(archive.invoice) AS invoice_id', 'archive.archivedYear AS archived_year', 'archive.pdfHash AS pdf_hash')
            ->andWhere('archive.invoice IN (:invoiceIds)')
            ->setParameter('invoiceIds', $invoiceIds)
            ->getQuery()
            ->getArrayResult();

        $mapped = [];
        foreach ($rows as $row) {
            $invoiceId = (int) $row['invoice_id'];
            $mapped[$invoiceId] = [
                'archived_year' => (int) $row['archived_year'],
                'pdf_hash' => (string) $row['pdf_hash'],
            ];
        }

        return $mapped;
    }

    /**
     * @return InvoiceArchive[]
     */
    public function findByCustomer(User $customer): array
    {
        return $this->createQueryBuilder('archive')
            ->innerJoin('archive.invoice', 'invoice')
            ->andWhere('invoice.customer = :customer')
            ->setParameter('customer', $customer)
            ->orderBy('archive.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
