<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Core\Application\NodeDiskProtectionService;
use App\Module\Core\Command\DiskScanReconcileCommand;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\MetricSample;
use App\Module\Core\Domain\Enum\JobStatus;
use App\Repository\AgentRepositoryInterface;
use App\Repository\JobRepositoryInterface;
use App\Repository\MetricSampleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CoreTelemetryStabilityTest extends KernelTestCase
{
    private array $previousDatabase;
    private EntityManagerInterface $entities;

    protected function setUp(): void
    {
        $this->previousDatabase = [$_SERVER['DATABASE_URL'] ?? null, $_ENV['DATABASE_URL'] ?? null];
        $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->entities = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($this->entities))->createSchema($this->entities->getMetadataFactory()->getAllMetadata());
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

    public function testDashboardSummaryDoesNotHydrateAllAgentJsonPayloads(): void
    {
        $now = new \DateTimeImmutable('2026-11-18 12:00:00 UTC');
        $repository = self::getContainer()->get(AgentRepositoryInterface::class);
        self::assertSame(['total' => 0, 'online' => 0, 'degraded' => 0, 'offline' => 0], $repository->countHeartbeatStates($now->modify('-5 minutes'), $now->modify('-30 minutes')));
        $times = [null, '-31 minutes', '-30 minutes', '-5 minutes', '-4 minutes', '-1 minute', '-60 minutes', null];
        foreach ($times as $index => $relative) {
            $agent = $this->agent('summary-' . $index);
            $agent->recordHeartbeat(['sample' => str_repeat('x', 16384)], 'test', null);
            (new \ReflectionProperty(Agent::class, 'lastHeartbeatAt'))->setValue($agent, $relative === null ? null : $now->modify($relative));
        }
        $this->entities->flush();
        $this->entities->clear();
        self::assertSame(['total' => 8, 'online' => 3, 'degraded' => 1, 'offline' => 4], $repository->countHeartbeatStates($now->modify('-5 minutes'), $now->modify('-30 minutes')));
        self::assertSame([], $this->entities->getUnitOfWork()->getIdentityMap()[Agent::class] ?? []);
        self::assertCount(6, $repository->findBy([], ['updatedAt' => 'DESC'], 6));
        self::assertCount(6, $this->entities->getUnitOfWork()->getIdentityMap()[Agent::class]);
    }

    public function testSparklineKeepsTheNewestLimitedSamplesInChronologicalOrder(): void
    {
        $agent = $this->agent('sparkline');
        foreach ([10, 20, 30, 40] as $index => $disk) {
            $this->entities->persist(new MetricSample($agent, new \DateTimeImmutable('2026-11-18 12:0' . $index . ':00 UTC'), null, null, $disk, null, null));
        }
        $this->entities->flush();
        $rows = self::getContainer()->get(MetricSampleRepository::class)->findSparklineSeriesForAgentSince($agent, new \DateTimeImmutable('2026-11-18 12:00:00 UTC'), 2);
        self::assertSame([30.0, 40.0], array_column($rows, 'diskPercent'));
        self::assertSame(['12:02', '12:03'], array_map(static fn (array $row): string => $row['recordedAt']->format('H:i'), $rows));
    }

    public function testHeartbeatKeepsDiskScanTimestampAndDoesNotTriggerAnotherScan(): void
    {
        $agent = $this->agent('disk-fresh');
        self::getContainer()->get(NodeDiskProtectionService::class)->updateDiskStat($agent, 1024, 1.0, new \DateTimeImmutable());
        $agent->recordHeartbeat([], 'test', null, [], ['os' => 'linux']);
        $this->entities->flush();
        $command = new CommandTester(self::getContainer()->get(DiskScanReconcileCommand::class));
        self::assertSame(0, $command->execute([]));
        self::assertSame(0, $this->entities->getRepository(Job::class)->count(['type' => 'node.disk.stat']));
        self::assertSame(1024, self::getContainer()->get(NodeDiskProtectionService::class)->getDiskStat($agent)['free_bytes']);
    }

    public function testReconcileDoesNotDuplicatePendingDiskJobsAndRetriesFailedJobs(): void
    {
        $agent = $this->agent('disk-queue');
        $agent->recordHeartbeat(['os' => 'linux'], 'test', null, status: Agent::STATUS_ACTIVE);
        $this->entities->flush();
        $command = new CommandTester(self::getContainer()->get(DiskScanReconcileCommand::class));
        self::assertSame(0, $command->execute([]));
        self::assertSame(0, $command->execute([]));
        $jobs = $this->entities->getRepository(Job::class);
        self::assertSame(1, $jobs->count(['type' => 'node.disk.stat']));
        $job = $jobs->findOneBy(['type' => 'node.disk.stat']);
        $job->transitionTo(JobStatus::Running);
        $this->entities->flush();
        self::assertSame([$agent->getId() => true], self::getContainer()->get(JobRepositoryInterface::class)->findActivePayloadValues('node.disk.stat', 'node_id'));
        self::assertSame(0, $command->execute([]));
        self::assertSame(1, $jobs->count(['type' => 'node.disk.stat']));
        $job->transitionTo(JobStatus::Failed);
        $this->entities->flush();
        self::assertSame(0, $command->execute([]));
        self::assertSame(2, $jobs->count(['type' => 'node.disk.stat']));
    }

    private function agent(string $id): Agent
    {
        $agent = new Agent($id, ['key_id' => 'fixture', 'nonce' => 'fixture', 'ciphertext' => 'fixture']);
        $this->entities->persist($agent);
        return $agent;
    }
}
