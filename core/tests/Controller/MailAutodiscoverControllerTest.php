<?php
declare(strict_types=1);
namespace App\Tests\Controller;

use App\Module\Core\Domain\Entity\Domain;
use App\Module\Core\Domain\Entity\MailDomain;
use App\Module\Core\Domain\Entity\MailNode;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\PanelCustomer\UI\Controller\Public\MailAutodiscoverController;
use App\Repository\MailDomainRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class MailAutodiscoverControllerTest extends TestCase
{
    public function testAutoconfigReturns404ForUnknownDomain(): void
    {
        $repo = $this->createMock(MailDomainRepository::class);
        $repo->method('findOneByDomainName')->willReturn(null);
        $controller = new MailAutodiscoverController($repo);
        $response = $controller->autoconfig(new Request(['emailaddress' => 'u@unknown.test']));
        self::assertSame(404, $response->getStatusCode());
    }

    public function testAutoconfigContainsImapPop3AndSmtp(): void
    {
        $customer = new User('c@example.com', UserType::Customer);
        $domain = new Domain($customer, null, 'example.com');
        $node = new MailNode('n', 'imap.example.com', 993, 'smtp.example.com', 587, 'https://webmail.example.com');
        $mailDomain = new MailDomain($domain, $node);

        $repo = $this->createMock(MailDomainRepository::class);
        $repo->method('findOneByDomainName')->willReturn($mailDomain);
        $controller = new MailAutodiscoverController($repo);
        $response = $controller->autoconfig(new Request(['emailaddress' => 'u@example.com']));

        self::assertSame(200, $response->getStatusCode());
        $xml = (string) $response->getContent();
        self::assertStringContainsString('<incomingServer type="imap">', $xml);
        self::assertStringContainsString('<incomingServer type="pop3">', $xml);
        self::assertStringContainsString('<outgoingServer type="smtp">', $xml);
        self::assertStringNotContainsString('password>', $xml);
    }
}
