<?php
declare(strict_types=1);
namespace App\Tests\Controller;

use App\Module\Core\Application\MailDnsCheckService;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\PanelAdmin\UI\Controller\Admin\AdminMailDnsCheckController;
use App\Repository\MailDomainRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class AdminMailDnsCheckControllerTest extends TestCase
{
    public function testNonAdminGets403(): void
    {
        $c = new AdminMailDnsCheckController($this->createMock(MailDomainRepository::class), $this->createMock(MailDnsCheckService::class));
        $r = new Request(); $r->attributes->set('current_user', new User('c@x', UserType::Customer));
        self::assertSame(403, $c->check($r, 1)->getStatusCode());
    }

    public function testMissingDomainReturns404(): void
    {
        $repo = $this->createMock(MailDomainRepository::class); $repo->method('find')->willReturn(null);
        $c = new AdminMailDnsCheckController($repo, $this->createMock(MailDnsCheckService::class));
        $r = new Request(); $r->attributes->set('current_user', new User('a@x', UserType::Superadmin));
        self::assertSame(404, $c->check($r, 2)->getStatusCode());
    }
}
