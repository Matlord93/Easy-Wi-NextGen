<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotWorkflow;
use App\Module\Musicbot\Domain\Enum\MusicbotWorkflowTriggerType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MusicbotWorkflow> */
final class MusicbotWorkflowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MusicbotWorkflow::class);
    }

    /** @return MusicbotWorkflow[] */
    public function findByCustomer(User $customer): array
    {
        return $this->findBy(['customer' => $customer], ['createdAt' => 'DESC']);
    }

    /** @return MusicbotWorkflow[] */
    public function findByInstance(MusicbotInstance $instance): array
    {
        return $this->findBy(['instance' => $instance], ['createdAt' => 'DESC']);
    }

    /** @return MusicbotWorkflow[] */
    public function findByInstanceForCustomer(MusicbotInstance $instance, User $customer): array
    {
        return $this->findBy(['instance' => $instance, 'customer' => $customer], ['createdAt' => 'DESC']);
    }

    /** @return MusicbotWorkflow[] */
    public function findEnabledByTriggerType(MusicbotWorkflowTriggerType $triggerType): array
    {
        return $this->findBy(['enabled' => true, 'triggerType' => $triggerType]);
    }

    /** @return MusicbotWorkflow[] */
    public function findEnabledByTriggerTypeAndInstance(MusicbotWorkflowTriggerType $triggerType, MusicbotInstance $instance): array
    {
        return $this->findBy(['enabled' => true, 'triggerType' => $triggerType, 'instance' => $instance]);
    }

    public function countByCustomer(User $customer): int
    {
        return $this->count(['customer' => $customer]);
    }

    /**
     * @param array<string, mixed> $filters  Supports: customer_id, instance_id, trigger_type, enabled
     * @return MusicbotWorkflow[]
     */
    public function findForAdmin(array $filters = []): array
    {
        $qb = $this->createQueryBuilder('w')
            ->orderBy('w.createdAt', 'DESC');

        if (isset($filters['customer_id'])) {
            $qb->andWhere('w.customer = :customerId')->setParameter('customerId', (int) $filters['customer_id']);
        }
        if (isset($filters['instance_id'])) {
            $qb->andWhere('w.instance = :instanceId')->setParameter('instanceId', (int) $filters['instance_id']);
        }
        if (isset($filters['trigger_type'])) {
            $type = MusicbotWorkflowTriggerType::tryFrom((string) $filters['trigger_type']);
            if ($type !== null) {
                $qb->andWhere('w.triggerType = :triggerType')->setParameter('triggerType', $type);
            }
        }
        if (isset($filters['enabled'])) {
            $qb->andWhere('w.enabled = :enabled')->setParameter('enabled', (bool) $filters['enabled']);
        }

        return $qb->getQuery()->getResult();
    }
}
