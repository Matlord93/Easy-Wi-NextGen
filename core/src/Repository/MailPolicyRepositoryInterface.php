<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Domain;
use App\Module\Core\Domain\Entity\MailPolicy;

interface MailPolicyRepositoryInterface
{
    public function findOneByDomain(Domain $domain): ?MailPolicy;

    /** @return array{total:int,strict_tls:int,external_forwarding_enabled:int,high_spam_protection:int,greylisting_enabled:int} */
    public function countSecurityPosture(): array;
}
