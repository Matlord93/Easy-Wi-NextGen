<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\AgentOrchestrator\Application\AgentJobResultApplier;
use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\AgentOrchestrator\Domain\Enum\AgentJobStatus;
use App\Module\Musicbot\Application\MusicbotRuntimeEventServiceInterface;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotRuntimeEvent;
use App\Module\Musicbot\Domain\Enum\MusicbotInstanceStatus;
use App\Repository\MusicbotInstanceRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class MusicbotPlaybackStateFeedbackTest extends TestCase
{
    /** @var list<array{type: string, level: string, message: string, context: array}> */
    private array $eventLog = [];

    protected function setUp(): void
    {
        $this->eventLog = [];
    }

    private function buildApplier(MusicbotInstance $instance): AgentJobResultApplier
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $eventService = $this->buildEventService();

        $instanceRepo = new class ($instance) implements MusicbotInstanceRepositoryInterface {
            public function __construct(private readonly MusicbotInstance $i) {}

            public function findById(int $id): ?MusicbotInstance { return $this->i; }

            /** @return MusicbotInstance[] */
            public function findByCustomer(\App\Module\Core\Domain\Entity\User $customer): array { return []; }

            public function findOneForCustomer(int $id, \App\Module\Core\Domain\Entity\User $customer): ?MusicbotInstance { return null; }
        };

        $applier = (new \ReflectionClass(AgentJobResultApplier::class))->newInstanceWithoutConstructor();
        $this->inject($applier, 'ts3NodeRepository', $this->nullRepo(\App\Repository\Ts3NodeRepository::class));
        $this->inject($applier, 'ts6NodeRepository', $this->nullRepo(\App\Repository\Ts6NodeRepository::class));
        $this->inject($applier, 'sinusbotNodeRepository', $this->nullRepo(\App\Repository\SinusbotNodeRepository::class));
        $this->inject($applier, 'musicbotInstanceRepository', $instanceRepo);
        $this->inject($applier, 'musicbotConnectionRepository', $this->nullRepo(\App\Repository\MusicbotConnectionRepository::class));
        $this->inject($applier, 'ts3InstanceRepository', $this->nullRepo(\App\Repository\Ts3InstanceRepository::class));
        $this->inject($applier, 'ts6InstanceRepository', $this->nullRepo(\App\Repository\Ts6InstanceRepository::class));
        $this->inject($applier, 'ts3VirtualServerRepository', $this->nullRepo(\App\Repository\Ts3VirtualServerRepository::class));
        $this->inject($applier, 'ts6VirtualServerRepository', $this->nullRepo(\App\Repository\Ts6VirtualServerRepository::class));
        $this->inject($applier, 'crypto', $this->nullRepo(\App\Module\Core\Application\SecretsCrypto::class));
        $this->inject($applier, 'userRepository', $this->nullRepo(\App\Repository\UserRepository::class));
        $this->inject($applier, 'entityManager', $em);
        $this->inject($applier, 'musicbotRuntimeEventService', $eventService);
        $this->inject($applier, 'musicbotSecretConfigService', new \App\Module\Musicbot\Application\MusicbotSecretConfigService(new \App\Module\Core\Application\SecretsCrypto('test-secret')));

        return $applier;
    }

    private function inject(object $target, string $property, mixed $value): void
    {
        (new \ReflectionProperty($target, $property))->setValue($target, $value);
    }

    /** @param class-string $class */
    private function nullRepo(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    private function buildEventService(): MusicbotRuntimeEventServiceInterface
    {
        $eventLog = &$this->eventLog;

        return new class ($eventLog) implements MusicbotRuntimeEventServiceInterface {
            /** @param list<array> $log */
            public function __construct(private array &$log) {}

            public function record(MusicbotInstance $instance, string $type, string $level = 'info', string $message = '', array $context = []): MusicbotRuntimeEvent
            {
                $this->log[] = compact('type', 'level', 'message', 'context');

                return (new \ReflectionClass(MusicbotRuntimeEvent::class))->newInstanceWithoutConstructor();
            }
        };
    }

    private function makeInstance(?array $previousRuntimePayload = null): MusicbotInstance
    {
        return new class ($previousRuntimePayload) extends MusicbotInstance {
            private ?array $rp;
            private ?string $le = null;
            private MusicbotInstanceStatus $st;

            public function __construct(?array $rp)
            {
                $this->rp = $rp;
                $this->st = MusicbotInstanceStatus::Running;
            }

            public function getId(): ?int { return 42; }

            public function getRuntimePayload(): ?array { return $this->rp; }

            public function setRuntimePayload(?array $rp): void { $this->rp = $rp; }

            public function getLastError(): ?string { return $this->le; }

            public function setLastError(?string $e): void { $this->le = $e; }

            public function getStatus(): MusicbotInstanceStatus { return $this->st; }

            public function setStatus(MusicbotInstanceStatus $s): void { $this->st = $s; }
        };
    }

    private function makeJob(string $type): AgentJob
    {
        $job = (new \ReflectionClass(AgentJob::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(AgentJob::class, 'id'))->setValue($job, 'job-test-1');
        (new \ReflectionProperty(AgentJob::class, 'type'))->setValue($job, $type);
        (new \ReflectionProperty(AgentJob::class, 'payload'))->setValue($job, ['instance_id' => 42]);

        return $job;
    }

    /** @return array<string, mixed> */
    private function makeStatusPayload(string $playbackState, string $trackId = '', string $title = '', string $lastError = '', int $queueLength = 1): array
    {
        return [
            'running' => true,
            'playback_status' => [
                'playback_state' => $playbackState,
                'current_track_id' => $trackId,
                'current_title' => $title,
                'current_artist' => '',
                'current_source' => $trackId !== '' ? 'upload' : '',
                'playback_position_ms' => 0,
                'duration_ms' => 180000,
                'queue_length' => $queueLength,
                'repeat_mode' => 'off',
                'shuffle' => false,
                'decoder_backend' => 'ffmpeg',
                'decoder_status' => 'decoding',
                'output_backend' => 'null',
                'output_status' => 'ok',
                'frames_processed' => 100,
                'last_error' => $lastError,
                'last_state_change_at' => '2024-01-01T12:00:00Z',
            ],
        ];
    }

    public function testFirstPollDoesNotEmitTransitionEvent(): void
    {
        $instance = $this->makeInstance(null);
        $applier = $this->buildApplier($instance);

        $applier->apply($this->makeJob('musicbot.status'), AgentJobStatus::Success, $this->makeStatusPayload('playing', 't-1', 'Song'));

        self::assertNotContains('playback.started', array_column($this->eventLog, 'type'), 'No transition event on first poll (no previous state)');
    }

    public function testStoppedToPlayingEmitsPlaybackStarted(): void
    {
        $instance = $this->makeInstance($this->makeStatusPayload('stopped'));
        $applier = $this->buildApplier($instance);

        $applier->apply($this->makeJob('musicbot.status'), AgentJobStatus::Success, $this->makeStatusPayload('playing', 't-1', 'My Track'));

        $types = array_column($this->eventLog, 'type');
        self::assertContains('playback.started', $types);
        self::assertNotContains('playback.stopped', $types);
    }

    public function testPlayingToPausedEmitsPlaybackPaused(): void
    {
        $instance = $this->makeInstance($this->makeStatusPayload('playing', 't-1', 'My Track'));
        $applier = $this->buildApplier($instance);

        $applier->apply($this->makeJob('musicbot.status'), AgentJobStatus::Success, $this->makeStatusPayload('paused', 't-1', 'My Track'));

        self::assertContains('playback.paused', array_column($this->eventLog, 'type'));
    }

    public function testPausedToPlayingEmitsPlaybackResumed(): void
    {
        $instance = $this->makeInstance($this->makeStatusPayload('paused', 't-1', 'My Track'));
        $applier = $this->buildApplier($instance);

        $applier->apply($this->makeJob('musicbot.status'), AgentJobStatus::Success, $this->makeStatusPayload('playing', 't-1', 'My Track'));

        $types = array_column($this->eventLog, 'type');
        self::assertContains('playback.resumed', $types);
        self::assertNotContains('playback.started', $types);
    }

    public function testPlayingToStoppedEmitsPlaybackStopped(): void
    {
        $instance = $this->makeInstance($this->makeStatusPayload('playing', 't-1', 'My Track'));
        $applier = $this->buildApplier($instance);

        $applier->apply($this->makeJob('musicbot.status'), AgentJobStatus::Success, $this->makeStatusPayload('stopped', '', '', '', 1));

        self::assertContains('playback.stopped', array_column($this->eventLog, 'type'));
    }

    public function testEmptyQueueOnStopEmitsQueueEmpty(): void
    {
        $instance = $this->makeInstance($this->makeStatusPayload('playing', 't-1', 'Last Track', '', 1));
        $applier = $this->buildApplier($instance);

        $applier->apply($this->makeJob('musicbot.status'), AgentJobStatus::Success, $this->makeStatusPayload('stopped', '', '', '', 0));

        $types = array_column($this->eventLog, 'type');
        self::assertContains('queue.empty', $types);
        self::assertContains('playback.stopped', $types);
    }

    public function testErrorStateEmitsPlaybackError(): void
    {
        $instance = $this->makeInstance($this->makeStatusPayload('playing', 't-1', 'Track'));
        $applier = $this->buildApplier($instance);

        $applier->apply($this->makeJob('musicbot.status'), AgentJobStatus::Success, $this->makeStatusPayload('error', '', '', 'decoder crashed'));

        $types = array_column($this->eventLog, 'type');
        self::assertContains('playback.error', $types);
        $errorEvent = array_values(array_filter($this->eventLog, fn ($e) => $e['type'] === 'playback.error'))[0];
        self::assertSame('error', $errorEvent['level']);
        self::assertSame('decoder crashed', $errorEvent['context']['error'] ?? '');
    }

    public function testSameStateDoesNotEmitTransitionEvent(): void
    {
        $instance = $this->makeInstance($this->makeStatusPayload('playing', 't-1', 'Track'));
        $applier = $this->buildApplier($instance);

        $applier->apply($this->makeJob('musicbot.status'), AgentJobStatus::Success, $this->makeStatusPayload('playing', 't-1', 'Track'));

        foreach (['playback.started', 'playback.paused', 'playback.resumed', 'playback.stopped'] as $type) {
            self::assertNotContains($type, array_column($this->eventLog, 'type'), "Unexpected $type event on same state");
        }
    }

    public function testRuntimePayloadIsStoredOnInstance(): void
    {
        $instance = $this->makeInstance(null);
        $applier = $this->buildApplier($instance);

        $applier->apply($this->makeJob('musicbot.status'), AgentJobStatus::Success, $this->makeStatusPayload('playing', 't-99', 'New Track'));

        $stored = $instance->getRuntimePayload();
        self::assertIsArray($stored);
        self::assertArrayHasKey('playback_status', $stored);
        self::assertSame('playing', $stored['playback_status']['playback_state']);
        self::assertSame('t-99', $stored['playback_status']['current_track_id']);
    }

    public function testLastErrorPopulatedFromPlaybackStatus(): void
    {
        $instance = $this->makeInstance($this->makeStatusPayload('playing'));
        $applier = $this->buildApplier($instance);

        $applier->apply($this->makeJob('musicbot.status'), AgentJobStatus::Success, $this->makeStatusPayload('error', '', '', 'audio output failed'));

        self::assertSame('audio output failed', $instance->getLastError());
    }

    public function testOutputBackendNullIsPreservedInPayload(): void
    {
        $instance = $this->makeInstance(null);
        $applier = $this->buildApplier($instance);

        $applier->apply($this->makeJob('musicbot.status'), AgentJobStatus::Success, $this->makeStatusPayload('stopped'));

        $stored = $instance->getRuntimePayload();
        self::assertSame('null', $stored['playback_status']['output_backend'] ?? null, 'NullAudioOutput must surface as "null"');
    }
}
