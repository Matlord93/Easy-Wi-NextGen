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

final class CustomerInstanceBackupsApiControllerTest extends TestCase
{
    public function testListForbiddenForNonOwnerReturnsEnvelope(): void
    {
        [$controller, $customer] = $this->forbiddenFixture();
        $request = Request::create('/api/instances/7/backups', 'GET');
        $request->attributes->set('current_user', $customer);

        $response = $controller->listBackups($request, 7);
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse((bool) $payload['ok']);
        self::assertSame('FORBIDDEN', $payload['error_code']);
    }

    public function testCreateForbiddenForNonOwnerReturnsEnvelope(): void
    {
        [$controller, $customer] = $this->forbiddenFixture();
        $request = Request::create('/api/instances/7/backups', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([]));
        $request->attributes->set('current_user', $customer);

        $response = $controller->createBackup($request, 7);
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse((bool) $payload['ok']);
        self::assertSame('FORBIDDEN', $payload['error_code']);
    }

    public function testRestoreForbiddenForNonOwnerReturnsEnvelope(): void
    {
        [$controller, $customer] = $this->forbiddenFixture();
        $request = Request::create('/api/instances/7/backups/11/restore', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['confirm' => true]));
        $request->attributes->set('current_user', $customer);

        $response = $controller->restoreBackup($request, 7, 11);
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse((bool) $payload['ok']);
        self::assertSame('FORBIDDEN', $payload['error_code']);
    }

    public function testDeleteForbiddenForNonOwnerReturnsEnvelope(): void
    {
        [$controller, $customer] = $this->forbiddenFixture();
        $request = Request::create('/api/instances/7/backups/11', 'DELETE');
        $request->attributes->set('current_user', $customer);

        $response = $controller->deleteBackup($request, 7, 11);
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse((bool) $payload['ok']);
        self::assertSame('FORBIDDEN', $payload['error_code']);
    }

    public function testRestoreInvalidPayloadReturns422(): void
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $this->setEntityId($customer, 10);

        $instance = $this->createMock(Instance::class);
        $instance->method('getCustomer')->willReturn($customer);

        $repo = $this->createMock(InstanceRepository::class);
        $repo->method('find')->with(7)->willReturn($instance);

        $controller = $this->newControllerWithRepo($repo);
        $request = Request::create('/api/instances/7/backups/11/restore', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['confirm' => false]));
        $request->attributes->set('current_user', $customer);

        $response = $controller->restoreBackup($request, 7, 11);
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(422, $response->getStatusCode());
        self::assertFalse((bool) $payload['ok']);
        self::assertSame('INVALID_INPUT', $payload['error_code']);
    }

    public function testHealthReturnsOkForOwner(): void
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
        $request = Request::create('/api/instances/7/backups/health', 'GET');
        $request->attributes->set('current_user', $customer);

        $response = $controller->backupsHealth($request, 7);
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue((bool) $payload['ok']);
        self::assertSame(7, $payload['data']['instance_id']);
        self::assertTrue((bool) $payload['data']['backup_supported']);
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
