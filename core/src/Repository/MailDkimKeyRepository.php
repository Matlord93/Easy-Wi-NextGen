<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Domain;
use App\Module\Core\Domain\Entity\MailDkimKey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class MailDkimKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailDkimKey::class);
    }

    /** @return MailDkimKey[] */
    public function findByDomain(Domain $domain): array
    {
        return $this->findBy(['domain' => $domain], ['createdAt' => 'DESC']);
    }
}
