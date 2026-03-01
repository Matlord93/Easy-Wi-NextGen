<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Mailbox;
use App\Module\Core\Domain\Entity\MailUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class MailUserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailUser::class);
    }

    public function findOneByMailbox(Mailbox $mailbox): ?MailUser
    {
        return $this->findOneBy(['mailbox' => $mailbox]);
    }
}
