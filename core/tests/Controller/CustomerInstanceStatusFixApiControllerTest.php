<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Gameserver\Infrastructure\Client\AgentGameServerClient;
use App\Module\Gameserver\UI\Controller\Customer\CustomerInstanceActionApiController;
use App\Repository\InstanceRepository;
use App\Repository\JobRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CustomerInstanceStatusFixApiControllerTest extends TestCase
{
    public function testFixStatusSynchronizesDatabaseStatusWithAgentRuntime(): void
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $this->setEntityId($customer, 42);

        $status = InstanceStatus::Stopped;
        $instance = $this->createMock(Instance::class);
        $instance->method('getCustomer')->willReturn($customer);
        $instance->method('getId')->willReturn(7);
        $instance->method('getStatus')->willReturnCallback(function () use (&$status): InstanceStatus {
            return $status;
        });
        $instance->method('setStatus')->willReturnCallback(function (InstanceStatus $newStatus) use (&$status): void {
            $status = $newStatus;
        });

        $instanceRepository = $this->createMock(InstanceRepository::class);
        $instanceRepository->method('find')->with(7)->willReturn($instance);

        $jobRepository = $this->createMock(JobRepository::class);
        $jobRepository->method('findLatestActiveByTypesAndInstanceId')->willReturn(null);

        $agentClient = $this->createMock(AgentGameServerClient::class);
        $agentClient->method('getInstanceStatus')->with($instance)->willReturn(['status' => 'running']);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with($instance);
        $entityManager->expects(self::once())->method('flush');

        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects(self::once())->method('log');

        $controller = $this->newController($instanceRepository, $jobRepository, $agentClient, $entityManager, $auditLogger);
        $request = Request::create('/api/instances/7/status/fix', 'POST');
        $request->attributes->set('current_user', $customer);
        $request->headers->set('X-Request-ID', 'req-status-fix-ok');

        $response = $controller->fixStatus($request, 7);
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue((bool) $payload['ok']);
        self::assertSame('running', $payload['data']['status']);
        self::assertSame('running', $payload['data']['runtime_status']);
        self::assertTrue((bool) $payload['data']['changed']);
        self::assertSame('req-status-fix-ok', $payload['request_id']);
    }

    public function testFixStatusReturnsConflictWhenLifecycleJobIsActive(): void
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $this->setEntityId($customer, 42);

        $instance = $this->createMock(Instance::class);
        $instance->method('getCustomer')->willReturn($customer);
        $instance->method('getId')->willReturn(7);

        $instanceRepository = $this->createMock(InstanceRepository::class);
        $instanceRepository->method('find')->with(7)->willReturn($instance);

        $activeJob = new Job('instance.start', ['instance_id' => '7']);
        $jobRepository = $this->createMock(JobRepository::class);
        $jobRepository->method('findLatestActiveByTypesAndInstanceId')->willReturn($activeJob);

        $agentClient = $this->createMock(AgentGameServerClient::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $auditLogger = $this->createMock(AuditLogger::class);

        $controller = $this->newController($instanceRepository, $jobRepository, $agentClient, $entityManager, $auditLogger);
        $request = Request::create('/api/instances/7/status/fix', 'POST');
        $request->attributes->set('current_user', $customer);
        $request->headers->set('X-Request-ID', 'req-status-fix-busy');

        $response = $controller->fixStatus($request, 7);
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(409, $response->getStatusCode());
        self::assertFalse((bool) $payload['ok']);
        self::assertSame('STATUS_FIX_BLOCKED', $payload['error_code']);
        self::assertSame('req-status-fix-busy', $payload['request_id']);
    }

    private function newController(
        InstanceRepository $instanceRepository,
        JobRepository $jobRepository,
        AgentGameServerClient $agentClient,
        EntityManagerInterface $entityManager,
        AuditLogger $auditLogger,
    ): CustomerInstanceActionApiController {
        $reflection = new \ReflectionClass(CustomerInstanceActionApiController::class);
        /** @var CustomerInstanceActionApiController $controller */
        $controller = $reflection->newInstanceWithoutConstructor();

        foreach ([
            'instanceRepository' => $instanceRepository,
            'jobRepository' => $jobRepository,
            'agentGameServerClient' => $agentClient,
            'entityManager' => $entityManager,
            'auditLogger' => $auditLogger,
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
