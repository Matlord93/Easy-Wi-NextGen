<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Template;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\InstanceUpdatePolicy;
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



    public function testControllerDefinesFindBackupDefinitionHelper(): void
    {
        $reflection = new \ReflectionClass(CustomerInstanceSettingsApiController::class);

        self::assertTrue($reflection->hasMethod('findBackupDefinition'));
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



    public function testSummaryReturnsOkAndAutomationDefaultsWhenSchedulesUnavailable(): void
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $this->setEntityId($customer, 10);

        $template = $this->createMock(Template::class);
        $template->method('getGameKey')->willReturn('minecraft');
        $template->method('getInstallResolver')->willReturn(['type' => 'unknown']);

        $instance = $this->createMock(Instance::class);
        $instance->method('getId')->willReturn(7);
        $instance->method('getCustomer')->willReturn($customer);
        $instance->method('getStatus')->willReturn(InstanceStatus::Running);
        $instance->method('getSlots')->willReturn(12);
        $instance->method('getMaxSlots')->willReturn(20);
        $instance->method('isLockSlots')->willReturn(false);
        $instance->method('getTemplate')->willReturn($template);
        $instance->method('getConfigOverrides')->willReturn([]);
        $instance->method('getSetupVars')->willReturn([]);
        $instance->method('getUpdatePolicy')->willReturn(InstanceUpdatePolicy::Manual);
        $instance->method('getLockedVersion')->willReturn(null);
        $instance->method('getCurrentVersion')->willReturn(null);
        $instance->method('getPreviousVersion')->willReturn(null);

        $repo = $this->createMock(InstanceRepository::class);
        $repo->method('find')->with(7)->willReturn($instance);

        $controller = $this->newController($repo);
        $request = Request::create('/api/instances/7/settings', 'GET');
        $request->attributes->set('current_user', $customer);

        $response = $controller->summary($request, 7);
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue((bool) $payload['ok']);
        self::assertSame(false, $payload['data']['automation']['auto_backup']['enabled']);
        self::assertSame('manual', $payload['data']['automation']['auto_backup']['mode']);
        self::assertSame('03:00', $payload['data']['automation']['auto_backup']['time']);
        self::assertSame('04:00', $payload['data']['automation']['auto_restart']['time']);
        self::assertSame('05:00', $payload['data']['automation']['auto_update']['time']);
        self::assertArrayHasKey('request_id', $payload);
    }

    public function testUpdateAutomationRejectsInvalidBackupMode(): void
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $this->setEntityId($customer, 10);

        $instance = $this->createMock(Instance::class);
        $instance->method('getId')->willReturn(7);
        $instance->method('getCustomer')->willReturn($customer);

        $repo = $this->createMock(InstanceRepository::class);
        $repo->method('find')->with(7)->willReturn($instance);

        $controller = $this->newController($repo);

        $request = Request::create('/api/instances/7/settings/automation', 'PATCH', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'automation' => ['auto_backup' => ['mode' => 'broken']],
        ]));
        $request->attributes->set('current_user', $customer);

        $response = $controller->updateAutomation($request, 7);
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(422, $response->getStatusCode());
        self::assertFalse((bool) $payload['ok']);
        self::assertSame('INVALID_INPUT', $payload['error_code']);
    }



    public function testUpdateAutomationRejectsInvalidBackupTime(): void
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $this->setEntityId($customer, 10);

        $instance = $this->createMock(Instance::class);
        $instance->method('getId')->willReturn(7);
        $instance->method('getCustomer')->willReturn($customer);

        $repo = $this->createMock(InstanceRepository::class);
        $repo->method('find')->with(7)->willReturn($instance);

        $controller = $this->newController($repo);

        $request = Request::create('/api/instances/7/settings/automation', 'PATCH', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'automation' => ['auto_backup' => ['enabled' => true, 'mode' => 'auto', 'time' => '25:99']],
        ]));
        $request->attributes->set('current_user', $customer);

        $response = $controller->updateAutomation($request, 7);
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(422, $response->getStatusCode());
        self::assertFalse((bool) $payload['ok']);
        self::assertSame('INVALID_INPUT', $payload['error_code']);
        self::assertArrayHasKey('request_id', $payload);
    }

    public function testUpdateAutomationReturnsConflictWhenAutoUpdateAndVersionLockActive(): void
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $this->setEntityId($customer, 10);

        $instance = $this->createMock(Instance::class);
        $instance->method('getId')->willReturn(7);
        $instance->method('getCustomer')->willReturn($customer);
        $instance->method('getLockedVersion')->willReturn('1.20.4');

        $repo = $this->createMock(InstanceRepository::class);
        $repo->method('find')->with(7)->willReturn($instance);

        $controller = $this->newController($repo);

        $request = Request::create('/api/instances/7/settings/automation', 'PATCH', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'automation' => ['auto_update' => ['enabled' => true]],
        ]));
        $request->attributes->set('current_user', $customer);

        $response = $controller->updateAutomation($request, 7);
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(409, $response->getStatusCode());
        self::assertFalse((bool) $payload['ok']);
        self::assertSame('CONFLICT', $payload['error_code']);
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
