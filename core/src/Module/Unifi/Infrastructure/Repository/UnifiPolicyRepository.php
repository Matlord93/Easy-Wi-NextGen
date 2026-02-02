<?php

declare(strict_types=1);

namespace App\Module\Unifi\Infrastructure\Repository;

use App\Module\Unifi\Domain\Entity\UnifiPolicy;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UnifiPolicy>
 */
class UnifiPolicyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UnifiPolicy::class);
    }

    public function getPolicy(): UnifiPolicy
    {
        $policy = $this->findOneBy([]);
        if ($policy === null) {
            $policy = new UnifiPolicy();
            $entityManager = $this->getEntityManager();
            $entityManager->persist($policy);
            $entityManager->flush();

            return $policy;
        }

        if (! $policy instanceof UnifiPolicy) {
            throw new \RuntimeException('Unexpected Unifi policy type.');
        }

        return $policy;
    }
}
