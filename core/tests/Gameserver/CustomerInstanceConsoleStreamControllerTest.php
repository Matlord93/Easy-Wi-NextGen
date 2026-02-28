<?php

declare(strict_types=1);

namespace App\Tests\Gameserver;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Gameserver\Application\Console\ConsoleEventBusInterface;
use App\Module\Gameserver\Application\Console\ConsoleStreamDiagnostics;
use App\Module\Gameserver\Infrastructure\Mercure\NullConsoleAgentGrpcClient;
use App\Module\Gameserver\Application\Console\AgentEndpointProbeInterface;
use App\Module\Gameserver\UI\Controller\Customer\CustomerInstanceConsoleStreamController;
use App\Repository\InstanceRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class CustomerInstanceConsoleStreamControllerTest extends TestCase
{
    public function testUnauthorizedThrows403(): void
    {
        $controller = $this->controllerWith(new class implements ConsoleEventBusInterface {
            public function publishConsoleEvent(int $instanceId, array $payload): void {}
            public function replayConsoleEvents(int $instanceId, int $lastSeq): array { return []; }
            public function consumeConsoleEvents(int $instanceId, callable $onEvent, callable $shouldStop): void {}
            public function incrementSubscriber(int $instanceId): void {}
            public function refreshSubscriberTtl(int $instanceId): void {}
            public function decrementSubscriber(int $instanceId): void {}
            public function getSubscriberCount(int $instanceId): int { return 0; }
            public function getInstancesWithSubscribers(): array { return []; }
        }, false);

        $request = Request::create('/instances/1/console/stream', 'GET');
        $this->expectException(AccessDeniedHttpException::class);
        $controller->stream($request, 1);
    }

    public function testHeadersAndReplayWithLastEventId(): void
    {
        $eventBus = new class implements ConsoleEventBusInterface {
            public int $lastReplaySeq = -1;
            public function publishConsoleEvent(int $instanceId, array $payload): void {}
            public function replayConsoleEvents(int $instanceId, int $lastSeq): array
            {
                $this->lastReplaySeq = $lastSeq;
                return [
                    ['type' => 'chunk', 'seq' => $lastSeq + 1, 'chunk_base64' => base64_encode('line'), 'instance_id' => $instanceId, 'ts' => '2026-01-01T00:00:00Z'],
                ];
            }
            public function consumeConsoleEvents(int $instanceId, callable $onEvent, callable $shouldStop): void {}
            public function incrementSubscriber(int $instanceId): void {}
            public function refreshSubscriberTtl(int $instanceId): void {}
            public function decrementSubscriber(int $instanceId): void {}
            public function getSubscriberCount(int $instanceId): int { return 0; }
            public function getInstancesWithSubscribers(): array { return []; }
        };

        $controller = $this->controllerWith($eventBus, true, 0);
        $request = Request::create('/instances/1/console/stream', 'GET');
        $request->headers->set('Last-Event-ID', '41');
        $user = new User('owner@example.test', UserType::Customer);
        $this->setEntityId($user, 5);
        $request->attributes->set('current_user', $user);

        $response = $controller->stream($request, 1);
        self::assertStringStartsWith('text/event-stream', (string) $response->headers->get('Content-Type'));
        $response->sendContent();
        self::assertSame(41, $eventBus->lastReplaySeq);
    }


    public function testReturnsBackendNotConfiguredStatusWhenNullClientActive(): void
    {
        $eventBus = new class implements ConsoleEventBusInterface {
            public function publishConsoleEvent(int $instanceId, array $payload): void {}
            public function replayConsoleEvents(int $instanceId, int $lastSeq): array { return []; }
            public function consumeConsoleEvents(int $instanceId, callable $onEvent, callable $shouldStop): void {}
            public function incrementSubscriber(int $instanceId): void {}
            public function refreshSubscriberTtl(int $instanceId): void {}
            public function decrementSubscriber(int $instanceId): void {}
            public function getSubscriberCount(int $instanceId): int { return 0; }
            public function getInstancesWithSubscribers(): array { return []; }
        };

        $probe = $this->createMock(AgentEndpointProbeInterface::class);
        $probe->method('hasAnyEndpoint')->willReturn(false);
        $diagnostics = new ConsoleStreamDiagnostics(new NullConsoleAgentGrpcClient(), $probe, null);

        $controller = $this->controllerWith($eventBus, true, 1, $diagnostics);
        $request = Request::create('/instances/1/console/stream', 'GET');
        $user = new User('owner@example.test', UserType::Customer);
        $this->setEntityId($user, 5);
        $request->attributes->set('current_user', $user);

        $response = $controller->stream($request, 1);
        ob_start();
        ob_start();
        $response->sendContent();
        ob_end_clean();
        $content = (string) ob_get_clean();

        self::assertStringContainsString('event: status', $content);
        self::assertStringContainsString('backend_not_configured', $content);
    }

    private function controllerWith(ConsoleEventBusInterface $eventBus, bool $owner, int $maxDurationSeconds = 1, ?ConsoleStreamDiagnostics $diagnostics = null): CustomerInstanceConsoleStreamController
    {
        $instance = $this->createMock(Instance::class);
        $customer = new User('owner@example.test', UserType::Customer);
        $this->setEntityId($customer, $owner ? 5 : 7);
        $instance->method('getCustomer')->willReturn($customer);

        $repo = $this->createMock(InstanceRepository::class);
        $repo->method('find')->with(1)->willReturn($instance);

        return new CustomerInstanceConsoleStreamController($repo, $eventBus, $maxDurationSeconds, 1, $diagnostics);
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionClass($entity);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }
}
