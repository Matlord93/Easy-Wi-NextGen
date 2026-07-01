<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Gameserver\Application\Console\AgentEndpointProbeInterface;
use App\Module\Gameserver\Application\Console\ConsoleAgentGrpcClientInterface;
use App\Module\Gameserver\Application\Console\ConsoleCommandRequest;
use App\Module\Gameserver\Application\Console\ConsoleCommandResult;
use App\Module\Gameserver\Application\Console\ConsoleStreamDiagnostics;
use App\Module\Gameserver\Infrastructure\Client\AgentGameServerClient;
use App\Module\Gameserver\UI\Controller\Customer\CustomerInstanceActionApiController;
use App\Repository\InstanceRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\Request;

final class CustomerInstanceConsoleApiControllerTest extends TestCase
{
    public function testCommandForbiddenForNonOwnerReturnsEnvelope(): void
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $owner = new User('owner@example.test', UserType::Customer);
        $this->setEntityId($customer, 10);
        $this->setEntityId($owner, 20);

        $instance = $this->createMock(Instance::class);
        $instance->method('getCustomer')->willReturn($owner);

        $repo = $this->createMock(InstanceRepository::class);
        $repo->method('find')->with(7)->willReturn($instance);

        $controller = $this->newControllerWithRepo($repo);

