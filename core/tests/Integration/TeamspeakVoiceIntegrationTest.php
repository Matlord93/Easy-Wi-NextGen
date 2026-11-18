<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\AgentOrchestrator\Domain\Enum\AgentJobStatus;
use App\Module\Core\Application\SecretsCryptoInterface;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\AuditLog;
use App\Module\Core\Domain\Entity\Ts3Node;
use App\Module\Core\Domain\Entity\Ts3VirtualServer;
use App\Module\Core\Domain\Entity\Ts6Node;
use App\Module\Core\Domain\Entity\Ts6VirtualServer;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\VoiceInstance;
use App\Module\Core\Domain\Entity\VoiceNode;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Voice\Application\TeamspeakServerOperationsInterface;
use App\Module\Voice\UI\Controller\Customer\CustomerVoiceApiController;
use App\Module\Voice\UI\Controller\Customer\CustomerVoiceController;
use App\Module\Voice\UI\Controller\Admin\AdminTs3NodeController;
use App\Module\Voice\UI\Controller\Admin\AdminTs6NodeController;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Cache\CacheInterface;

final class TeamspeakVoiceIntegrationTest extends KernelTestCase
{
    private array $previousDatabase;
    private EntityManagerInterface $entities;
    private User $customer;
    private Agent $agent;

