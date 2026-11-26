<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\AppSettingsServiceInterface;
use App\Module\Core\Application\JobHistoryPolicy;
use App\Module\Core\Application\JobHistoryRetention;
use App\Module\Core\Application\JobLogger;
use App\Module\Core\Application\MetricsRetentionService;
use App\Module\Core\Application\SecretsCryptoInterface;
use App\Module\Core\Application\Scheduler\CentralSchedulerRunner;
use App\Module\Core\Application\Scheduler\JobsCleanupScheduleHandler;
use App\Module\Core\Application\Scheduler\MetricsCleanupScheduleHandler;
use App\Module\Core\Application\Scheduler\ScheduleExecutionResult;
use App\Module\Core\Application\Scheduler\ScheduleHandlerInterface;
use App\Module\Core\Application\Scheduler\ScheduleHandlerRegistry;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Backup;
use App\Module\Core\Domain\Entity\BackupDefinition;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\JobLog;
use App\Module\Core\Domain\Entity\JobResult;
use App\Module\Core\Domain\Entity\MetricAggregate;
use App\Module\Core\Domain\Entity\MetricSample;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\InstanceMetricSample;
use App\Module\Core\Domain\Entity\Template;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\AgentOrchestrator\Domain\Enum\AgentJobStatus;
use App\Module\Core\Domain\Entity\ScheduledTaskRun;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\BackupStatus;
use App\Module\Core\Domain\Enum\BackupTargetType;
use App\Module\Core\Domain\Enum\JobResultStatus;
use App\Module\Core\Domain\Enum\JobStatus;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Nodes\Domain\Entity\KvmGuestAssignment;
use App\Module\Nodes\Application\VmPasswordDelivery;
use App\Module\Nodes\Domain\Entity\ProxmoxCluster;
use App\Module\Nodes\Domain\Entity\ProxmoxGuestAssignment;
use App\Module\Nodes\Domain\Proxmox\ProxmoxConnection;
use App\Repository\JobRepositoryInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class HistoryRetentionTest extends KernelTestCase
{
    private array $previousDatabase;
    private EntityManagerInterface $em;
    private Connection $db;
    private AppSettingsServiceInterface $settings;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->previousDatabase = [$_SERVER['DATABASE_URL'] ?? null, $_ENV['DATABASE_URL'] ?? null];
        $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->db->executeStatement('PRAGMA foreign_keys = ON');
        $this->settings = self::getContainer()->get(AppSettingsServiceInterface::class);
        $this->now = new \DateTimeImmutable('2026-11-18 12:00:00 UTC');
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

    public static function modes(): iterable
    {
        yield 'all' => ['all', 3, 0];
        yield 'customers' => ['customers', 2, 1];
        yield 'errors' => ['errors', 1, 2];
    }

    #[DataProvider('modes')]
    public function testHistoryFiltersAndCleanupAgreeAndActiveJobsRemainDispatchable(string $mode, int $visible, int $deleted): void
    {
        $this->settings->updateSettings([AppSettingsService::KEY_JOB_HISTORY_MODE => $mode]);
        $this->job('instance.restart', JobStatus::Succeeded, '-2 days');
        $this->job('node.disk.stat', JobStatus::Succeeded, '-2 days');
        $error = $this->job('server.update.run', JobStatus::Failed, '-2 days');
        $this->em->flush();
        $jobs = self::getContainer()->get(JobRepositoryInterface::class);
        self::assertSame($visible, $jobs->countAll());
        self::assertCount($visible, $jobs->findLatestHistory());
        self::assertCount($visible, $jobs->findPaginatedLatest(1)['jobs']);
        self::assertSame(1, $jobs->countByStatus(JobStatus::Failed));
        $active = $this->job('node.disk.stat', JobStatus::Queued, '-200 days');
        $running = $this->job('instance.restart', JobStatus::Running, '-200 days');
        $fresh = $this->job('node.disk.stat', JobStatus::Succeeded, '-1 hour');
        $this->em->flush();
        self::assertSame($deleted, self::getContainer()->get(JobHistoryRetention::class)->cleanup($this->now)['jobs_deleted']);
        foreach ([$active, $running, $fresh, $error] as $job) {
            self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM jobs WHERE id = ?', [$job->getId()]));
        }
        self::assertCount(1, $jobs->findBy(['status' => JobStatus::Queued]));
        self::assertContains($active->getId(), array_map(static fn (Job $job): string => $job->getId(), $jobs->findLatest()));
    }

    public function testExpiredErrorAndSuccessResultsAndLogsAreRemovedButBackupReferencesSurvive(): void
    {
        $this->settings->updateSettings([AppSettingsService::KEY_DATABASE_LOGGING_ROUTINE_RETENTION_DAYS => 3, AppSettingsService::KEY_DATABASE_LOGGING_ERROR_RETENTION_DAYS => 7]);
        $oldSuccess = $this->job('instance.restart', JobStatus::Succeeded, '-4 days');
        $oldError = $this->job('server.update.run', JobStatus::Failed, '-8 days');
        $keepError = $this->job('server.update.run', JobStatus::Failed, '-6 days');
        $keepBoundary = $this->job('instance.restart', JobStatus::Succeeded, '-3 days');
        foreach ([$oldSuccess, $oldError, $keepError] as $job) {
            $this->em->persist(new JobLog($job, 'retention fixture'));
            $this->em->persist(new JobResult($job, $job->getStatus() === JobStatus::Failed ? JobResultStatus::Failed : JobResultStatus::Succeeded, ['error' => 'fixture'], $this->now));
        }
        foreach ([[$oldSuccess, '-4 days'], [$oldError, '-8 days'], [$keepError, '-6 days']] as [$job, $age]) {
            (new \ReflectionProperty(Job::class, 'updatedAt'))->setValue($job, $this->now->modify($age));
        }
        $backupJob = $this->job('instance.backup.create', JobStatus::Succeeded, '-100 days');
        $customer = new User('retention@example.test', UserType::Customer);
        $customer->setPasswordHash('fixture-only');
        $definition = new BackupDefinition($customer, BackupTargetType::Game, '1', 'Keep backup metadata');
        $backup = new Backup($definition, BackupStatus::Succeeded);
        $backup->setJob($backupJob);
        foreach ([$customer, $definition, $backup] as $entity) { $this->em->persist($entity); }
        $this->em->flush();
        self::assertSame(2, self::getContainer()->get(JobHistoryRetention::class)->cleanup($this->now)['jobs_deleted']);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM job_results'));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM job_logs'));
        foreach ([$keepError, $keepBoundary, $backupJob] as $job) {
            self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM jobs WHERE id = ?', [$job->getId()]));
        }
    }

    public function testRoutineJobLogWritesStopInErrorModeButFailureOutputAndProgressRemain(): void
    {
        $this->settings->updateSettings([AppSettingsService::KEY_JOB_HISTORY_MODE => 'errors']);
        $running = $this->job('node.disk.stat', JobStatus::Running, '-1 hour');
        $logger = self::getContainer()->get(JobLogger::class);
        $logger->log($running, 'Job started.', 10);
        $running->transitionTo(JobStatus::Failed);
        $logger->log($running, 'Job failed.', 100);
        $this->em->persist(new JobResult($running, JobResultStatus::Failed, ['error' => 'Original detailed error']));
        $this->em->flush();
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM job_logs'));
        self::assertSame(100, $running->getProgress());
        self::assertStringContainsString('Original detailed error', $this->db->fetchOne('SELECT output FROM job_results'));
    }

    public function testJobCleanupRemovesExpiredVmPasswordHandoffsForBothProviders(): void
    {
        $customer = new User('vm-password-retention@example.test', UserType::Customer);
        $customer->setPasswordHash('fixture-only');
        $agent = new Agent('vm-password-retention', ['key_id' => 'fixture', 'nonce' => 'fixture', 'ciphertext' => 'fixture']);
        $cluster = new ProxmoxCluster('Retention fixture', new ProxmoxConnection('https://pve.example.test', 'panel@pve!fixture', 'fixture-token'), self::getContainer()->get(SecretsCryptoInterface::class));
        foreach ([$customer, $agent, $cluster] as $entity) {
            $this->em->persist($entity);
        }
        $assignments = [];
        foreach ([-1, 0, 1, null] as $i => $secondsToExpiry) {
            $handoff = $secondsToExpiry === null ? null : ['operation' => 'fixture-'.$i, 'expires_at' => $this->now->getTimestamp() + $secondsToExpiry, 'ciphertext' => 'fixture-ciphertext'];
            $kvm = new KvmGuestAssignment($agent, sprintf('11111111-1111-4111-8111-%012d', $i), $customer);
            $proxmox = new ProxmoxGuestAssignment($cluster, 100 + $i, 'qemu', $customer, 'easywi-owned-0123456789abcdef');
            foreach ([$kvm, $proxmox] as $assignment) {
                $assignment->setRootPasswordHandoff($handoff);
                $this->em->persist($assignment);
                $assignments[] = [$assignment, $secondsToExpiry === 1 ? $handoff : null];
            }
        }
        $this->em->flush();

        $container = self::getContainer();
        self::assertInstanceOf(JobsCleanupScheduleHandler::class, $container->get(ScheduleHandlerRegistry::class)->get('cleanup.jobs'));
        $handler = new JobsCleanupScheduleHandler($container->get(JobHistoryRetention::class), new ArrayAdapter(), $container->get(VmPasswordDelivery::class));
        $first = $handler->runDue($this->now);
        self::assertSame('success', $first->status);
        self::assertStringContainsString('4 expired VM password handoffs', $first->message ?? '');
        self::assertSame('skipped', $handler->runDue($this->now)->status);
        foreach ($assignments as [$assignment, $expected]) {
            $this->em->refresh($assignment);
            self::assertSame($expected, $assignment->getRootPasswordHandoff());
            self::assertSame($customer->getId(), $assignment->getCustomer()->getId());
        }

        $second = $handler->runNow('system', 'cleanup.jobs', $this->now);
        self::assertSame('success', $second->status);
        self::assertStringContainsString('0 expired VM password handoffs', $second->message ?? '');
        self::assertStringContainsString('2 expired VM password handoffs', $handler->runNow('system', 'cleanup.jobs', $this->now->modify('+1 second'))->message ?? '');
    }

    public function testCentralSchedulerExecutesAllHandlersButPersistsOnlyFailuresInErrorMode(): void
    {
        $this->settings->updateSettings([AppSettingsService::KEY_JOB_HISTORY_MODE => 'errors']);
        $calls = 0;
        $handlers = [];
        foreach (['success', 'skipped', 'failed'] as $status) {
            $handler = $this->createStub(ScheduleHandlerInterface::class);
            $handler->method('type')->willReturn('fixture.'.$status);
            $handler->method('runDue')->willReturnCallback(static function () use (&$calls, $status): ScheduleExecutionResult {
                ++$calls;
                return new ScheduleExecutionResult($status, 'Fixture '.$status);
            });
            $handlers[] = $handler;
        }
        $runner = new CentralSchedulerRunner(new ScheduleHandlerRegistry($handlers), $this->em, new NullLogger(), self::getContainer()->get(JobHistoryPolicy::class));
        $runner->runDue($this->now);
        self::assertSame(3, $calls);
        self::assertSame(['failed'], $this->db->fetchFirstColumn('SELECT status FROM scheduled_task_runs'));
    }

    public function testMetricsRetentionHonorsIndependentSettingsIncludingDisabledInfrastructureAndRecentBoundaries(): void
    {
        $this->settings->updateSettings([AppSettingsService::KEY_METRICS_RETENTION_DAYS => 2, AppSettingsService::KEY_METRICS_INSTANCE_RETENTION_DAYS => 1, AppSettingsService::KEY_INFRA_MONITORING => ['enabled' => false, 'retention_days' => 3]]);
        $agent = new Agent('metric-retention', ['key_id' => 'fixture', 'nonce' => 'fixture', 'ciphertext' => 'fixture']);
        $this->em->persist($agent);
        $customer = new User('metrics-retention@example.test', UserType::Customer);
        $customer->setPasswordHash('fixture-only');
        $template = new Template('fixture', 'Fixture', null, null, null, [], '', [], [], [], [], '', '', [], []);
        $instance = new Instance($customer, $template, $agent, 100, 1024, 1024, null, InstanceStatus::Stopped);
        foreach ([$customer, $template, $instance] as $entity) { $this->em->persist($entity); }
        foreach (['-2 days', '-1 day', '-1 hour'] as $age) {
            $this->em->persist(new InstanceMetricSample($instance, 10, 1024, 2, $this->now->modify($age), null));
        }
        foreach (['-3 days', '-2 days', '-1 day'] as $age) {
            $this->em->persist(new MetricSample($agent, $this->now->modify($age), 10, 20, 30, null, null));
            $this->em->persist(new MetricAggregate($agent, '1h', $this->now->modify($age)));
        }
        $this->em->flush();
        foreach (['infrastructure_samples', 'infrastructure_rollups'] as $table) {
            foreach (['-4 days', '-3 days', '-2 days'] as $age) {
                $row = ['resource_key' => 'fixture', 'stream' => 'load', 'bucket' => $this->now->modify($age)->getTimestamp(), 'payload' => '{}'];
                if ($table === 'infrastructure_samples') { $row['observed_at'] = $row['bucket']; }
                $this->db->insert($table, $row);
            }
        }
        $handler = new MetricsCleanupScheduleHandler(self::getContainer()->get(MetricsRetentionService::class), new ArrayAdapter());
        self::assertSame('success', $handler->runDue($this->now)->status);
        self::assertSame('skipped', $handler->runDue($this->now)->status);
        foreach (['metric_samples', 'metric_aggregates', 'instance_metric_samples', 'infrastructure_samples', 'infrastructure_rollups'] as $table) {
            self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM '.$table));
        }
    }

    public function testAgentJobsAndTaskHistoryUseTheSameRetentionModes(): void
    {
        $this->settings->updateSettings([AppSettingsService::KEY_JOB_HISTORY_MODE => 'errors']);
        $agent = new Agent('agent-job-retention', ['key_id' => 'fixture', 'nonce' => 'fixture', 'ciphertext' => 'fixture']);
        $this->em->persist($agent);
        foreach ([[AgentJobStatus::Success, '-2 days'], [AgentJobStatus::Failed, '-2 days'], [AgentJobStatus::Running, '-200 days']] as $index => [$status, $age]) {
            $job = new AgentJob('fixture-'.$index, $agent, 'kvm.vm.list', []);
            if ($status === AgentJobStatus::Running) { $job->markRunning($this->now->modify($age)); }
            else { $job->markFinished($status, $this->now->modify($age)); }
            $this->em->persist($job);
        }
        foreach (['success', 'failed', 'running'] as $status) {
            $run = new ScheduledTaskRun('handler', 'fixture', 'Fixture', 'fixture', 'core', $this->now->modify('-2 days'), $status);
            $this->em->persist($run);
        }
        $this->em->flush();
        $result = self::getContainer()->get(JobHistoryRetention::class)->cleanup($this->now);
        self::assertSame(1, $result['agent_jobs_deleted']);
        self::assertSame(1, $result['task_runs_deleted']);
        self::assertSame(['failed', 'running'], $this->db->fetchFirstColumn('SELECT status FROM agent_jobs ORDER BY id'));
        self::assertSame(['failed', 'running'], $this->db->fetchFirstColumn('SELECT status FROM scheduled_task_runs ORDER BY id'));
    }

    public function testSchedulerHeartbeatCannotOverwriteChangedRetentionAndResetReloadsSettings(): void
    {
        $this->settings->updateSettings(['metrics_retention_days' => 30, 'job_history_mode' => 'all']);
        self::assertSame(30, $this->settings->getSettings()['metrics_retention_days']);
        // Simulate an administrator saving from a different request/EntityManager.
        $this->db->executeStatement('UPDATE app_settings SET value = ? WHERE setting_key = ?', ['2', 'metrics_retention_days']);
        $this->db->executeStatement('UPDATE app_settings SET value = ? WHERE setting_key = ?', ['"errors"', 'job_history_mode']);
        $this->em->clear();
        $this->settings->markSchedulerHeartbeat('running', $this->now);
        self::assertSame('2', (string) $this->db->fetchOne('SELECT value FROM app_settings WHERE setting_key = ?', ['metrics_retention_days']));
        self::assertSame('"errors"', $this->db->fetchOne('SELECT value FROM app_settings WHERE setting_key = ?', ['job_history_mode']));
        $agent = new Agent('pending-during-settings-reset', ['key_id' => 'fixture', 'nonce' => 'fixture', 'ciphertext' => 'fixture']);
        $this->em->persist($agent);
        $this->settings->reset();
        self::assertTrue($this->em->contains($agent));
        self::assertSame(2, $this->settings->getSettings()['metrics_retention_days']);
        self::assertSame('errors', $this->settings->getSettings()['job_history_mode']);
    }

    public function testExistingLongRetentionIsNeverShortenedToADefault(): void
    {
        $this->settings->updateSettings(['database_logging_routine_retention_days' => 6000, 'database_logging_error_retention_days' => 6000, 'metrics_retention_days' => 6000]);
        $this->job('instance.restart', JobStatus::Succeeded, '-200 days');
        $this->job('node.disk.stat', JobStatus::Failed, '-200 days');
        $agent = new Agent('long-retention', ['key_id' => 'fixture', 'nonce' => 'fixture', 'ciphertext' => 'fixture']);
        $this->em->persist($agent);
        $this->em->persist(new MetricSample($agent, $this->now->modify('-40 days'), 10, 20, 30, null, null));
        $this->em->flush();
        self::assertSame(0, self::getContainer()->get(JobHistoryRetention::class)->cleanup($this->now)['jobs_deleted']);
        self::assertSame(0, self::getContainer()->get(MetricsRetentionService::class)->cleanup($this->now)['metric_samples_deleted']);
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM jobs'));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM metric_samples'));
    }

    private function job(string $type, JobStatus $status, string $age): Job
    {
        $job = new Job($type, ['agent_id' => 'fixture-agent']);
        if ($status !== JobStatus::Queued) { $job->transitionTo(JobStatus::Running); }
        if ($status !== JobStatus::Queued && $status !== JobStatus::Running) { $job->transitionTo($status); }
        (new \ReflectionProperty(Job::class, 'updatedAt'))->setValue($job, $this->now->modify($age));
        $this->em->persist($job);
        return $job;
    }
}
