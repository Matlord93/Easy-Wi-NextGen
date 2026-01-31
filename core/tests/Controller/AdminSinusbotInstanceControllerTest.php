<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\Sinusbot\SinusbotInstanceProvisioner;
use App\Module\Core\Domain\Entity\SinusbotNode;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\PanelAdmin\UI\Controller\Admin\AdminSinusbotInstanceController;
use App\Repository\SinusbotInstanceRepository;
use App\Repository\SinusbotNodeRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class AdminSinusbotInstanceControllerTest extends TestCase
{
    public function testAdminCanCreateInstance(): void
    {
        $admin = new User('admin@example.test', UserType::Admin);
        $customer = new User('customer@example.test', UserType::Customer);

        $node = $this->createMock(SinusbotNode::class);
        $node->method('getId')->willReturn(1);

        $instanceRepo = $this->createMock(SinusbotInstanceRepository::class);
        $instanceRepo->method('findOneBy')->willReturn(null);

        $nodeRepo = $this->createMock(SinusbotNodeRepository::class);
        $nodeRepo->method('find')->with(1)->willReturn($node);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('find')->with(2)->willReturn($customer);

        $entityManager = $this->createMock(EntityManagerInterface::class);

        $provisioner = $this->createMock(SinusbotInstanceProvisioner::class);
        $provisioner->expects($this->once())
            ->method('createInstanceForCustomer')
            ->with($customer, $node, 5, 'botuser');

        $csrfManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfManager->method('isTokenValid')->willReturn(true);

        $controller = new AdminSinusbotInstanceController(
            $instanceRepo,
            $nodeRepo,
            $userRepo,
            $entityManager,
            $provisioner,
            $csrfManager,
        );

        $session = new Session(new MockArraySessionStorage());
        $request = Request::create('/admin/sinusbot/instances/create', 'POST', [
            '_token' => 'csrf-token',
            'node_id' => 1,
            'customer_id' => 2,
            'quota' => 5,
            'username' => 'botuser',
        ]);
        $request->setSession($session);
        $request->attributes->set('current_user', $admin);

        $response = $controller->create($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/sinusbot/nodes/1', $response->headers->get('Location'));
    }
}
