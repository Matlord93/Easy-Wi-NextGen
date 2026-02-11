<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\ForumMemberBan;
use App\Module\Core\Domain\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class ForumMemberBanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumMemberBan::class);
    }

    public function findActiveForUser(User $user): ?ForumMemberBan
    {
        $ban = $this->findOneBy(['user' => $user]);
        if (!$ban instanceof ForumMemberBan || !$ban->isActive()) {
            return null;
        }

        return $ban;
    }
}
