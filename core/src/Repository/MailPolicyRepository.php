<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Domain;
use App\Module\Core\Domain\Entity\MailPolicy;
use App\Module\Core\Domain\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class MailPolicyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailPolicy::class);
    }

    public function findOneByDomain(Domain $domain): ?MailPolicy
    {
        return $this->findOneBy(['domain' => $domain]);
    }

    /** @return MailPolicy[] */
    public function findByOwner(User $owner): array
    {
        return $this->findBy(['owner' => $owner], ['updatedAt' => 'DESC']);
    }

    /**
     * @return array{total:int,strict_tls:int,external_forwarding_enabled:int,high_spam_protection:int,greylisting_enabled:int}
     */
    public function countSecurityPosture(): array
    {
        $result = $this->createQueryBuilder('p')
            ->select('COUNT(p.id) AS total')
            ->addSelect('SUM(CASE WHEN p.requireTls = true THEN 1 ELSE 0 END) AS strict_tls')
            ->addSelect('SUM(CASE WHEN p.allowExternalForwarding = true THEN 1 ELSE 0 END) AS external_forwarding_enabled')
            ->addSelect("SUM(CASE WHEN p.spamProtectionLevel = 'high' THEN 1 ELSE 0 END) AS high_spam_protection")
            ->addSelect('SUM(CASE WHEN p.greylistingEnabled = true THEN 1 ELSE 0 END) AS greylisting_enabled')
            ->getQuery()
            ->getSingleResult();

        return [
            'total' => (int) ($result['total'] ?? 0),
            'strict_tls' => (int) ($result['strict_tls'] ?? 0),
            'external_forwarding_enabled' => (int) ($result['external_forwarding_enabled'] ?? 0),
            'high_spam_protection' => (int) ($result['high_spam_protection'] ?? 0),
            'greylisting_enabled' => (int) ($result['greylisting_enabled'] ?? 0),
        ];
    }
}
