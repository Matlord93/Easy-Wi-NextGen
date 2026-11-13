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

final class AdminSinusbotInstanceControllerTest extends TestCase
{
    public function testAdminCanCreateInstance(): void
    {
        $admin = new User('admin@example.test', UserType::Admin);
        $customer = new User('customer@example.test', UserType::Customer);

        $node = $this->createStub(SinusbotNode::class);
        $node->method('getId')->willReturn(1);

        $instanceRepo = $this->createStub(SinusbotInstanceRepository::class);

        $nodeRepo = $this->createStub(SinusbotNodeRepository::class);
        $nodeRepo->method('find')->willReturnCallback(function (mixed ...$stubArguments) use ($node): mixed {
            $expectedArguments = [1];
            self::assertEquals($expectedArguments, array_slice($stubArguments, 0, count($expectedArguments)));

            return $node;
        });

        $userRepo = $this->createStub(UserRepository::class);
        $userRepo->method('find')->willReturnCallback(function (mixed ...$stubArguments) use ($customer): mixed {
            $expectedArguments = [2];
            self::assertEquals($expectedArguments, array_slice($stubArguments, 0, count($expectedArguments)));

            return $customer;
        });

        $entityManager = $this->createStub(EntityManagerInterface::class);

        $provisioner = $this->createMock(SinusbotInstanceProvisioner::class);
        $provisioner->expects($this->once())
            ->method('createInstanceForCustomer')
            ->with($customer, $node, 5, 'botuser');

        $csrfManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfManager->method('isTokenValid')->willReturn(true);

        $controller = new AdminSinusbotInstanceController(
            $instanceRepo,
            $nodeRepo,
            $userRepo,
            $entityManager,
            $provisioner,
            $csrfManager,
            $this->createStub(TranslatorInterface::class),
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

    public function testAdminCanCreateAdditionalInstanceForSameCustomer(): void
    {
        $admin = new User('admin@example.test', UserType::Admin);
        $customer = new User('customer@example.test', UserType::Customer);

        $node = $this->createStub(SinusbotNode::class);
        $node->method('getId')->willReturn(1);

        $instanceRepo = $this->createMock(SinusbotInstanceRepository::class);
        $instanceRepo->expects($this->never())->method('findOneBy');

        $nodeRepo = $this->createStub(SinusbotNodeRepository::class);
        $nodeRepo->method('find')->willReturnCallback(function (mixed ...$stubArguments) use ($node): mixed {
            $expectedArguments = [1];
            self::assertEquals($expectedArguments, array_slice($stubArguments, 0, count($expectedArguments)));

            return $node;
        });

        $userRepo = $this->createStub(UserRepository::class);
        $userRepo->method('find')->willReturnCallback(function (mixed ...$stubArguments) use ($customer): mixed {
            $expectedArguments = [2];
            self::assertEquals($expectedArguments, array_slice($stubArguments, 0, count($expectedArguments)));

            return $customer;
        });

        $entityManager = $this->createStub(EntityManagerInterface::class);

        $provisioner = $this->createMock(SinusbotInstanceProvisioner::class);
        $provisioner->expects($this->once())
            ->method('createInstanceForCustomer')
            ->with($customer, $node, 3, null);

        $csrfManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfManager->method('isTokenValid')->willReturn(true);

        $controller = new AdminSinusbotInstanceController(
            $instanceRepo,
            $nodeRepo,
            $userRepo,
            $entityManager,
            $provisioner,
            $csrfManager,
            $this->createStub(TranslatorInterface::class),
        );

        $session = new Session(new MockArraySessionStorage());
        $request = Request::create('/admin/sinusbot/instances/create', 'POST', [
            '_token' => 'csrf-token',
            'node_id' => 1,
            'customer_id' => 2,
            'quota' => 3,
            'username' => '',
        ]);
        $request->setSession($session);
        $request->attributes->set('current_user', $admin);

        $response = $controller->create($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/sinusbot/nodes/1', $response->headers->get('Location'));
        $this->assertSame([], $session->getFlashBag()->peek('error'));
    }

}
