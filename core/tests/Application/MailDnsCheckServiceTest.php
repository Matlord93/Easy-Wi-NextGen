<?php
declare(strict_types=1);
namespace App\Tests\Application;

use App\Module\Core\Application\DnsResolverInterface;
use App\Module\Core\Application\MailDnsCheckService;
use PHPUnit\Framework\TestCase;

final class MailDnsCheckServiceTest extends TestCase
{
    public function testOverallOkWhenMxSpfDmarcPresent(): void
    {
        $service = new MailDnsCheckService(new class implements DnsResolverInterface {
            public function query(string $host, int $type): array {
                return match (true) {
                    $type === DNS_MX => [['target' => 'mx.example.com']],
                    $host === 'example.com' && $type === DNS_TXT => [['txt' => 'v=spf1 mx -all']],
                    $host === '_dmarc.example.com' && $type === DNS_TXT => [['txt' => 'v=DMARC1; p=none']],
                    default => [],
                };
            }
        });
        $result = $service->check('example.com');
        self::assertTrue($result['overall_ok']);
    }

    public function testOverallFalseWhenDmarcMissing(): void
    {
        $service = new MailDnsCheckService(new class implements DnsResolverInterface {
            public function query(string $host, int $type): array { return $type===DNS_MX ? [['t'=>'x']] : ($host==='example.com'?[['txt'=>'v=spf1 -all']]:[]); }
        });
        self::assertFalse($service->check('example.com')['overall_ok']);
    }

    public function testDkimMissingSelectorMessage(): void
    {
        $service = new MailDnsCheckService(new class implements DnsResolverInterface { public function query(string $host, int $type): array { return []; } });
        $result = $service->check('example.com');
        self::assertFalse($result['checks']['dkim']['ok']);
        self::assertSame('No DKIM key configured', $result['checks']['dkim']['message']);
    }

    public function testDkimOkWhenSelectorRecordPresent(): void
    {
        $service = new MailDnsCheckService(new class implements DnsResolverInterface {
            public function query(string $host, int $type): array { return str_contains($host, 'selector1._domainkey') ? [['txt'=>'k=rsa']] : []; }
        });
        $result = $service->check('example.com', null, 'selector1');
        self::assertTrue($result['checks']['dkim']['ok']);
    }

    public function testOutputContainsNoSensitiveKeywords(): void
    {
        $json = json_encode((new MailDnsCheckService(new class implements DnsResolverInterface { public function query(string $host, int $type): array { return []; } }))->check('example.com')) ?: '';
        foreach (['subject','body','from','to','recipient','sender','password','filename'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, strtolower($json));
        }
    }
}
