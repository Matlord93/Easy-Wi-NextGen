<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\AgentOrchestrator\Application\AgentJobDispatcherInterface;
use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Application\MusicbotQueueService;
use App\Module\Musicbot\Application\MusicbotQuotaServiceInterface;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotQueueItem;
use App\Module\Musicbot\Domain\Entity\MusicbotTrack;
use App\Module\Musicbot\Domain\Enum\MusicbotTrackSourceType;
use App\Repository\MusicbotQueueItemRepositoryInterface;
use App\Repository\MusicbotTrackRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class MusicbotQueueServiceSyncTest extends TestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createStub(EntityManagerInterface::class);
    }

    /**
     * Build a MusicbotQueueService using anonymous stubs for the interface dependencies.
     *
     * @param list<object> $queueItems  returned by the stub repo's findQueueForInstanceOrdered
     * @param list<array<string,mixed>> $dispatchLog  reference — each dispatch() call is appended
     */
    private function buildService(array $queueItems = [], array &$dispatchLog = []): MusicbotQueueService
    {
        $queueItemRepo = new class($queueItems) implements MusicbotQueueItemRepositoryInterface {
            public function __construct(private readonly array $items) {}

            public function findQueueForInstanceOrdered(MusicbotInstance $instance): array
            {
                return $this->items;
            }
        };

        $trackRepo = new class implements MusicbotTrackRepositoryInterface {
            public function findOneForCustomer(int $id, User $customer): ?MusicbotTrack
            {
                return null;
            }
        };

        $quotaService = new class implements MusicbotQuotaServiceInterface {
            public function assertCanAddToQueue(User $customer, MusicbotInstance $instance): void {}
        };

        $agentJobProto = (new \ReflectionClass(AgentJob::class))->newInstanceWithoutConstructor();
        $jobDispatcher = new class($dispatchLog, $agentJobProto) implements AgentJobDispatcherInterface {
            public function __construct(private array &$log, private readonly AgentJob $proto) {}

            /** @param array<string, mixed> $payload */
            public function dispatch(Agent $node, string $type, array $payload): AgentJob
            {
                $this->log[] = ['node' => $node, 'type' => $type, 'payload' => $payload];

                return $this->proto;
            }
        };

        return new MusicbotQueueService(
            $this->entityManager,
            $queueItemRepo,
            $trackRepo,
            $quotaService,
            $jobDispatcher,
        );
    }

    /** @return array{User, MusicbotInstance, MusicbotTrack} */
    private function makeEntities(
        ?string $filePath = 'tracks/song.mp3',
        MusicbotTrackSourceType $sourceType = MusicbotTrackSourceType::Upload,
    ): array {
        $customer = $this->createStub(User::class);
        $customer->method('getId')->willReturn(7);

        $node = $this->createStub(Agent::class);
        $node->method('getId')->willReturn('node-1');

        $instance = $this->createStub(MusicbotInstance::class);
        $instance->method('getId')->willReturn(42);
        $instance->method('getCustomer')->willReturn($customer);
        $instance->method('getNode')->willReturn($node);
        $instance->method('getServiceName')->willReturn('musicbot-test');
        $instance->method('getInstallPath')->willReturn('/srv/musicbot/instance-42');

        $track = $this->createStub(MusicbotTrack::class);
        $track->method('getId')->willReturn(101);
        $track->method('getCustomer')->willReturn($customer);
        $track->method('getInstance')->willReturn($instance);
        $track->method('getTitle')->willReturn('My Track');
        $track->method('getArtist')->willReturn('Test Artist');
        $track->method('getDurationSeconds')->willReturn(180);
        $track->method('getSourceType')->willReturn($sourceType);
        $track->method('getFilePath')->willReturn($filePath);
        $track->method('getMimeType')->willReturn('audio/mpeg');
        $track->method('getMetadata')->willReturn([]);

        return [$customer, $instance, $track];
    }

    private function makeQueueItem(object $instance, object $track, int $position): MusicbotQueueItem
    {
        $item = $this->createStub(MusicbotQueueItem::class);
        $item->method('getId')->willReturn(1);
        $item->method('getInstance')->willReturn($instance);
        $item->method('getTrack')->willReturn($track);
        $item->method('getPosition')->willReturn($position);

        return $item;
    }

    public function testAddTrackToQueueDispatchesQueueSync(): void
    {
        [$customer, $instance, $track] = $this->makeEntities();
        $dispatchLog = [];
        $service = $this->buildService([], $dispatchLog);

        $service->addTrackToQueue($customer, $instance, $track);

        self::assertCount(1, $dispatchLog, 'dispatch() must be called exactly once');
        $call = $dispatchLog[0];
        self::assertSame('musicbot.queue.sync', $call['type']);
        self::assertSame('42', $call['payload']['instance_id']);
        self::assertSame('musicbot-test', $call['payload']['service_name']);
        self::assertArrayHasKey('queue', $call['payload']);
        self::assertArrayHasKey('queue_length', $call['payload']);
    }

    public function testClearQueueDispatchesSyncWithEmptyItems(): void
    {
        [$customer, $instance] = $this->makeEntities();
        $dispatchLog = [];
        $service = $this->buildService([], $dispatchLog);

        $service->clearQueue($customer, $instance);

        self::assertCount(1, $dispatchLog);
        $payload = $dispatchLog[0]['payload'];
        self::assertSame(0, $payload['queue_length']);
        self::assertSame([], $payload['queue']['items']);
    }

    public function testBuildQueueSnapshotOmitsTracksWithoutFilePath(): void
    {
        [$customer, $instance, $track] = $this->makeEntities(filePath: null);
        $queueItem = $this->makeQueueItem($instance, $track, 0);
        $service = $this->buildService([$queueItem]);

        $snapshot = $service->buildQueueSnapshot($instance);

        self::assertSame([], $snapshot['items'], 'Track without filePath must be excluded');
    }

    public function testBuildQueueSnapshotStripsInstallPathPrefix(): void
    {
        [$customer, $instance, $track] = $this->makeEntities(
            filePath: '/srv/musicbot/instance-42/data/tracks/abc.mp3',
        );
        $queueItem = $this->makeQueueItem($instance, $track, 0);
        $service = $this->buildService([$queueItem]);

        $snapshot = $service->buildQueueSnapshot($instance);

        self::assertCount(1, $snapshot['items']);
        self::assertSame('tracks/abc.mp3', $snapshot['items'][0]['source']['uri']);
    }

    public function testBuildQueueSnapshotRejectsPathTraversal(): void
    {
        [$customer, $instance, $track] = $this->makeEntities(filePath: '../../etc/passwd');
        $queueItem = $this->makeQueueItem($instance, $track, 0);
        $service = $this->buildService([$queueItem]);

        $snapshot = $service->buildQueueSnapshot($instance);

        self::assertSame([], $snapshot['items'], 'Path traversal must be excluded');
    }

    public function testBuildQueueSnapshotOmitsNonUploadTracks(): void
    {
        [$customer, $instance, $track] = $this->makeEntities(
            filePath: 'tracks/stream.mp3',
            sourceType: MusicbotTrackSourceType::Stream,
        );
        $queueItem = $this->makeQueueItem($instance, $track, 0);
        $service = $this->buildService([$queueItem]);

        $snapshot = $service->buildQueueSnapshot($instance);

        self::assertSame([], $snapshot['items'], 'Stream track must be excluded from runtime queue');
    }

    public function testBuildQueueSnapshotContainsExpectedFields(): void
    {
        [$customer, $instance, $track] = $this->makeEntities(filePath: 'tracks/song.mp3');
        $queueItem = $this->makeQueueItem($instance, $track, 0);
        $service = $this->buildService([$queueItem]);

        $snapshot = $service->buildQueueSnapshot($instance);

        self::assertSame('42', $snapshot['instance_id']);
        self::assertCount(1, $snapshot['items']);
        $item = $snapshot['items'][0];
        self::assertSame('My Track', $item['title']);
        self::assertSame('upload', $item['source']['type']);
        self::assertSame('tracks/song.mp3', $item['source']['uri']);
        self::assertSame('audio/mpeg', $item['source']['mime_type']);
        self::assertArrayHasKey('revision', $snapshot);
        self::assertArrayHasKey('generated_at', $snapshot);
    }

    public function testQueueSyncPayloadNeverContainsOtherInstanceData(): void
    {
        [$customer, $instance, $track] = $this->makeEntities(filePath: 'tracks/mine.mp3');
        $queueItem = $this->makeQueueItem($instance, $track, 0);
        $dispatchLog = [];
        $service = $this->buildService([$queueItem], $dispatchLog);

        $service->addTrackToQueue($customer, $instance, $track);

        self::assertNotEmpty($dispatchLog);
        $payload = $dispatchLog[0]['payload'];
        self::assertSame('42', $payload['queue']['instance_id']);
        foreach ($payload['queue']['items'] as $item) {
            self::assertStringContainsString('mine', $item['source']['uri'] ?? '');
        }
    }
}
