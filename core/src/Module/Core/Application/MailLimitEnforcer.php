<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Domain;
use App\Module\Core\Domain\Entity\MailDomain;
use Doctrine\ORM\EntityManagerInterface;


final class MailLimitEnforcer
{
    public function __construct(
        private readonly MailboxStatsProviderInterface $mailboxRepository,
        private readonly EntityManagerInterface $entityManager,
    )
    {
    }

    public function lockDomainForMailboxCreate(Domain $domain): void
    {
        $domainId = $domain->getId();
        if ($domainId === null) {
            return;
        }

        $connection = $this->entityManager->getConnection();
        $mailDomainRows = $connection->fetchFirstColumn('SELECT id FROM mail_domains WHERE domain_id = :domainId FOR UPDATE', [
            'domainId' => $domainId,
        ]);

        if ($mailDomainRows === []) {
            $connection->fetchFirstColumn('SELECT id FROM domains WHERE id = :domainId FOR UPDATE', [
                'domainId' => $domainId,
            ]);
        }
    }

    public function canCreateMailbox(Domain $domain, ?MailDomain $mailDomain, int $requestedQuotaMb): ?string
    {
        if ($mailDomain === null || $mailDomain->getQuotaPolicy() === null) {
            return null;
        }

        $policy = $mailDomain->getQuotaPolicy();
        $domainId = $domain->getId();
        if ($domainId === null) {
            return null;
        }

        $currentCount = $this->mailboxRepository->countByDomain($domain);
        if ($currentCount >= $policy->getMaxAccounts()) {
            return 'Mailbox account limit reached for this domain.';
        }
        if ($requestedQuotaMb > $policy->getMaxMailboxQuotaMb()) {
            return sprintf('Mailbox quota exceeds policy limit (%d MB).', $policy->getMaxMailboxQuotaMb());
        }

        $currentQuota = $this->mailboxRepository->sumQuotaByDomainId($domainId);
        if ($currentQuota + $requestedQuotaMb > $policy->getMaxDomainQuotaMb()) {
            return sprintf('Domain quota limit exceeded (%d MB).', $policy->getMaxDomainQuotaMb());
        }

        return null;
    }
}
