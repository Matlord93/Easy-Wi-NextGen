<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Webspace;
use App\Module\Core\Domain\Entity\WebspaceSftpCredential;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WebspaceSftpCredential>
 */
final class WebspaceSftpCredentialRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebspaceSftpCredential::class);
    }

    public function findOneByWebspace(Webspace $webspace): ?WebspaceSftpCredential
    {
        return $this->findOneBy(['webspace' => $webspace]);
    }
}
