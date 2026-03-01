<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Module\Core\Application\MailDkimKeyGenerator;
use PHPUnit\Framework\TestCase;

final class MailDkimKeyGeneratorTest extends TestCase
{
    public function testBuildDnsMaterialFromPublicPem(): void
    {
        $generator = new MailDkimKeyGenerator();

        $material = $generator->buildDnsMaterial(
            'Example.COM',
            "-----BEGIN PUBLIC KEY-----\nQUJDREVGR0g=\n-----END PUBLIC KEY-----\n",
            'mail202610'
        );

        self::assertSame('mail202610', $material['selector']);
        self::assertSame('mail202610._domainkey.example.com', $material['dns_name']);
        self::assertSame('v=DKIM1; k=rsa; p=QUJDREVGR0g=', $material['dns_value']);
        self::assertSame(hash('sha256', 'QUJDREVGR0g='), $material['fingerprint_sha256']);
    }

    public function testGenerateSelectorUsesExpectedFormat(): void
    {
        $generator = new MailDkimKeyGenerator();
        $selector = $generator->generateSelector(new \DateTimeImmutable('2026-10-05T10:00:00+00:00'));

        self::assertSame('mail202610', $selector);
    }

    public function testInvalidPublicKeyThrows(): void
    {
        $generator = new MailDkimKeyGenerator();

        $this->expectException(\InvalidArgumentException::class);
        $generator->buildDnsMaterial('example.com', "-----BEGIN PUBLIC KEY-----\n!!!!\n-----END PUBLIC KEY-----");
    }
}
