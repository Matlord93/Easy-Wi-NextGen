<?php

declare(strict_types=1);

namespace App\Tests\Gameserver;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\AuditLogHasher;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Core\UI\Api\ResponseEnvelopeFactory;
use App\Module\Gameserver\Application\Console\ConsoleAgentGrpcClientInterface;
use App\Module\Gameserver\Application\Console\ConsoleCommandLimiterInterface;
use App\Module\Gameserver\Application\Console\ConsoleCommandResult;
use App\Module\Gameserver\UI\Controller\Customer\CustomerInstanceConsoleCommandController;
use App\Repository\AuditLogRepository;
use App\Repository\InstanceRepository;
use App\Repository\JobRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class CustomerInstanceConsoleCommandControllerTest extends TestCase
{
    public function testUnauthorizedReturns403(): void
    {
        [$controller, $request] = $this->buildControllerAndRequest(false, true, true);
        $response = $controller->send($request, 99);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testRateLimitReturns429(): void
    {
        [$controller, $request] = $this->buildControllerAndRequest(true, false, true);
        $response = $controller->send($request, 99);

        self::assertSame(429, $response->getStatusCode());
    }

    public function testCsrfRequiredForCookieAuth(): void
    {
        [$controller, $request] = $this->buildControllerAndRequest(true, true, false);
        $request->cookies->set('EASYWISESSID', 'cookie-session');

        $response = $controller->send($request, 99);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testHappyPathCallsGrpcAndReturnsAccepted(): void
    {
        [$controller, $request, $grpc] = $this->buildControllerAndRequest(true, true, true);

        $grpc->expects(self::once())->method('sendCommand')->willReturn(new ConsoleCommandResult(true, false, 10));
        $response = $controller->send($request, 99);

        self::assertSame(202, $response->getStatusCode());
    }

    public function testLifecycleConflictReturns409(): void
    {
        [$controller, $request] = $this->buildControllerAndRequest(true, true, true, new Job('instance.backup.restore', ['instance_id' => '99']));
        $response = $controller->send($request, 99);

        self::assertSame(409, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertSame('LIFECYCLE_CONFLICT', $payload['error_code']);
    }

    private function buildControllerAndRequest(bool $isOwner, bool $rateAllowed, bool $csrfValid, ?Job $activeJob = null): array
    {
        $actor = new User('customer@example.test', UserType::Customer);
        $owner = $isOwner ? $actor : new User('owner@example.test', UserType::Customer);
        $this->setEntityId($actor, 11);
        $this->setEntityId($owner, $isOwner ? 11 : 22);

        $instance = $this->createMock(Instance::class);
        $instance->method('getCustomer')->willReturn($owner);
        $instance->method('getStatus')->willReturn(InstanceStatus::Running);
        $instance->method('getId')->willReturn(99);

        $repo = $this->createMock(InstanceRepository::class);
        $repo->method('find')->with(99)->willReturn($instance);

        $grpc = $this->createMock(ConsoleAgentGrpcClientInterface::class);
        $jobRepository = $this->createMock(JobRepository::class);
        $jobRepository->method('findLatestActiveByTypesAndInstanceId')->willReturn($activeJob);

        $auditRepo = $this->createMock(AuditLogRepository::class);
        $auditRepo->method('findLatestHash')->willReturn(null);
        $audit = new AuditLogger($auditRepo, new AuditLogHasher(), $this->createMock(EntityManagerInterface::class));

        $limiter = new class ($rateAllowed) implements ConsoleCommandLimiterInterface {
            public function __construct(private readonly bool $allowed)
            {
            }
            public function consume(string $key): bool
            {
                return $this->allowed;
            }
        };

        $csrfManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfManager->method('isTokenValid')->willReturnCallback(static fn (CsrfToken $token): bool => $csrfValid && $token->getValue() === 'csrf-ok');

        $controller = new CustomerInstanceConsoleCommandController(
            $repo,
            $jobRepository,
            new ResponseEnvelopeFactory(),
            $grpc,
            $limiter,
            $audit,
            $this->createMock(EntityManagerInterface::class),
            $csrfManager,
            'test-secret',
        );

        $request = Request::create('/instances/99/console/command', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['command' => "status\n", 'csrf_token' => 'csrf-ok']));
        $request->attributes->set('current_user', $actor);

        return [$controller, $request, $grpc];
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionClass($entity);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }
}
