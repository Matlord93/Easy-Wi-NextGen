<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Agent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AgentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Agent::class);
    }

    /** @return Agent[] */
    public function findActive(): array
    {
        return $this->findBy(['status' => Agent::STATUS_ACTIVE], ['name' => 'ASC']);
    }
}
