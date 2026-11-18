<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Application\MailboxStatsProviderInterface;
use App\Module\Core\Domain\Entity\Mailbox;
use App\Module\Core\Domain\Entity\User;

/** @extends \App\Repository\EntityRepositoryQueryInterface<Mailbox> */
interface MailboxRepositoryInterface extends MailboxStatsProviderInterface, \App\Repository\EntityRepositoryQueryInterface
{
    /**
     * @return Mailbox[]
     */
    public function findByCustomer(User $customer): array;

    public function findOneByAddress(string $address): ?Mailbox;
}
