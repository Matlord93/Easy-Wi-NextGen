<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\AgentOrchestrator\Application\AgentJobDispatcherInterface;
use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Application\MusicbotPlaybackCommandService;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Enum\MusicbotRepeatMode;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MusicbotPlaybackCommandServiceTest extends TestCase
{
    private AgentJobDispatcherInterface $dispatcher;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->dispatcher = $this->createStub(AgentJobDispatcherInterface::class);
        $this->em = $this->createStub(EntityManagerInterface::class);
    }

    private function makeService(): MusicbotPlaybackCommandService
    {
        return new MusicbotPlaybackCommandService($this->dispatcher, $this->em);
    }

    private function makeUser(int $id = 1): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn($id);
        return $user;
    }

    private function makeNode(): Agent
    {
        $node = $this->createStub(Agent::class);
        $node->method('getId')->willReturn('node-1');
        return $node;
    }

    private function makeInstance(User $customer, int $id = 42): MusicbotInstance
    {
        $instance = new MusicbotInstance($customer, $this->makeNode(), 'TestBot', 'mb-1-abcde', '/opt/mb');
        // Fake the id via reflection so assertions can reference it
        $ref = new \ReflectionProperty($instance, 'id');
        $ref->setAccessible(true);
        $ref->setValue($instance, $id);
        return $instance;
    }

    private function makeJob(): AgentJob
    {
        $job = $this->createStub(AgentJob::class);
        $job->method('getId')->willReturn('job-xyz');
        return $job;
    }

    // ──────────────────────────── ownership ────────────────────────────

    public function testDispatchFails_WhenInstanceBelongsToDifferentCustomer(): void
    {
        $owner = $this->makeUser(1);
        $other = $this->makeUser(2);
        $instance = $this->makeInstance($owner);

        $this->expectException(\RuntimeException::class);

        $this->makeService()->dispatchPlaybackAction($other, $instance, 'play');
    }

    // ──────────────────────────── valid actions ────────────────────────

    /** @return list<array{string}> */
    public static function validActionProvider(): array
    {
        return [
            ['play'],
            ['pause'],
            ['resume'],
            ['stop'],
            ['skip'],
            ['volume'],
            ['shuffle'],
            ['repeat'],
        ];
    }

    #[DataProvider('validActionProvider')]
    public function testDispatch_ValidAction_DispatchesCorrectJobType(string $action): void
    {
        $customer = $this->makeUser();
        $instance = $this->makeInstance($customer);

        $dispatchedType = null;
        $this->dispatcher = $this->createMock(AgentJobDispatcherInterface::class);
        $this->dispatcher->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (Agent $node, string $type, array $payload) use (&$dispatchedType): AgentJob {
                $dispatchedType = $type;
                return $this->makeJob();
            });

        $this->makeService()->dispatchPlaybackAction($customer, $instance, $action);

        $this->assertSame('musicbot.playback.action', $dispatchedType);
    }

    public function testDispatch_NormalizesActionToLowercase(): void
    {
        $customer = $this->makeUser();
        $instance = $this->makeInstance($customer);

        $capturedPayload = [];
        $this->dispatcher = $this->createMock(AgentJobDispatcherInterface::class);
        $this->dispatcher->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (Agent $node, string $type, array $payload) use (&$capturedPayload): AgentJob {
                $capturedPayload = $payload;
                return $this->makeJob();
            });

        $this->makeService()->dispatchPlaybackAction($customer, $instance, 'PLAY');

        $this->assertSame('play', $capturedPayload['action']);
    }

    public function testDispatch_IncludesInstanceIdInPayload(): void
    {
        $customer = $this->makeUser();
        $instance = $this->makeInstance($customer, 42);

        $capturedPayload = [];
        $this->dispatcher = $this->createMock(AgentJobDispatcherInterface::class);
        $this->dispatcher->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (Agent $node, string $type, array $payload) use (&$capturedPayload): AgentJob {
                $capturedPayload = $payload;
                return $this->makeJob();
            });

        $this->makeService()->dispatchPlaybackAction($customer, $instance, 'stop');

        $this->assertArrayHasKey('instance_id', $capturedPayload);
        $this->assertArrayHasKey('service_name', $capturedPayload);
    }

    public function testDispatch_MergesExtraPayload(): void
    {
        $customer = $this->makeUser();
        $instance = $this->makeInstance($customer);

        $capturedPayload = [];
        $this->dispatcher = $this->createMock(AgentJobDispatcherInterface::class);
        $this->dispatcher->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (Agent $node, string $type, array $payload) use (&$capturedPayload): AgentJob {
                $capturedPayload = $payload;
                return $this->makeJob();
            });

        $this->makeService()->dispatchPlaybackAction($customer, $instance, 'volume', ['value' => 75]);

        $this->assertSame(75, $capturedPayload['value']);
    }

    // ──────────────────────────── invalid action ────────────────────────

    public function testDispatch_InvalidAction_Throws(): void
    {
        $customer = $this->makeUser();
        $instance = $this->makeInstance($customer);

        $this->expectException(\InvalidArgumentException::class);

        $this->makeService()->dispatchPlaybackAction($customer, $instance, 'explode');
    }

    // ──────────────────────────── prepareSkip ────────────────────────────

    public function testPrepareSkip_DispatchesSkipAction(): void
    {
        $customer = $this->makeUser();
        $instance = $this->makeInstance($customer);

        $capturedAction = null;
        $this->dispatcher = $this->createMock(AgentJobDispatcherInterface::class);
        $this->dispatcher->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (Agent $node, string $type, array $payload) use (&$capturedAction): AgentJob {
                $capturedAction = $payload['action'] ?? null;
                return $this->makeJob();
            });

        $this->makeService()->prepareSkip($customer, $instance);

        $this->assertSame('skip', $capturedAction);
    }

    // ──────────────────────────── repeat / shuffle storage ────────────────

    public function testStoreRepeatMode_PersistsToRuntimePayload(): void
    {
        $customer = $this->makeUser();
        $instance = $this->makeInstance($customer);

        $flushed = false;
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->expects($this->once())
            ->method('flush')
            ->willReturnCallback(function () use (&$flushed): void { $flushed = true; });

        $this->makeService()->storeRepeatMode($customer, $instance, MusicbotRepeatMode::All);

        $payload = $instance->getRuntimePayload();
        $this->assertSame('all', $payload['playback']['repeat_mode'] ?? null);
        $this->assertTrue($flushed);
    }

    public function testStoreShuffle_PersistsToRuntimePayload(): void
    {
        $customer = $this->makeUser();
        $instance = $this->makeInstance($customer);

        $flushed = false;
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->expects($this->once())
            ->method('flush')
            ->willReturnCallback(function () use (&$flushed): void { $flushed = true; });

        $this->makeService()->storeShuffle($customer, $instance, true);

        $payload = $instance->getRuntimePayload();
        $this->assertTrue($payload['playback']['shuffle'] ?? false);
        $this->assertTrue($flushed);
    }

    public function testStoreRepeatMode_FailsForWrongCustomer(): void
    {
        $owner = $this->makeUser(1);
        $other = $this->makeUser(2);
        $instance = $this->makeInstance($owner);

        $this->expectException(\RuntimeException::class);

        $this->makeService()->storeRepeatMode($other, $instance, MusicbotRepeatMode::One);
    }
}
