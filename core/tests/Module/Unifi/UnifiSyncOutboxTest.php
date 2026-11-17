<?php

declare(strict_types=1);

namespace App\Tests\Module\Unifi;

use App\Module\Unifi\Application\QueuedUnifiSynchronizerInterface;
use App\Module\Unifi\Application\UnifiSyncOutbox;
use App\Module\Unifi\Application\UnifiSyncOutboxWorker;
use App\Module\Unifi\Domain\Entity\UnifiSyncRequest;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class UnifiSyncOutboxTest extends TestCase
{
    private Connection $connection;
    private UnifiSyncOutbox $outbox;

    #[\Override]
    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 4).'/src'], true);
        $config->enableNativeLazyObjects(true);
        $em = new EntityManager($this->connection, $config);
        (new SchemaTool($em))->createSchema([$em->getClassMetadata(UnifiSyncRequest::class)]);
        $this->outbox = new UnifiSyncOutbox($this->connection);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testRolledBackAllocationCannotLeaveAnOutboxRequestOrRunAWorker(): void
    {
        $this->connection->beginTransaction();
        $this->outbox->enqueue(42);
        $sync = $this->createMock(QueuedUnifiSynchronizerInterface::class);
        $sync->expects($this->never())->method('syncQueued');
        try {
            (new UnifiSyncOutboxWorker($this->outbox, $sync, new NullLogger()))->drain();
            self::fail('Work must not run inside the allocation transaction.');
        } catch (\LogicException $expected) {
            self::assertStringContainsString('outside an open transaction', $expected->getMessage());
        } finally {
            $this->connection->rollBack();
        }
        self::assertSame([], $this->outbox->dueIds());
    }

    public function testRepeatedEventsCoalesceAndNewerChangesSurviveOldAcknowledgement(): void
    {
        $this->enqueue(42);
        $this->enqueue(42);
        $claim = $this->outbox->claim(42);
        self::assertNotNull($claim);
        self::assertSame(2, $claim->revision);
        self::assertNull($this->outbox->claim(42), 'A second worker must not claim a live lease.');
        $this->enqueue(42);
        $this->outbox->acknowledge($claim);
        $fresh = $this->outbox->claim(42);
        self::assertNotNull($fresh);
        self::assertSame(3, $fresh->revision);
        self::assertSame(1, $fresh->attempts);
        $this->outbox->acknowledge($fresh);
        self::assertSame([], $this->outbox->dueIds());
    }

    public function testFailuresHaveDurableExponentialBackoffAndDoNotLoseNewerChanges(): void
    {
        $now = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $this->enqueue(42, $now);
        $claim = $this->outbox->claim(42, $now);
        self::assertNotNull($claim);
        $this->outbox->retry($claim, 'router offline', $now);
        self::assertSame([], $this->outbox->dueIds(20, $now->modify('+29 seconds')));
        self::assertSame([42], $this->outbox->dueIds(20, $now->modify('+30 seconds')));
        $second = $this->outbox->claim(42, $now->modify('+30 seconds'));
        self::assertNotNull($second);
        self::assertSame(2, $second->attempts);
        $this->enqueue(42, $now->modify('+31 seconds'));
        $this->outbox->retry($second, 'old request failed', $now->modify('+32 seconds'));
        self::assertSame([42], $this->outbox->dueIds(20, $now->modify('+32 seconds')));
        self::assertNull($this->connection->fetchOne('SELECT last_error FROM unifi_sync_requests'));
    }

    public function testStaleWorkerCannotRenewOrAcknowledgeTheNewOwner(): void
    {
        $now = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $this->enqueue(42, $now);
        $old = $this->outbox->claim(42, $now);
        self::assertNotNull($old);
        $new = $this->outbox->claim(42, $now->modify('+301 seconds'));
        self::assertNotNull($new);
        $this->outbox->acknowledge($old);
        self::assertSame($new->token, $this->connection->fetchOne('SELECT claim_token FROM unifi_sync_requests'));
        $this->expectException(\RuntimeException::class);
        $this->outbox->renew($old, $now->modify('+302 seconds'));
    }

    public function testWorkerAcknowledgesSuccessAndRetriesReportedFailures(): void
    {
        $this->enqueue(42);
        $calls = [];
        $sync = new class($calls) implements QueuedUnifiSynchronizerInterface {
            public bool $fail = true;
            public function __construct(public array &$calls) {}
            #[\Override]
            public function syncQueued(int $instanceId, callable $heartbeat): array
            {
                $heartbeat();
                $this->calls[] = $instanceId;
                return ['status' => $this->fail ? 'error' : 'success'];
            }
        };
        $worker = new UnifiSyncOutboxWorker($this->outbox, $sync, new NullLogger());
        self::assertSame(['attempted' => 1, 'succeeded' => 0, 'failed' => 1, 'skipped' => 0], $worker->drain());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM unifi_sync_requests'));
        $this->connection->executeStatement('UPDATE unifi_sync_requests SET available_at = ?', ['2000-01-01 00:00:00']);
        $sync->fail = false;
        self::assertSame(['attempted' => 1, 'succeeded' => 1, 'failed' => 0, 'skipped' => 0], $worker->drain());
        self::assertSame([42, 42], $calls);
        self::assertSame([], $this->outbox->dueIds());
    }

    public function testDisabledIntegrationDefersWorkWithoutReportingAppliedRules(): void
    {
        $this->enqueue(42);
        $sync = $this->createStub(QueuedUnifiSynchronizerInterface::class);
        $sync->method('syncQueued')->willReturn(['status' => 'disabled']);
        $worker = new UnifiSyncOutboxWorker($this->outbox, $sync, new NullLogger());
        self::assertSame(['attempted' => 1, 'succeeded' => 0, 'failed' => 0, 'skipped' => 1], $worker->drain());
        self::assertSame([], $this->outbox->dueIds());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM unifi_sync_requests'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT attempts FROM unifi_sync_requests'));
        $this->connection->executeStatement('UPDATE unifi_sync_requests SET available_at = ?', ['2000-01-01 00:00:00']);
        self::assertSame([42], $this->outbox->dueIds());
    }

    private function enqueue(int $id, ?\DateTimeImmutable $now = null): void
    {
        $this->connection->transactional(fn () => $this->outbox->enqueue($id, $now));
    }
}
