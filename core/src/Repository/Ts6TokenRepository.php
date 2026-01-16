<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Ts6Token;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Ts6Token>
 */
final class Ts6TokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ts6Token::class);
    }
}
