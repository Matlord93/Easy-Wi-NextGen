<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\MailNode;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\PanelAdmin\Application\MailNodeHealthAggregator;
use App\Module\PanelAdmin\Application\MailNodeMetricsAggregator;
use App\Module\PanelAdmin\UI\Controller\Admin\AdminMailNodeHealthController;
use App\Repository\MailDomainRepository;
use App\Repository\MailNodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class AdminMailNodeHealthControllerTest extends TestCase
{
    public function testNonAdminGetsForbidden(): void { $c=$this->controller(); $r=new Request(); $r->attributes->set('current_user', new User('c@x', UserType::Customer)); self::assertSame(403,$c->repair(1,$r)->getStatusCode()); }
    public function testRepairReturns404ForMissingNode(): void { [$c,$nodeRepo]=$this->controllerWithRepos(); $nodeRepo->method('find')->willReturn(null); $r=$this->adminReq(); self::assertSame(404,$c->repair(99,$r)->getStatusCode()); }
    public function testRepairReturns400WithoutAgent(): void { [$c,$nodeRepo,$domainRepo]=$this->controllerWithRepos(); $nodeRepo->method('find')->willReturn(new MailNode('n','i',1,'s',2,'r')); $domainRepo->method('findBy')->willReturn([]); $r=$this->adminReq(); self::assertSame(400,$c->repair(1,$r)->getStatusCode()); }

    public function testRoundcubeDeployReturns400WithoutAgent(): void { [$c,$nodeRepo,$domainRepo]=$this->controllerWithRepos(); $nodeRepo->method('find')->willReturn(new MailNode('n','i',993,'s',587,'https://webmail')); $domainRepo->method('findBy')->willReturn([]); $r=$this->adminReq(); self::assertSame(400,$c->roundcubeAction(1,'deploy',$r)->getStatusCode()); }
    public function testRoundcubeActionRejectsNonAdmin(): void { $c=$this->controller(); $r=new Request(); $r->attributes->set('current_user', new User('c@x', UserType::Customer)); self::assertSame(403,$c->roundcubeAction(1,'install',$r)->getStatusCode()); }

    private function adminReq(): Request { $r=new Request(); $r->headers->set('X-Admin-Request', '1'); $r->attributes->set('current_user', new User('a@x', UserType::Superadmin)); return $r; }
    private function controller(): AdminMailNodeHealthController { return $this->controllerWithRepos()[0]; }
    private function controllerWithRepos(): array {
        $nodeRepo=$this->createMock(MailNodeRepository::class); $domainRepo=$this->createMock(MailDomainRepository::class);
        $em=$this->createMock(EntityManagerInterface::class); $audit=$this->createMock(AuditLogger::class);
        return [new AdminMailNodeHealthController($nodeRepo,$domainRepo,$this->createMock(MailNodeHealthAggregator::class),$this->createMock(MailNodeMetricsAggregator::class),$em,$audit),$nodeRepo,$domainRepo];
    }
}
