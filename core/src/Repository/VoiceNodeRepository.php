<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\VoiceNode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class VoiceNodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VoiceNode::class);
    }

    /**
     * @return array{items: VoiceNode[], total: int, page: int, per_page: int}
     */
    public function findPaginated(int $page, int $perPage, ?string $providerType = null, ?bool $enabled = null): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));

        $qb = $this->createQueryBuilder('node');
        if (is_string($providerType) && $providerType !== '') {
            $qb->andWhere('node.providerType = :providerType')->setParameter('providerType', $providerType);
        }
        if (is_bool($enabled)) {
            $qb->andWhere('node.enabled = :enabled')->setParameter('enabled', $enabled);
        }

        $items = (clone $qb)
            ->orderBy('node.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        $total = (int) (clone $qb)
            ->select('COUNT(node.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }
}
