<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\SecurityPolicyRevision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SecurityPolicyRevision>
 */
final class SecurityPolicyRevisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SecurityPolicyRevision::class);
    }

    public function nextVersion(Agent $node, string $policyType): int
    {
        $result = $this->createQueryBuilder('revision')
            ->select('MAX(revision.version)')
            ->where('revision.node = :node')
            ->andWhere('revision.policyType = :policyType')
            ->setParameter('node', $node)
            ->setParameter('policyType', $policyType)
            ->getQuery()
            ->getSingleScalarResult();

        if (!is_numeric($result)) {
            return 1;
        }

        return (int) $result + 1;
    }

    /**
     * @param Agent[] $nodes
     * @return SecurityPolicyRevision[]
     */
    public function findLatestByNodesAndType(array $nodes, string $policyType): array
    {
        if ($nodes === []) {
            return [];
        }

        return $this->createQueryBuilder('revision')
            ->where('revision.node IN (:nodes)')
            ->andWhere('revision.policyType = :policyType')
            ->setParameter('nodes', $nodes)
            ->setParameter('policyType', $policyType)
            ->orderBy('revision.version', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
