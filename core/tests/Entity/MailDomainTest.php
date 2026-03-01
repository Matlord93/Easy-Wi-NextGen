<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Module\Core\Domain\Entity\Domain;
use App\Module\Core\Domain\Entity\MailDomain;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\MailNode;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\Webspace;
use App\Module\Core\Domain\Enum\UserType;
use PHPUnit\Framework\TestCase;

final class MailDomainTest extends TestCase
{
    public function testNormalizesDomainToLowercaseAscii(): void
    {
        self::assertSame('xn--bcher-kva.de', MailDomain::normalizeDomainName('BÜCHER.de'));
    }

    public function testRejectsInvalidDomain(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MailDomain::normalizeDomainName('not a domain');
    }

    public function testConstructorAssignsTenantOwnerAndDomainName(): void
    {
        $mailDomain = $this->createMailDomain('Kunde.DE');

        self::assertSame('kunde.de', $mailDomain->getDomainName());
        self::assertSame('owner@example.test', $mailDomain->getOwner()->getEmail());
        self::assertTrue($mailDomain->isMailEnabled());
    }

    public function testRotateDkimKeySwitchesSelectorAndStatus(): void
    {
        $mailDomain = $this->createMailDomain();

        $mailDomain->rotateDkimKey('mail202611');

        self::assertSame('mail202611', $mailDomain->getDkimSelector());
        self::assertSame(MailDomain::STATUS_WARNING, $mailDomain->getDkimStatus());
    }

    public function testDnsStatusNormalizationFallsBackToUnknownAndPolicyToQuarantine(): void
    {
        $mailDomain = $this->createMailDomain();

        $mailDomain->markDnsStatus('ok', 'weird', 'warning', 'error', 'invalid', 'bad-policy');

        self::assertSame('ok', $mailDomain->getDkimStatus());
        self::assertSame('unknown', $mailDomain->getSpfStatus());
        self::assertSame('warning', $mailDomain->getDmarcStatus());
        self::assertSame('error', $mailDomain->getMxStatus());
        self::assertSame('unknown', $mailDomain->getTlsStatus());
        self::assertSame('quarantine', $mailDomain->getDmarcPolicy());
        self::assertNotNull($mailDomain->getDnsLastCheckedAt());
    }

    private function createMailDomain(string $domainName = 'example.com'): MailDomain
    {
        $owner = new User('owner@example.test', UserType::Customer);
        $agent = new Agent('node-1', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c'], 'mail-node-1');
        $webspace = new Webspace($owner, $agent, '/srv/www/ws-01', 'public', strtolower($domainName), '8.4', 1024);
        $domain = new Domain($owner, $webspace, $domainName);
        $mailNode = new MailNode('mail-1', 'imap.example.test', 993, 'smtp.example.test', 587, 'https://webmail.example.test');

        return new MailDomain($domain, $mailNode);
    }
}
