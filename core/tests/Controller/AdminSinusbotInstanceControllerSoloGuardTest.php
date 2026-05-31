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
use Symfony\Contracts\Translation\TranslatorInterface;

final class AdminSinusbotInstanceControllerSoloGuardTest extends TestCase
{
    private function makeController(
        SinusbotNodeRepository $nodeRepo,
        ?SinusbotInstanceProvisioner $provisioner = null,
    ): AdminSinusbotInstanceController {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $csrfManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfManager->method('isTokenValid')->willReturn(true);

        return new AdminSinusbotInstanceController(
            $this->createMock(SinusbotInstanceRepository::class),
            $nodeRepo,
            $this->createMock(UserRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $provisioner ?? $this->createMock(SinusbotInstanceProvisioner::class),
            $csrfManager,
            $translator,
        );
    }

    public function testCreateInstanceOnSoloNodeIsRejected(): void
    {
        $admin = new User('admin@example.test', UserType::Admin);

        $node = $this->createMock(SinusbotNode::class);
        $node->method('getId')->willReturn(7);
        $node->method('getInstanceMode')->willReturn('solo');

        $nodeRepo = $this->createMock(SinusbotNodeRepository::class);
        $nodeRepo->method('find')->with(7)->willReturn($node);

        $provisioner = $this->createMock(SinusbotInstanceProvisioner::class);
        $provisioner->expects($this->never())->method('createInstanceForCustomer');

        $controller = $this->makeController($nodeRepo, $provisioner);

        $session = new Session(new MockArraySessionStorage());
        $request = Request::create('/admin/sinusbot/instances/create', 'POST', [
            '_token' => 'csrf-token',
            'node_id' => 7,
            'customer_id' => 2,
            'quota' => 5,
        ]);
        $request->setSession($session);
        $request->attributes->set('current_user', $admin);

        $response = $controller->create($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/sinusbot/nodes/7', $response->headers->get('Location'));
        $errors = $session->getFlashBag()->peek('error');
        $this->assertNotEmpty($errors, 'Expected an error flash for solo-mode node');
        $this->assertStringContainsString('admin_sinusbot_solo_no_instances', $errors[0]);
    }

    public function testCreateInstanceOnMultiNodeProceeds(): void
    {
        $admin = new User('admin@example.test', UserType::Admin);
        $customer = new User('customer@example.test', UserType::Customer);

        $node = $this->createMock(SinusbotNode::class);
        $node->method('getId')->willReturn(3);
        $node->method('getInstanceMode')->willReturn('multi');

        $nodeRepo = $this->createMock(SinusbotNodeRepository::class);
        $nodeRepo->method('find')->with(3)->willReturn($node);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('find')->with(5)->willReturn($customer);

        $provisioner = $this->createMock(SinusbotInstanceProvisioner::class);
        $provisioner->expects($this->once())
            ->method('createInstanceForCustomer')
            ->with($customer, $node, 3, null);

        $csrfManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfManager->method('isTokenValid')->willReturn(true);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $controller = new AdminSinusbotInstanceController(
            $this->createMock(SinusbotInstanceRepository::class),
            $nodeRepo,
            $userRepo,
            $this->createMock(EntityManagerInterface::class),
            $provisioner,
            $csrfManager,
            $translator,
        );

        $session = new Session(new MockArraySessionStorage());
        $request = Request::create('/admin/sinusbot/instances/create', 'POST', [
            '_token' => 'csrf-token',
            'node_id' => 3,
            'customer_id' => 5,
            'quota' => 3,
            'username' => '',
        ]);
        $request->setSession($session);
        $request->attributes->set('current_user', $admin);

        $response = $controller->create($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertEmpty($session->getFlashBag()->peek('error'));
    }
}
