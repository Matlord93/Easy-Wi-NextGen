<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Database;
use App\Module\Core\Domain\Entity\Job;

interface DatabaseProvisioningServiceInterface
{
    /** @return Job[] */
    public function buildCreateJobs(Database $database, string $agentId): array;

    public function buildPasswordRotateJob(Database $database, string $agentId): Job;

    public function buildDeleteJob(Database $database, string $agentId): Job;
}
