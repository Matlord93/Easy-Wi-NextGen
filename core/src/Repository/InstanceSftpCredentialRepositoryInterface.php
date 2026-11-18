<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\InstanceSftpCredential;

/** @extends \App\Repository\EntityRepositoryQueryInterface<InstanceSftpCredential> */
interface InstanceSftpCredentialRepositoryInterface extends \App\Repository\EntityRepositoryQueryInterface
{
    public function findOneByInstance(Instance $instance): ?InstanceSftpCredential;

    /**
     * @param Instance[] $instances
     *
     * @return InstanceSftpCredential[]
     */
    public function findByInstances(array $instances): array;
}
