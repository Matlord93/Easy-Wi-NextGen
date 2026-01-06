<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Mailbox;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class MailboxRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Mailbox::class);
    }

    /**
     * @return Mailbox[]
     */
    public function findByCustomer(User $customer): array
    {
        return $this->findBy(['customer' => $customer], ['updatedAt' => 'DESC']);
    }

    public function findOneByAddress(string $address): ?Mailbox
    {
        return $this->findOneBy(['address' => $address]);
    }
}
