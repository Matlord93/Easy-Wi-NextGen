<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application;

use App\Module\Core\Application\MailLimitEnforcer;
use App\Module\Core\Domain\Entity\Domain;
use App\Module\Core\Domain\Entity\MailDomain;
use App\Module\Core\Domain\Entity\QuotaPolicy;
use App\Module\Core\Application\MailboxStatsProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class MailLimitEnforcerTest extends TestCase
{
    public function testBlocksWhenAccountLimitIsReached(): void
    {
        $repo = $this->createMock(MailboxStatsProviderInterface::class);
        $repo->method('countByDomain')->willReturn(2);
        $repo->method('sumQuotaByDomainId')->willReturn(1000);

        $enforcer = new MailLimitEnforcer($repo, $this->createMock(EntityManagerInterface::class));
        $domain = $this->mockDomain(10);
        $mailDomain = $this->mockMailDomain(new QuotaPolicy('basic', 2, 5000, 2048));

        $error = $enforcer->canCreateMailbox($domain, $mailDomain, 100);
        self::assertSame('Mailbox account limit reached for this domain.', $error);
    }

    public function testAllowsCreationWithinLimits(): void
    {
        $repo = $this->createMock(MailboxStatsProviderInterface::class);
        $repo->method('countByDomain')->willReturn(1);
        $repo->method('sumQuotaByDomainId')->willReturn(512);

        $enforcer = new MailLimitEnforcer($repo, $this->createMock(EntityManagerInterface::class));
        $domain = $this->mockDomain(10);
        $mailDomain = $this->mockMailDomain(new QuotaPolicy('pro', 10, 10000, 4096));

        $error = $enforcer->canCreateMailbox($domain, $mailDomain, 1024);
        self::assertNull($error);
    }

    private function mockDomain(int $id): Domain
    {
        $domain = $this->createMock(Domain::class);
        $domain->method('getId')->willReturn($id);

        return $domain;
    }

    private function mockMailDomain(QuotaPolicy $policy): MailDomain
    {
        $mailDomain = $this->createMock(MailDomain::class);
        $mailDomain->method('getQuotaPolicy')->willReturn($policy);

        return $mailDomain;
    }
}
