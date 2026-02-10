<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\AuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

class AuditLogRepository extends ServiceEntityRepository
{
    private const PAYLOAD_PREVIEW_LENGTH = 4000;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    public function findLatestHash(): ?string
    {
        $result = $this->createQueryBuilder('audit')
            ->select('audit.hashCurrent')
            ->orderBy('audit.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($result === null) {
            return null;
        }

        return $result['hashCurrent'] ?? null;
    }

    /**
     * @return AuditLog[]
     */
    public function findRecent(int $limit = 30, ?\App\Module\Core\Domain\Entity\User $actor = null): array
    {
        $builder = $this->createQueryBuilder('audit')
            ->orderBy('audit.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($actor !== null) {
            $builder->andWhere('audit.actor = :actor')
                ->setParameter('actor', $actor);
        }

        return $builder->getQuery()->getResult();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findRecentSummaries(int $limit = 50): array
    {
        $connection = $this->getEntityManager()->getConnection();

        return $connection->fetchAllAssociative(
            <<<'SQL'
                SELECT
                    audit.id,
                    audit.action,
                    audit.created_at,
                    audit.hash_prev,
                    audit.hash_current,
                    SUBSTRING(audit.payload, 1, :payloadLimit) AS payload_preview,
                    actor.email AS actor_email,
                    actor.type AS actor_type
                FROM audit_logs audit
                LEFT JOIN users actor ON actor.id = audit.actor_id
                ORDER BY audit.id DESC
                LIMIT :limit
            SQL,
            [
                'payloadLimit' => self::PAYLOAD_PREVIEW_LENGTH,
                'limit' => $limit,
            ],
            [
                'payloadLimit' => ParameterType::INTEGER,
                'limit' => ParameterType::INTEGER,
            ]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findRecentActivitySummaries(int $limit = 40): array
    {
        return $this->findRecentSummaries($limit);
    }
}
