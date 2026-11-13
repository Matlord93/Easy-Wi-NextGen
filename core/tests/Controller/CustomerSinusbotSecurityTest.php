<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Application\Sinusbot\SinusbotInstanceProvisioner;
use App\Module\Core\Domain\Entity\SinusbotInstance;
use App\Module\Core\Domain\Entity\SinusbotNode;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\PanelCustomer\UI\Controller\Customer\CustomerSinusbotController;
use App\Repository\SinusbotInstanceRepository;
use App\Repository\SinusbotNodeRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CustomerSinusbotSecurityTest extends TestCase
{
    public function testCustomerCannotStartForeignInstance(): void
    {
        $customer = new User('owner@example.test', UserType::Customer);
        $otherCustomer = new User('other@example.test', UserType::Customer);
        $this->setEntityId($customer, 1);
        $this->setEntityId($otherCustomer, 2);

        $node = $this->createStub(SinusbotNode::class);
        $instance = new SinusbotInstance($node, $otherCustomer, 'inst-1', 'user', 3, 'stopped');
        $this->setEntityId($instance, 10);

        $instanceRepo = $this->createStub(SinusbotInstanceRepository::class);
        $instanceRepo->method('find')->willReturnCallback(function (mixed ...$stubArguments) use ($instance): mixed {
            $expectedArguments = [10];
            self::assertEquals($expectedArguments, array_slice($stubArguments, 0, count($expectedArguments)));

            return $instance;
        });

        $provisioner = $this->createMock(SinusbotInstanceProvisioner::class);
        $provisioner->expects($this->never())->method('startInstance');

        $csrfManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfManager->method('isTokenValid')->willReturn(true);

        $controller = new CustomerSinusbotController(
            $instanceRepo,
            $this->createStub(SinusbotNodeRepository::class),
            $provisioner,
            $this->createStub(SecretsCrypto::class),
            $csrfManager,
            $this->createStub(UrlGeneratorInterface::class),
            $this->createStub(Environment::class),
            $this->createStub(TranslatorInterface::class),
        );

        $request = Request::create('/customer/infrastructure/sinusbot/instances/10/start', 'POST', ['_token' => 'csrf']);
        $request->attributes->set('current_user', $customer);

        $this->expectException(NotFoundHttpException::class);
        $controller->start($request, 10);
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity, 'id');
        $reflection->setValue($entity, $id);
    }
}
