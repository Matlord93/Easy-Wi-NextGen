<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\VoiceNode;
use App\Module\Core\Domain\Entity\VoiceRateLimitState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class VoiceRateLimitStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VoiceRateLimitState::class);
    }

    public function findOneByNodeAndProvider(VoiceNode $node, string $providerType): ?VoiceRateLimitState
    {
        return $this->findOneBy(['node' => $node, 'providerType' => $providerType]);
    }

    public function pruneOlderThan(\DateTimeImmutable $threshold): int
    {
        return $this->createQueryBuilder('state')
            ->delete()
            ->andWhere('state.updatedAt < :threshold')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->execute();
    }
}