        $request = Request::create('/api/instances/7/console/command', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['command' => 'status']));
        $request->attributes->set('current_user', $customer);
        $request->headers->set('X-Request-ID', 'req-console-forbidden');

        $response = $controller->sendConsoleCommandEnvelope($request, 7);
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse((bool) $payload['ok']);
        self::assertSame('FORBIDDEN', $payload['error_code']);
    }

    public function testLogsForbiddenForNonOwnerReturnsEnvelope(): void
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $owner = new User('owner@example.test', UserType::Customer);
        $this->setEntityId($customer, 10);
        $this->setEntityId($owner, 20);

        $instance = $this->createMock(Instance::class);
        $instance->method('getCustomer')->willReturn($owner);

        $repo = $this->createMock(InstanceRepository::class);
        $repo->method('find')->with(7)->willReturn($instance);

        $controller = $this->newControllerWithRepo($repo);

        $request = Request::create('/api/instances/7/console/logs', 'GET');
        $request->attributes->set('current_user', $customer);

        $response = $controller->logsEnvelope($request, 7);
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse((bool) $payload['ok']);
        self::assertSame('FORBIDDEN', $payload['error_code']);
    }

    public function testCommandInvalidPayloadReturns422(): void
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $this->setEntityId($customer, 10);

        $instance = $this->createMock(Instance::class);
        $instance->method('getCustomer')->willReturn($customer);

        $repo = $this->createMock(InstanceRepository::class);
        $repo->method('find')->with(7)->willReturn($instance);

        $controller = $this->newControllerWithRepo($repo);

        $request = Request::create('/api/instances/7/console/command', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['command' => '']));
        $request->attributes->set('current_user', $customer);

        $response = $controller->sendConsoleCommandEnvelope($request, 7);
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(422, $response->getStatusCode());
        self::assertFalse((bool) $payload['ok']);
        self::assertSame('INVALID_INPUT', $payload['error_code']);
    }

    public function testHealthReturnsOkEnvelopeForOwner(): void
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $this->setEntityId($customer, 10);

        $instance = $this->createMock(Instance::class);
        $instance->method('getId')->willReturn(7);
        $instance->method('getCustomer')->willReturn($customer);
        $instance->method('getStatus')->willReturn(InstanceStatus::Running);
        $instance->method('getQueryStatusCache')->willReturn(['status' => 'online']);

        $repo = $this->createMock(InstanceRepository::class);
        $repo->method('find')->with(7)->willReturn($instance);

        $controller = $this->newControllerWithRepo($repo);

        $request = Request::create('/api/instances/7/console/health', 'GET');
        $request->attributes->set('current_user', $customer);

        $response = $controller->healthEnvelope($request, 7);
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue((bool) $payload['ok']);
        self::assertSame(7, $payload['data']['instance_id']);
        self::assertTrue((bool) $payload['data']['can_send_command']);
    }

    public function testHealthNormalizesRunningQueryEvenWhenRuntimeProbeSaysStopped(): void
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $this->setEntityId($customer, 10);

        $instance = $this->createMock(Instance::class);
        $instance->method('getId')->willReturn(7);
        $instance->method('getCustomer')->willReturn($customer);
        $instance->method('getStatus')->willReturn(InstanceStatus::Running);
        $instance->method('getQueryStatusCache')->willReturn(['status' => 'running']);

        $repo = $this->createMock(InstanceRepository::class);
        $repo->method('find')->with(7)->willReturn($instance);

        $agentClient = $this->createMock(AgentGameServerClient::class);
        $agentClient->method('getInstanceStatus')->willReturn(['status' => 'stopped', 'running' => false]);
        $agentClient->method('getConsoleLogs')->willReturn(['data' => ['session' => ['connected' => false]]]);

        $controller = $this->newControllerWithRepo($repo, $agentClient);

        $request = Request::create('/api/instances/7/console/health', 'GET');
        $request->attributes->set('current_user', $customer);

        $response = $controller->healthEnvelope($request, 7);
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame('running', $payload['data']['query_status']);
        self::assertSame('running', $payload['data']['runtime_status']);
        self::assertSame('running', $payload['data']['running_state']);
        self::assertTrue((bool) $payload['data']['can_send_command']);
    }


    public function testLogsEnvelopeAcceptsRootAgentConsoleResponse(): void
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $this->setEntityId($customer, 10);

        $instance = $this->createMock(Instance::class);
        $instance->method('getId')->willReturn(7);
        $instance->method('getCustomer')->willReturn($customer);

        $repo = $this->createMock(InstanceRepository::class);
        $repo->method('find')->with(7)->willReturn($instance);

        $agentClient = $this->createMock(AgentGameServerClient::class);
        $agentClient->method('getConsoleLogs')->willReturn([
            'cursor' => 'abc:1',
            'lines' => [['id' => 1, 'text' => 'hello', 'ts' => '2026-01-01T00:00:00Z']],
            'meta' => ['state' => 'connected', 'journal_available' => true],
        ]);

        $controller = $this->newControllerWithRepo($repo, $agentClient);
        $request = Request::create('/api/instances/7/console/logs', 'GET');
        $request->attributes->set('current_user', $customer);

        $payload = json_decode((string) $controller->logsEnvelope($request, 7)->getContent(), true);

        self::assertTrue((bool) $payload['ok']);
        self::assertSame('hello', $payload['data']['lines'][0]['message']);
        self::assertTrue((bool) $payload['data']['session']['connected']);
    }

    public function testLogsEnvelopeAcceptsEnvelopedAgentConsoleResponse(): void
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $this->setEntityId($customer, 10);

        $instance = $this->createMock(Instance::class);
        $instance->method('getId')->willReturn(7);
        $instance->method('getCustomer')->willReturn($customer);

        $repo = $this->createMock(InstanceRepository::class);
        $repo->method('find')->with(7)->willReturn($instance);

        $agentClient = $this->createMock(AgentGameServerClient::class);
        $agentClient->method('getConsoleLogs')->willReturn([
            'ok' => true,
            'data' => [
                'cursor' => 'abc:2',
                'lines' => [['id' => 2, 'text' => 'world', 'ts' => '2026-01-01T00:00:01Z']],
                'meta' => ['state' => 'connected', 'journal_available' => true],
            ],
        ]);

        $controller = $this->newControllerWithRepo($repo, $agentClient);
        $request = Request::create('/api/instances/7/console/logs', 'GET');
        $request->attributes->set('current_user', $customer);

        $payload = json_decode((string) $controller->logsEnvelope($request, 7)->getContent(), true);

        self::assertTrue((bool) $payload['ok']);
        self::assertSame('world', $payload['data']['lines'][0]['message']);
        self::assertSame('abc:2', $payload['data']['cursor']);
    }


    public function testHealthKeepsLiveOutputSupportedWhenRelayIsNotRequired(): void
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $this->setEntityId($customer, 10);

        $instance = $this->createMock(Instance::class);
        $instance->method('getId')->willReturn(7);
        $instance->method('getCustomer')->willReturn($customer);
        $instance->method('getStatus')->willReturn(InstanceStatus::Running);
        $instance->method('getQueryStatusCache')->willReturn(['status' => 'running']);

        $repo = $this->createMock(InstanceRepository::class);
        $repo->method('find')->with(7)->willReturn($instance);

        $agentClient = $this->createMock(AgentGameServerClient::class);
        $agentClient->method('getInstanceStatus')->willReturn(['status' => 'running', 'running' => true]);
        $agentClient->method('getConsoleLogs')->willReturn(['ok' => true, 'data' => ['meta' => ['state' => 'connected']]]);

        $grpcClient = new class () implements ConsoleAgentGrpcClientInterface {
            public function sendCommand(ConsoleCommandRequest $request): ConsoleCommandResult
            {
                return new ConsoleCommandResult(false, false);
            }

            public function attachStream(int $instanceId): iterable
            {
                return [];
            }
        };
        $probe = $this->createMock(AgentEndpointProbeInterface::class);
        $diagnostics = new ConsoleStreamDiagnostics($grpcClient, $probe, null);

        $controller = $this->newControllerWithRepo($repo, $agentClient);
        $reflection = new \ReflectionClass($controller);
        $prop = $reflection->getProperty('consoleStreamDiagnostics');
        $prop->setAccessible(true);
        $prop->setValue($controller, $diagnostics);

        $request = Request::create('/api/instances/7/console/health', 'GET');
        $request->attributes->set('current_user', $customer);

        $payload = json_decode((string) $controller->healthEnvelope($request, 7)->getContent(), true);

        self::assertTrue((bool) $payload['data']['supports_live_output']);
        self::assertSame('ok', $payload['data']['live_output_status']);
    }

    private function newControllerWithRepo(InstanceRepository $repo, ?AgentGameServerClient $agentClient = null): CustomerInstanceActionApiController
    {
        $reflection = new \ReflectionClass(CustomerInstanceActionApiController::class);
        /** @var CustomerInstanceActionApiController $controller */
        $controller = $reflection->newInstanceWithoutConstructor();

        $prop = $reflection->getProperty('instanceRepository');
        $prop->setAccessible(true);
        $prop->setValue($controller, $repo);

        $agentProp = $reflection->getProperty('agentGameServerClient');
        $agentProp->setAccessible(true);
        $agentProp->setValue($controller, $agentClient);

        $translatorProp = $reflection->getProperty('translator');
        $translatorProp->setAccessible(true);
        $translatorProp->setValue($controller, $this->createMock(TranslatorInterface::class));

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
