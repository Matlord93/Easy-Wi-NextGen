<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Domain;
use App\Module\Core\Domain\Entity\MailRateLimit;
use App\Module\Core\Domain\Entity\Mailbox;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class MailRateLimitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailRateLimit::class);
    }

    public function findOneByMailbox(Mailbox $mailbox): ?MailRateLimit
    {
        return $this->findOneBy(['mailbox' => $mailbox]);
    }

    /** @return MailRateLimit[] */
    public function findByDomain(?Domain $domain, int $limit = 200): array
    {
        $qb = $this->createQueryBuilder('mrl')
            ->join('mrl.mailbox', 'mb')
            ->join('mb.domain', 'd')
            ->orderBy('mrl.updatedAt', 'DESC')
            ->setMaxResults(max(1, min($limit, 1000)));

        if ($domain !== null) {
            $qb->andWhere('d = :domain')->setParameter('domain', $domain);
        }

        return $qb->getQuery()->getResult();
    }
}
