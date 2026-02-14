<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Gameserver\UI\Controller\Customer\CustomerInstanceSettingsApiController;
use App\Repository\InstanceRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CustomerInstanceSettingsApiControllerTest extends TestCase
{
    public function testSummaryForbiddenForNonOwnerReturnsEnvelope(): void
    {
        [$controller, $customer] = $this->forbiddenFixture();

        $request = Request::create('/api/instances/7/settings', 'GET');
        $request->attributes->set('current_user', $customer);

        $response = $controller->summary($request, 7);
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse((bool) $payload['ok']);
        self::assertSame('FORBIDDEN', $payload['error_code']);
        self::assertArrayHasKey('request_id', $payload);
    }

    public function testConfigUpdateInvalidPayloadReturns422(): void
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $this->setEntityId($customer, 10);

        $instance = $this->createMock(Instance::class);
        $instance->method('getCustomer')->willReturn($customer);

        $repo = $this->createMock(InstanceRepository::class);
        $repo->method('find')->with(7)->willReturn($instance);

        $controller = $this->newController($repo);
        $request = Request::create('/api/instances/7/configs/server.properties', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([]));
        $request->attributes->set('current_user', $customer);

        $response = $controller->updateConfig($request, 7, 'server.properties');
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(422, $response->getStatusCode());
        self::assertFalse((bool) $payload['ok']);
        self::assertSame('INVALID_INPUT', $payload['error_code']);
    }

    public function testShowConfigUnauthorizedReturnsEnvelope(): void
    {
        $reflection = new \ReflectionClass(CustomerInstanceSettingsApiController::class);
        /** @var CustomerInstanceSettingsApiController $controller */
        $controller = $reflection->newInstanceWithoutConstructor();

        $request = Request::create('/api/instances/7/configs/1', 'GET');
        $request->headers->set('X-Request-ID', 'req-settings-show');

        $response = $controller->showConfig($request, 7, '1');
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(401, $response->getStatusCode());
        self::assertFalse((bool) $payload['ok']);
        self::assertSame('UNAUTHORIZED', $payload['error_code']);
        self::assertSame('req-settings-show', $payload['request_id']);
    }

    public function testHealthReturnsOkForOwner(): void
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $this->setEntityId($customer, 10);

        $instance = $this->createMock(Instance::class);
        $instance->method('getId')->willReturn(7);
        $instance->method('getCustomer')->willReturn($customer);

        $repo = $this->createMock(InstanceRepository::class);
        $repo->method('find')->with(7)->willReturn($instance);

        $controller = $this->newController($repo);

        $request = Request::create('/api/instances/7/settings/health', 'GET');
        $request->attributes->set('current_user', $customer);

        $response = $controller->health($request, 7);
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue((bool) $payload['ok']);
        self::assertSame(7, $payload['data']['instance_id']);
        self::assertArrayHasKey('request_id', $payload);
    }

    /** @return array{0: CustomerInstanceSettingsApiController, 1: User} */
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

        return [$this->newController($repo), $customer];
    }

    private function newController(InstanceRepository $instanceRepo): CustomerInstanceSettingsApiController
    {
        $reflection = new \ReflectionClass(CustomerInstanceSettingsApiController::class);
        /** @var CustomerInstanceSettingsApiController $controller */
        $controller = $reflection->newInstanceWithoutConstructor();

        $prop = $reflection->getProperty('instanceRepository');
        $prop->setAccessible(true);
        $prop->setValue($controller, $instanceRepo);

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
