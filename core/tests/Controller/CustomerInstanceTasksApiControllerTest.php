<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Gameserver\UI\Controller\Customer\CustomerInstanceActionApiController;
use App\Repository\InstanceRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CustomerInstanceTasksApiControllerTest extends TestCase
{
    public function testListForbiddenForNonOwnerReturnsEnvelope(): void
    {
        [$controller, $customer] = $this->forbiddenFixture();

        $request = Request::create('/api/instances/7/tasks', 'GET');
        $request->attributes->set('current_user', $customer);

        $response = $controller->listTasksEnvelope($request, 7);
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse((bool) $payload['ok']);
        self::assertSame('FORBIDDEN', $payload['error_code']);
        self::assertArrayHasKey('request_id', $payload);
    }

    public function testScheduleInvalidPayloadReturns422(): void
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $this->setEntityId($customer, 10);

        $instance = $this->createMock(Instance::class);
        $instance->method('getCustomer')->willReturn($customer);

        $repo = $this->createMock(InstanceRepository::class);
        $repo->method('find')->with(7)->willReturn($instance);

        $controller = $this->newControllerWithRepo($repo);
        $request = Request::create('/api/instances/7/schedules', 'PATCH', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['action' => 'invalid']));
        $request->attributes->set('current_user', $customer);

        $response = $controller->updateScheduleEnvelope($request, 7);
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(422, $response->getStatusCode());
        self::assertFalse((bool) $payload['ok']);
        self::assertSame('INVALID_INPUT', $payload['error_code']);
    }

    public function testTasksHealthReturnsOkForOwner(): void
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $this->setEntityId($customer, 10);

        $instance = $this->createMock(Instance::class);
        $instance->method('getId')->willReturn(7);
        $instance->method('getCustomer')->willReturn($customer);
        $instance->method('getStatus')->willReturn(InstanceStatus::Running);

        $repo = $this->createMock(InstanceRepository::class);
        $repo->method('find')->with(7)->willReturn($instance);

        $controller = $this->newControllerWithRepo($repo);

        $request = Request::create('/api/instances/7/tasks/health', 'GET');
        $request->attributes->set('current_user', $customer);

        $response = $controller->tasksHealthEnvelope($request, 7);
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue((bool) $payload['ok']);
        self::assertSame(7, $payload['data']['instance_id']);
        self::assertArrayHasKey('request_id', $payload);
    }

    /** @return array{0: CustomerInstanceActionApiController, 1: User} */
    private function forbiddenFixture(): array
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $owner = new User('owner@example.test', UserType::Customer);
        $this->setEntityId($customer, 10);
        $this->setEntityId($owner, 20);

        $instance = $this->createMock(Instance::class);
        $instance->method('getCustomer')->willReturn($owner);

        $repo = $this->createMock(InstanceRepository::class);
        $repo->method('find')->with(7)->willReturn($instance);

        return [$this->newControllerWithRepo($repo), $customer];
    }

    private function newControllerWithRepo(InstanceRepository $repo): CustomerInstanceActionApiController
    {
        $reflection = new \ReflectionClass(CustomerInstanceActionApiController::class);
        /** @var CustomerInstanceActionApiController $controller */
        $controller = $reflection->newInstanceWithoutConstructor();

        $prop = $reflection->getProperty('instanceRepository');
        $prop->setAccessible(true);
        $prop->setValue($controller, $repo);

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
