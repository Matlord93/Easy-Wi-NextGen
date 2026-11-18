<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Domain;
use App\Module\Core\Domain\Entity\MailDomain;

/** @extends \App\Repository\EntityRepositoryQueryInterface<MailDomain> */
interface MailDomainRepositoryInterface extends \App\Repository\EntityRepositoryQueryInterface
{
    public function findOneByDomain(Domain $domain): ?MailDomain;

    public function findOneByDomainName(string $domainName): ?MailDomain;
}
