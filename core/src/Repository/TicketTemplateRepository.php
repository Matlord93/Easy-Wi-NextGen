<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TicketTemplate;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class TicketTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TicketTemplate::class);
    }

    /**
     * @return TicketTemplate[]
     */
    public function findByAdmin(User $admin): array
    {
        return $this->findBy(['admin' => $admin], ['updatedAt' => 'DESC']);
    }
}
