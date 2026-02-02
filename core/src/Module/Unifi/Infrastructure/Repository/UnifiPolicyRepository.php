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
        if ($policy instanceof UnifiPolicy) {
            return $policy;
        }

        $policy = new UnifiPolicy();
        $this->_em->persist($policy);
        $this->_em->flush();

        return $policy;
    }
}
