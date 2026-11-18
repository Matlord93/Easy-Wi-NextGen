<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\VoiceInstance;
use App\Module\Core\Domain\Entity\VoiceNode;
use App\Module\Core\Domain\Entity\VoiceRateLimitState;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Core\UI\Api\ResponseEnvelopeFactory;
use App\Module\Voice\Application\Provider\VoiceProviderAdapter;
use App\Module\Voice\Application\Provider\VoiceProviderRegistry;
use App\Module\Voice\Application\Provider\VoiceQueryResult;
use App\Module\Voice\Application\VoiceProbeGuard;
use App\Module\Voice\Application\VoiceRateLimitStateStoreInterface;
use App\Module\Voice\UI\Controller\Customer\CustomerVoiceApiController;
use App\Repository\JobRepositoryInterface;
use App\Repository\VoiceInstanceRepository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CustomerVoiceProbeCircuitTest extends TestCase
{
    public function testRepeatedProbeFailuresOpenTheDocumentedCircuit(): void
    {
        [$controller, $request, $instance, $state] = $this->controller('unknown', 'voice_query_timeout');

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $this->makeSnapshotStale($instance);
            self::assertSame(200, $controller->probe($request, 872341)->getStatusCode());
        }

        self::assertSame(3, $state->getConsecutiveFailures());
        self::assertNotNull($state->getCircuitOpenUntil());
        $this->makeSnapshotStale($instance);
        $response = $controller->probe($request, 872341);
        self::assertSame(429, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('voice_circuit_open', $body['error_code']);
        self::assertSame(3, $state->getConsecutiveFailures());
        self::assertGreaterThan(0, $body['retry_after']);
    }

    public function testCachedProbeDoesNotCountTheSameFailureAgain(): void
    {
        [$controller, $request, $instance, $state] = $this->controller('unknown', 'voice_query_timeout');
        $this->makeSnapshotStale($instance);

        self::assertSame(200, $controller->probe($request, 872341)->getStatusCode());
        self::assertSame(1, $state->getConsecutiveFailures());
        self::assertSame(200, $controller->probe($request, 872341)->getStatusCode());
        self::assertSame(1, $state->getConsecutiveFailures());
    }

    #[DataProvider('probeResults')]
    public function testProbeResultsUpdateOnlyActualFailureHistory(string $status, ?string $errorCode, int $expectedFailures): void
    {
        [$controller, $request, $instance, $state] = $this->controller($status, $errorCode);
        $state->setConsecutiveFailures(1);
        if ($expectedFailures === 0) {
            $state->setCircuitOpenUntil(new \DateTimeImmutable('-1 second'));
        }
        $this->makeSnapshotStale($instance);

        self::assertSame(200, $controller->probe($request, 872341)->getStatusCode());
        self::assertSame($expectedFailures, $state->getConsecutiveFailures());
        self::assertNull($state->getCircuitOpenUntil());
    }

    public static function probeResults(): iterable
    {
        yield 'timeout' => ['unknown', 'voice_query_timeout', 2];
        yield 'banned' => ['unknown', 'voice_query_banned', 2];
        yield 'authentication failure' => ['unknown', 'voice_query_auth_failed', 2];
        yield 'query failure' => ['unknown', 'voice_query_failed', 2];
        yield 'online success' => ['running', null, 0];
        yield 'offline success' => ['stopped', null, 0];
        yield 'unknown snapshot' => ['unknown', null, 1];
        yield 'rate limit' => ['unknown', 'voice_query_rate_limited', 1];
        yield 'unsupported query' => ['unknown', 'voice_query_not_supported', 1];
        yield 'unsupported provider' => ['unknown', 'voice_provider_unsupported', 1];
        yield 'missing configured server' => ['unknown', 'voice_instance_not_found', 1];
    }

    /** @return array{CustomerVoiceApiController, Request, VoiceInstance, VoiceRateLimitState} */
    private function controller(string $status, ?string $errorCode): array
    {
        $customer = new User('voice-circuit@example.test', UserType::Customer);
        (new \ReflectionProperty(User::class, 'id'))->setValue($customer, 872341);
        $node = new VoiceNode('test', 'ts3', '127.0.0.1', 10011);
        $instance = new VoiceInstance($customer, $node, '1', 'Voice test');
        (new \ReflectionProperty(VoiceInstance::class, 'id'))->setValue($instance, 872341);
        $state = new VoiceRateLimitState($node, 'ts3');
        $store = $this->createStub(VoiceRateLimitStateStoreInterface::class);
        $store->method('findOneByNodeAndProvider')->willReturn($state);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $guard = new VoiceProbeGuard($store, $entityManager);

        $baseRepository = $this->createStub(EntityRepository::class);
        $baseRepository->method('find')->willReturn($instance);
        $repository = new VoiceInstanceRepository($this->createStub(ManagerRegistry::class));
        (new \ReflectionProperty(ServiceEntityRepository::class, 'repository'))->setValue($repository, $baseRepository);
        $jobs = $this->createStub(JobRepositoryInterface::class);
        $jobs->method('findActiveByTypeAndPayloadField')->willReturn(null);

        $adapter = new class($status, $errorCode) implements VoiceProviderAdapter {
            public function __construct(private readonly string $status, private readonly ?string $errorCode)
            {
            }
            public function supports(string $providerType): bool
            {
                return $providerType === 'ts3';
            }
            public function query(VoiceInstance $instance): VoiceQueryResult
            {
                throw new \LogicException('Probe must use probeStatus.');
            }
            public function probeStatus(VoiceInstance $instance): array
            {
                return ['status' => $this->status, 'players_online' => null, 'players_max' => null, 'reason' => null, 'error_code' => $this->errorCode];
            }
            public function performAction(VoiceInstance $instance, string $action): array
            {
                throw new \LogicException('No action expected.');
            }
            public function getConnectInfo(VoiceInstance $instance): array
            {
                return ['host' => '127.0.0.1', 'port' => 9987];
            }

        };

        $operations = $this->createStub(\App\Module\Voice\Application\TeamspeakServerOperationsInterface::class);
        $operations->method('resolve')->willReturn((new \ReflectionClass(\App\Module\Core\Domain\Entity\Ts3VirtualServer::class))->newInstanceWithoutConstructor());
        $reflection = new \ReflectionClass(CustomerVoiceApiController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        foreach ([
            'repository' => $repository,
            'serverOperations' => $operations,
            'jobRepository' => $jobs,
            'providers' => new VoiceProviderRegistry([$adapter]),
            'probeGuard' => $guard,
            'entityManager' => $entityManager,
            'responseEnvelopeFactory' => new ResponseEnvelopeFactory(),
        ] as $property => $value) {
            $reflection->getProperty($property)->setValue($controller, $value);
        }
        $request = Request::create('/api/v1/customer/voice/872341/probe', 'POST');
        $request->attributes->set('current_user', $customer);

        return [$controller, $request, $instance, $state];
    }

    private function makeSnapshotStale(VoiceInstance $instance): void
    {
        $instance->updateStatus('unknown', null, null, null, null, new \DateTimeImmutable('-1 minute'));
    }
}
