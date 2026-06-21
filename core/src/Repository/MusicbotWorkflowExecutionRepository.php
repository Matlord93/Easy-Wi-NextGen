<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotWorkflow;
use App\Module\Musicbot\Domain\Entity\MusicbotWorkflowExecution;
use App\Module\Musicbot\Domain\Enum\MusicbotWorkflowExecutionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MusicbotWorkflowExecution> */
final class MusicbotWorkflowExecutionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MusicbotWorkflowExecution::class);
    }

    /** @return MusicbotWorkflowExecution[] */
    public function findByWorkflow(MusicbotWorkflow $workflow, int $limit = 50): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.workflow = :workflow')
            ->setParameter('workflow', $workflow)
            ->orderBy('e.triggeredAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return MusicbotWorkflowExecution[] */
    public function findRecentForCustomer(User $customer, int $limit = 100): array
    {
        return $this->createQueryBuilder('e')
            ->innerJoin('e.workflow', 'w')
            ->andWhere('w.customer = :customer')
            ->setParameter('customer', $customer)
            ->orderBy('e.triggeredAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return MusicbotWorkflowExecution[] */
    public function findFailedForWorkflow(MusicbotWorkflow $workflow, int $limit = 20): array
    {
        return $this->findBy(
            ['workflow' => $workflow, 'status' => MusicbotWorkflowExecutionStatus::Failed],
            ['triggeredAt' => 'DESC'],
            $limit,
        );
    }
}
