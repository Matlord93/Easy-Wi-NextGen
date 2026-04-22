<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\DiskEnforcementService;
use App\Module\Core\Application\InstanceDiskStateResolver;
use App\Module\Core\Application\NodeDiskProtectionService;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Enum\InstanceDiskState;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Gameserver\Infrastructure\Client\AgentGameServerClient;
use App\Module\Gameserver\UI\Controller\Customer\CustomerInstanceActionApiController;
use App\Repository\InstanceRepository;
use App\Repository\JobRepository;
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
        $instance->method('getDiskState')->willReturn(InstanceDiskState::Ok);

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
        $instance->method('getDiskState')->willReturn(InstanceDiskState::Ok);

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

    public function testPowerBlockedWhenLifecycleJobRunning(): void
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $this->setEntityId($customer, 10);

        $instance = $this->createMock(Instance::class);
        $instance->method('getCustomer')->willReturn($customer);
        $instance->method('getStatus')->willReturn(InstanceStatus::Stopped);
        $instance->method('getId')->willReturn(7);
        $instance->method('getDiskState')->willReturn(InstanceDiskState::Ok);

        $instanceRepository = $this->createMock(InstanceRepository::class);
        $instanceRepository->method('find')->with(7)->willReturn($instance);

        $settings = $this->createMock(AppSettingsService::class);
        $settings->method('isGameserverStartStopAllowed')->willReturn(true);

        $jobRepository = $this->createMock(JobRepository::class);
        $jobRepository->method('findLatestActiveByTypesAndInstanceId')
            ->willReturnOnConsecutiveCalls(null, new Job('instance.backup.create', ['instance_id' => '7']));

        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects(self::once())->method('log');

        $controller = $this->newControllerWith($instanceRepository, $settings, $jobRepository, $auditLogger, null);
        $request = Request::create('/api/instances/7/power', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['action' => 'start']));
        $request->attributes->set('current_user', $customer);
        $request->headers->set('X-Request-ID', 'req-power-blocked');

        $response = $controller->power($request, 7);
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(409, $response->getStatusCode());
        self::assertFalse((bool) $payload['ok']);
        self::assertSame('POWER_BLOCKED_BY_LIFECYCLE', $payload['error_code']);
    }

    public function testPowerStartReturnsNoopWhenRuntimeAlreadyRunning(): void
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $this->setEntityId($customer, 10);

        $instance = $this->createMock(Instance::class);
        $instance->method('getCustomer')->willReturn($customer);
        $instance->method('getStatus')->willReturn(InstanceStatus::Stopped);
        $instance->method('getId')->willReturn(7);
        $instance->method('getDiskState')->willReturn(InstanceDiskState::Ok);

        $instanceRepository = $this->createMock(InstanceRepository::class);
        $instanceRepository->method('find')->with(7)->willReturn($instance);

        $settings = $this->createMock(AppSettingsService::class);
        $settings->method('isGameserverStartStopAllowed')->willReturn(true);

        $jobRepository = $this->createMock(JobRepository::class);
        $jobRepository->method('findLatestActiveByTypesAndInstanceId')
            ->willReturn(null);

        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects(self::once())->method('log');

        $agentClient = $this->createMock(AgentGameServerClient::class);
        $agentClient->method('getInstanceStatus')->with($instance)->willReturn(['status' => 'running']);

        $controller = $this->newControllerWith($instanceRepository, $settings, $jobRepository, $auditLogger, $agentClient);
        $request = Request::create('/api/instances/7/power', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['action' => 'start']));
        $request->attributes->set('current_user', $customer);
        $request->headers->set('X-Request-ID', 'req-power-noop');

        $response = $controller->power($request, 7);
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue((bool) $payload['ok']);
        self::assertFalse((bool) $payload['data']['transition']);
        self::assertSame('running', $payload['data']['current_state']);
    }

    private function newControllerWith(
        InstanceRepository $instanceRepository,
        AppSettingsService $settings,
        ?JobRepository $jobRepository = null,
        ?AuditLogger $auditLogger = null,
        ?AgentGameServerClient $agentClient = null,
    ): CustomerInstanceActionApiController
    {
        $reflection = new \ReflectionClass(CustomerInstanceActionApiController::class);
        /** @var CustomerInstanceActionApiController $controller */
        $controller = $reflection->newInstanceWithoutConstructor();

        foreach ([
            'instanceRepository' => $instanceRepository,
            'appSettingsService' => $settings,
            'jobRepository' => $jobRepository ?? $this->createMock(JobRepository::class),
            'auditLogger' => $auditLogger ?? $this->createMock(AuditLogger::class),
            'diskEnforcementService' => $this->buildDiskEnforcementService(),
            'agentGameServerClient' => $agentClient,
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

    private function buildDiskEnforcementService(): DiskEnforcementService
    {
        return new DiskEnforcementService(
            new NodeDiskProtectionService(),
            new InstanceDiskStateResolver(),
        );
    }
}
