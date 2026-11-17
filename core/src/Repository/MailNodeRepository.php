<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\MailNode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * @extends ServiceEntityRepository<MailNode>
 */
#[AsAlias(id: MailNodeRepositoryInterface::class)]
final class MailNodeRepository extends ServiceEntityRepository implements MailNodeRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailNode::class);
    }
}
