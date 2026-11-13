<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Kvm;

use App\Module\Core\Domain\Entity\Agent;
use Doctrine\ORM\EntityManagerInterface;

/** Serialize ownership changes with customer job creation on the same host. */
final readonly class KvmOperationLock
{
    /**
     * Symfony constructs this autowired service.
     *
     * @psalm-api
     */
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function run(Agent $node, callable $operation): mixed
    {
        return $this->em->getConnection()->transactional(function () use ($node, $operation): mixed {
            // A write locks the existing node row on MySQL/MariaDB and obtains
            // SQLite's write lock in tests, without modifying any node data.
            $this->em->getConnection()->executeStatement('UPDATE agents SET id = id WHERE id = ?', [$node->getId()]);

            return $operation();
        });
    }
}
