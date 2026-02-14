<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Gameserver\UI\Controller\Customer\CustomerInstanceActionApiController;
use App\Repository\InstanceRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CustomerInstancePowerApiControllerTest extends TestCase
{
    public function testPowerForbiddenForNonOwnerReturnsEnvelope(): void
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $owner = new User('owner@example.test', UserType::Customer);
        $this->setEntityId($customer, 10);
        $this->setEntityId($owner, 22);

        $instance = $this->createMock(Instance::class);
        $instance->method('getCustomer')->willReturn($owner);

        $instanceRepository = $this->createMock(InstanceRepository::class);
        $instanceRepository->method('find')->with(7)->willReturn($instance);

        $controller = $this->newControllerWith($instanceRepository, $this->createMock(AppSettingsService::class));

        $request = Request::create('/api/instances/7/power', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['action' => 'start']));
        $request->attributes->set('current_user', $customer);
        $request->headers->set('X-Request-ID', 'req-power-forbidden');

        $response = $controller->power($request, 7);
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse((bool) $payload['ok']);
        self::assertSame('FORBIDDEN', $payload['error_code']);
        self::assertSame('req-power-forbidden', $payload['request_id']);
    }

    public function testPowerInvalidActionReturns422Envelope(): void
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $this->setEntityId($customer, 10);

        $instance = $this->createMock(Instance::class);
        $instance->method('getCustomer')->willReturn($customer);

        $instanceRepository = $this->createMock(InstanceRepository::class);
        $instanceRepository->method('find')->with(7)->willReturn($instance);

        $controller = $this->newControllerWith($instanceRepository, $this->createMock(AppSettingsService::class));

        $request = Request::create('/api/instances/7/power', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['action' => 'launch']));
        $request->attributes->set('current_user', $customer);
        $request->headers->set('X-Request-ID', 'req-power-invalid');

        $response = $controller->power($request, 7);
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(422, $response->getStatusCode());
        self::assertFalse((bool) $payload['ok']);
        self::assertSame('INVALID_ACTION', $payload['error_code']);
        self::assertSame('req-power-invalid', $payload['request_id']);
    }

    private function newControllerWith(InstanceRepository $instanceRepository, AppSettingsService $settings): CustomerInstanceActionApiController
    {
        $reflection = new \ReflectionClass(CustomerInstanceActionApiController::class);
        /** @var CustomerInstanceActionApiController $controller */
        $controller = $reflection->newInstanceWithoutConstructor();

        foreach ([
            'instanceRepository' => $instanceRepository,
            'appSettingsService' => $settings,
        ] as $property => $value) {
            $prop = $reflection->getProperty($property);
            $prop->setAccessible(true);
            $prop->setValue($controller, $value);
        }

        return $controller;
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionClass($entity);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }
}
