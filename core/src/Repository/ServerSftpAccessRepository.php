<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\ServerSftpAccess;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServerSftpAccess>
 */
final class ServerSftpAccessRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServerSftpAccess::class);
    }

    public function findOneByServer(Instance $server): ?ServerSftpAccess
    {
        return $this->findOneBy(['server' => $server]);
    }
}