    protected function setUp(): void
    {
        $this->previousDatabase = [$_SERVER['DATABASE_URL'] ?? null, $_ENV['DATABASE_URL'] ?? null];
        $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->entities = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($this->entities))->createSchema($this->entities->getMetadataFactory()->getAllMetadata());
        self::getContainer()->get(CacheInterface::class)->clear();
        $this->customer = new User('voice-owner@example.test', UserType::Customer);
        $this->customer->setPasswordHash('fixture-only');
        $this->agent = new Agent('voice-fixture-agent', ['key_id' => 'fixture', 'nonce' => 'fixture', 'ciphertext' => 'fixture']);
        $this->entities->persist($this->customer);
        $this->entities->persist($this->agent);
        $this->entities->flush();
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        foreach (['SERVER', 'ENV'] as $index => $global) {
            if ($this->previousDatabase[$index] === null) {
                unset($GLOBALS['_'.$global]['DATABASE_URL']);
            } else {
                $GLOBALS['_'.$global]['DATABASE_URL'] = $this->previousDatabase[$index];
            }
        }
        parent::tearDown();
    }

    public static function providers(): iterable
    {
        yield 'TS3' => ['ts3'];
        yield 'TS6' => ['ts6'];
    }

    #[DataProvider('providers')]
    public function testActionKeepsExistingPayloadProtocolAndDeduplication(string $provider): void
    {
        [$server, $instance] = $this->fixture($provider);
        $operations = self::getContainer()->get(TeamspeakServerOperationsInterface::class);
        self::assertSame($server, $operations->resolve($instance));
        $first = $operations->action($server, 'start');
        self::assertSame($first, $operations->action($server, 'start'));
        self::assertSame($provider . '.virtual.action', $first->getType());
        self::assertSame($this->agent, $first->getNode());
        self::assertSame(17, $first->getPayload()['sid']);
        self::assertSame($server->getId(), $first->getPayload()['virtual_server_id']);
        self::assertSame('fixture-password', $first->getPayload()['admin_password']);
        self::assertSame('127.0.0.1', $first->getPayload()['query_bind_ip']);
        if ($provider === 'ts3') {
            self::assertSame(10011, $first->getPayload()['query_port']);
            self::assertSame('serveradmin', $first->getPayload()['admin_username']);
            self::assertArrayNotHasKey('query_https_port', $first->getPayload());
        } else {
            self::assertSame(10443, $first->getPayload()['query_https_port']);
            self::assertSame('/srv/voice-fixture', $first->getPayload()['install_dir']);
            self::assertArrayNotHasKey('query_port', $first->getPayload());
        }
        try {
            $operations->action($server, 'stop');
            self::fail('A competing action must be rejected while start is pending.');
        } catch (\App\Module\Voice\Application\VoiceActionConflict $conflict) {
            self::assertSame($first->getId(), $conflict->jobId);
        }
        $first->markFinished(AgentJobStatus::Success);
        $this->entities->flush();
        $stop = $operations->action($server, 'stop');
        self::assertSame('stop', $stop->getPayload()['action']);
        $stop->markFinished(AgentJobStatus::Success);
        $this->entities->flush();
        self::assertSame('restart', $operations->action($server, 'restart')->getPayload()['action']);
    }

    #[DataProvider('providers')]
    public function testProbeWaitsForActualAgentResultAndDoesNotInventCounts(string $provider): void
    {
        [$server] = $this->fixture($provider);
        $operations = self::getContainer()->get(TeamspeakServerOperationsInterface::class);
        $pending = $operations->probe($server);
        self::assertSame('pending', $pending->status);
        self::assertNull($pending->players);
        self::assertNotNull($pending->jobId);
        self::assertSame($pending->jobId, $operations->probe($server)->jobId);
        $job = $this->entities->find(AgentJob::class, $pending->jobId);
        self::assertSame($provider . '.virtual.list', $job->getType());
        $job->setResultPayload(['servers' => [
            ['sid' => 99, 'status' => 'online', 'players_online' => 900, 'slots' => 999],
            ['sid' => 17, 'status' => 'offline', 'players_online' => null, 'slots' => 64],
        ]]);
        $job->markFinished(AgentJobStatus::Success);
        $this->entities->flush();
        $result = $operations->probe($server);
        self::assertSame('offline', $result->status);
        self::assertNull($result->players);
        self::assertSame(64, $result->maxPlayers);
        self::assertSame($job->getFinishedAt(), $result->checkedAt);
        self::assertNull($result->jobId);
        $job->setResultPayload(['servers' => [['sid' => 17, 'status' => 'online', 'players_online' => 7, 'slots' => 64]]]);
        self::assertSame(7, $operations->probe($server)->players);
        $job->setResultPayload(['servers' => [['sid' => 17, 'slots' => 64]]]);
        self::assertSame('unknown', $operations->probe($server)->status);
        self::assertSame('voice_query_invalid_response', $operations->probe($server)->errorCode);
        $job->markFinished(AgentJobStatus::Failed);
        self::assertSame('voice_query_failed', $operations->probe($server)->errorCode);
    }

    #[DataProvider('providers')]
    public function testCustomerEndpointsQueueRealJobsAndRejectAnotherCustomersBinding(string $provider): void
    {
        [$server, $instance] = $this->fixture($provider);
        $controller = self::getContainer()->get(CustomerVoiceApiController::class);
        $request = Request::create('/api/v1/customer/voice/' . $instance->getId() . '/actions/start', 'POST');
        $request->attributes->set('current_user', $this->customer);
        $response = $controller->action($request, $instance->getId(), 'start');
        self::assertSame(202, $response->getStatusCode(), $response->getContent());
        self::assertSame(1, $this->entities->getRepository(AgentJob::class)->count(['type' => $provider . '.virtual.action']));
        $audit = $this->entities->getRepository(AuditLog::class)->findOneBy(['action' => 'voice.' . $provider . '.start']);
        self::assertNotNull($audit);
        self::assertSame($this->customer, $audit->getActor());
        self::assertSame($instance->getId(), $audit->getPayload()['voice_instance_id']);
        self::assertStringNotContainsString('fixture-password', json_encode($audit->getPayload(), JSON_THROW_ON_ERROR));
        $probe = $controller->probe($request, $instance->getId());
        self::assertSame(202, $probe->getStatusCode(), $probe->getContent());
        self::assertNull($instance->getCheckedAt(), 'Queuing a query is not a measured status.');
        $server->setCustomerId($this->customer->getId() + 1);
        self::assertSame(404, $controller->detail($request, $instance->getId())->getStatusCode());
        self::assertSame(404, $controller->action($request, $instance->getId(), 'start')->getStatusCode());
    }

    public function testExistingRoutesStillUseTheMergedModule(): void
    {
        $router = self::getContainer()->get(RouterInterface::class);
        foreach (['/admin/ts3/nodes', '/admin/ts6/nodes', '/admin/teamspeak/nodes', '/customer/voice', '/admin/voice/nodes'] as $path) {
            $match = $router->match($path);
            self::assertStringStartsWith('App\\Module\\Voice\\UI\\Controller\\', $match['_controller']);
        }
    }

    #[DataProvider('providers')]
    public function testVirtualServerCreationKeepsTheDtoContractAndProviderSpecificPorts(string $provider): void
    {
        [$existing] = $this->fixture($provider);
        $node = $existing->getNode();
        $factory = self::getContainer()->get(\App\Module\Core\Application\VirtualServerDtoFactory::class);
        if ($provider === 'ts3') {
            $admin = new \App\Module\Core\Dto\Ts3\AdminCreateVirtualServerDto($this->customer->getId(), $node->getId(), 'Contract server', 10020, 30040);
            $dto = $factory->createTs3($admin);
            $created = self::getContainer()->get(\App\Module\Voice\Application\Ts3\Ts3VirtualServerService::class)->createForCustomer($admin->getCustomerId(), $node, $dto);
            self::assertSame(30040, $created->getFiletransferPort());
        } else {
            $admin = new \App\Module\Core\Dto\Ts6\AdminCreateVirtualServerDto($this->customer->getId(), $node->getId(), 'Contract server', 48, 10020);
            $dto = $factory->createTs6($admin);
            $created = self::getContainer()->get(\App\Module\Voice\Application\Ts6\Ts6VirtualServerService::class)->createForCustomer($admin->getCustomerId(), $node, $dto);
            self::assertSame(48, $created->getSlots());
            self::assertNull($created->getFiletransferPort());
        }
        self::assertSame($node->getId(), $admin->getNodeId());
        self::assertSame('Contract server', $created->getName());
        self::assertSame(10020, $created->getVoicePort());
        self::assertSame($this->customer->getId(), $created->getCustomerId());
        $job = $this->entities->getRepository(AgentJob::class)->findOneBy(['type' => $provider . '.virtual.create']);
        self::assertNotNull($job);
        self::assertSame('Contract server', $job->getPayload()['name']);
        self::assertSame($created->getId(), $job->getPayload()['virtual_server_id']);
        self::assertSame(10020, $job->getPayload()['params']['voice_port']);
    }

    #[DataProvider('providers')]
    public function testNodeStatusActionRequiresAdminAndCsrfAndQueuesTheExistingHandler(string $provider): void
    {
        [$server] = $this->fixture($provider);
        $node = $server->getNode();
        $controller = self::getContainer()->get($provider === 'ts3' ? AdminTs3NodeController::class : AdminTs6NodeController::class);
        $request = Request::create('/admin/' . $provider . '/nodes/' . $node->getId() . '/refresh-status', 'POST');
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get(RequestStack::class)->push($request);
        try {
            $request->attributes->set('current_user', $this->customer);
            try {
                $controller->refreshStatus($request, $node->getId());
                self::fail('Customers must not refresh node service status.');
            } catch (UnauthorizedHttpException) {
                self::assertSame(0, $this->entities->getRepository(AgentJob::class)->count([]));
            }
            $admin = new User('voice-admin@example.test', UserType::Admin);
            $request->attributes->set('current_user', $admin);
            try {
                $controller->refreshStatus($request, $node->getId());
                self::fail('The status action must reject a missing CSRF token.');
            } catch (UnauthorizedHttpException) {
                self::assertSame(0, $this->entities->getRepository(AgentJob::class)->count([]));
            }
            $request->request->set('_token', self::getContainer()->get(CsrfTokenManagerInterface::class)->getToken($provider . '_status_' . $node->getId())->getValue());
            self::assertSame(302, $controller->refreshStatus($request, $node->getId())->getStatusCode());
            $job = $this->entities->getRepository(AgentJob::class)->findOneBy(['type' => $provider . '.status']);
            self::assertNotNull($job);
            self::assertSame($this->agent, $job->getNode());
            self::assertSame($node->getId(), $job->getPayload()['node_id']);
            self::assertSame($node->getServiceName(), $job->getPayload()['service_name']);
            self::assertSame(['POST'], self::getContainer()->get(RouterInterface::class)->getRouteCollection()->get('admin_' . $provider . '_nodes_refresh_status')->getMethods());
        } finally {
            self::getContainer()->get(RequestStack::class)->pop();
        }
    }

    #[DataProvider('providers')]
    public function testProbeCooldownAfterActionDoesNotCreateAFreshUnknownStatus(string $provider): void
    {
        [$server, $instance] = $this->fixture($provider);
        $operations = self::getContainer()->get(TeamspeakServerOperationsInterface::class);
        self::assertSame('pending', $operations->probe($server)->status);
        $operations->action($server, 'start');

        $request = Request::create('/api/v1/customer/voice/' . $instance->getId() . '/probe', 'POST');
        $request->attributes->set('current_user', $this->customer);
        $response = self::getContainer()->get(CustomerVoiceApiController::class)->probe($request, $instance->getId());
        self::assertSame(202, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('pending', $payload['status']);
        self::assertNull($payload['job_id']);
        self::assertSame(10, $payload['retry_after']);
        self::assertNull($instance->getCheckedAt());
    }

    #[DataProvider('providers')]
    public function testExistingAndLinkedPanelPagesRenderTheirCompleteActionUrls(string $provider): void
    {
        [$server, $instance] = $this->fixture($provider);
        $controller = self::getContainer()->get(CustomerVoiceController::class);
        $request = Request::create('/customer/voice/' . $instance->getId());
        $request->attributes->set('current_user', $this->customer);
        foreach ([
            [$controller->show($request, $instance->getId()), '/api/v1/customer/voice/' . $instance->getId()],
            [$controller->legacyShow($request, $provider, $server->getId()), '/api/v1/customer/voice/legacy/' . $provider . '/' . $server->getId()],
        ] as [$response, $base]) {
            self::assertSame(200, $response->getStatusCode());
            foreach (['/actions', '/probe', '/tokens/rotate', '/snapshot', '/snapshot/poll', '/snapshot/restore', '/viewer', '/query'] as $path) {
                self::assertStringContainsString($base . $path, $response->getContent());
            }
        }
    }

    /** @return array{Ts3VirtualServer|Ts6VirtualServer,VoiceInstance} */
    private function fixture(string $provider): array
    {
        $nodeClass = $provider === 'ts3' ? Ts3Node::class : Ts6Node::class;
        $node = new $nodeClass('fixture', $this->agent, 'http://agent.invalid', 'fixture', 'https://download.invalid', '/srv/voice-fixture', 'voice-fixture', 'voice-fixture');
        $node->setAdminPassword('fixture-password', self::getContainer()->get(SecretsCryptoInterface::class));
        $server = $provider === 'ts3'
            ? new Ts3VirtualServer($node, $this->customer->getId(), 17, 'fixture')
            : new Ts6VirtualServer($node, $this->customer->getId(), 17, 'fixture', 64);
        $voiceNode = new VoiceNode('fixture', $provider, 'voice.example.test', 10011);
        foreach ([$node, $server, $voiceNode] as $entity) {
            $this->entities->persist($entity);
        }
        $this->entities->flush();
        $instance = new VoiceInstance($this->customer, $voiceNode, (string) $server->getId(), 'fixture');
        $this->entities->persist($instance);
        $this->entities->flush();
        return [$server, $instance];
    }
}
