<?php
declare(strict_types=1);
namespace App\Tests\Controller;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\PanelAdmin\UI\Controller\Admin\AdminMailboxPolicyController;
use App\Repository\MailboxRepository;
use App\Repository\MailDomainRepository;
use App\Repository\MailPolicyRepository;
use App\Module\Core\Application\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class AdminMailboxPolicyControllerTest extends TestCase
{
    public function testNonAdmin403(): void { $c=$this->ctrl(); $r=new Request(); $r->attributes->set('current_user', new User('c@x', UserType::Customer)); self::assertSame(403,$c->update($r,1)->getStatusCode()); }
    public function testMissingMailbox404(): void { [$c,$m]=$this->ctrlWithRepo(); $m->method('find')->willReturn(null); $r=$this->adminReq(); self::assertSame(404,$c->update($r,1)->getStatusCode()); }
    public function testNegativeLimits400(): void { [$c,$m]=$this->ctrlWithRepo(); $m->method('find')->willReturn($this->createMock(\App\Module\Core\Domain\Entity\Mailbox::class)); $r=$this->adminReq(['send_limit_hour'=>-1]); self::assertSame(400,$c->update($r,1)->getStatusCode()); }

    private function adminReq(array $data=[]): Request { $r=new Request([],[],[],[],[],[], json_encode($data) ?: ''); $r->initialize([],[],[],[],[],[],json_encode($data) ?: ''); $r->attributes->set('current_user', new User('a@x', UserType::Superadmin)); return $r; }
    private function ctrl(): AdminMailboxPolicyController { return $this->ctrlWithRepo()[0]; }
    private function ctrlWithRepo(): array { $m=$this->createMock(MailboxRepository::class); $p=$this->createMock(MailPolicyRepository::class); $md=$this->createMock(MailDomainRepository::class); $a=$this->createMock(AuditLogger::class); $em=$this->createMock(EntityManagerInterface::class); return [new AdminMailboxPolicyController($m,$p,$md,$a,$em),$m,$p,$em]; }
}
