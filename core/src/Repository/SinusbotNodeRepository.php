<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\SinusbotNode;
use App\Module\Core\Domain\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SinusbotNode>
 */
final class SinusbotNodeRepository extends ServiceEntityRepository implements SinusbotNodeRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SinusbotNode::class);
    }

    /** @return SinusbotNode[] */
    #[\Override]
    public function findSoloByCustomer(User $customer): array
    {
        return $this->findBy(['customer' => $customer, 'instanceMode' => 'solo']);
    }
}
