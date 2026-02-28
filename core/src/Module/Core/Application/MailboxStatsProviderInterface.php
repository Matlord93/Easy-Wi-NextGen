<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Domain;

interface MailboxStatsProviderInterface
{
    public function countByDomain(Domain $domain): int;

    public function sumQuotaByDomainId(int $domainId): int;
}
