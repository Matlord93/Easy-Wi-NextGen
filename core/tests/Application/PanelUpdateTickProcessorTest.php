<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Module\Core\Application\PanelUpdateTickProcessor;
use App\Module\Core\Application\UpdateJobServiceInterface;
use App\Module\Core\Update\UpdateResult;
use App\Module\Setup\Application\WebinterfaceUpdateServiceInterface;
use PHPUnit\Framework\TestCase;

final class PanelUpdateTickProcessorTest extends TestCase
{
    public function testTickProcessesPendingUpdateJobToSuccess(): void
    {
        $dir = $this->createTempDir();
        $jobId = 'job-update';
        $this->writeJob($dir, $jobId, ['id' => $jobId, 'type' => 'update', 'status' => 'pending', 'currentStep' => 'created', 'nextStep' => 'apply_update', 'logPath' => $dir . '/job.log']);

        $jobService = $this->mockJobService($dir, $jobId);
        $updateService = $this->createMock(WebinterfaceUpdateServiceInterface::class);
        $updateService->expects(self::once())->method('applyUpdate')->willReturn(new UpdateResult(true, 'ok', null, $dir . '/job.log', '1', '2'));
        $updateService->expects(self::never())->method('applyMigrations');

        $result = (new PanelUpdateTickProcessor($jobService, $updateService))->tick($jobId);

        self::assertFalse($result['locked']);
        $written = $this->readJob($dir, $jobId);
        self::assertSame('success', $written['status']);
        self::assertSame('done', $written['currentStep']);
        self::assertNull($written['nextStep']);
        self::assertSame(0, $written['exitCode']);
        self::assertNotEmpty($written['startedAt']);
        self::assertNotEmpty($written['finishedAt']);
    }

    public function testTickProcessesPendingMigrateJobToFailure(): void
    {
        $dir = $this->createTempDir();
        $jobId = 'job-migrate';
        $this->writeJob($dir, $jobId, ['id' => $jobId, 'type' => 'migrate', 'status' => 'pending', 'currentStep' => 'created', 'nextStep' => 'apply_migrations', 'logPath' => $dir . '/job.log']);

        $jobService = $this->mockJobService($dir, $jobId);
        $updateService = $this->createMock(WebinterfaceUpdateServiceInterface::class);
        $updateService->expects(self::never())->method('applyUpdate');
        $updateService->expects(self::once())->method('applyMigrations')->willReturn(new UpdateResult(false, 'no', 'schema failed', $dir . '/job.log', '1', null));

        (new PanelUpdateTickProcessor($jobService, $updateService))->tick($jobId);

        $written = $this->readJob($dir, $jobId);
        self::assertSame('failed', $written['status']);
        self::assertSame('apply_migrations', $written['currentStep']);
        self::assertNull($written['nextStep']);
        self::assertSame(1, $written['exitCode']);
        self::assertSame('schema failed', $written['error']);
    }

    public function testBothRunsUpdateFirstAndMigrationsOnNextTick(): void
    {
        $dir = $this->createTempDir();
        $jobId = 'job-both';
        $this->writeJob($dir, $jobId, ['id' => $jobId, 'type' => 'both', 'status' => 'pending', 'currentStep' => 'created', 'nextStep' => 'apply_update', 'logPath' => $dir . '/job.log']);

        $jobService = $this->mockJobService($dir, $jobId);
        $updateService = $this->createMock(WebinterfaceUpdateServiceInterface::class);
        $updateService->expects(self::once())->method('applyUpdate')->willReturn(new UpdateResult(true, 'ok', null, $dir . '/job.log', '1', '2'));
        $updateService->expects(self::never())->method('applyMigrations');
        (new PanelUpdateTickProcessor($jobService, $updateService))->tick($jobId);

        $afterUpdate = $this->readJob($dir, $jobId);
        self::assertSame('running', $afterUpdate['status']);
        self::assertSame('apply_update', $afterUpdate['currentStep']);
        self::assertSame('apply_migrations', $afterUpdate['nextStep']);

        $updateService = $this->createMock(WebinterfaceUpdateServiceInterface::class);
        $updateService->expects(self::never())->method('applyUpdate');
        $updateService->expects(self::once())->method('applyMigrations')->willReturn(new UpdateResult(true, 'ok', null, $dir . '/job.log', '2', null));
        (new PanelUpdateTickProcessor($jobService, $updateService))->tick($jobId);

        $done = $this->readJob($dir, $jobId);
        self::assertSame('success', $done['status']);
        self::assertSame('done', $done['currentStep']);
        self::assertNull($done['nextStep']);
    }

    public function testBothStopsWhenUpdateStepFails(): void
    {
        $dir = $this->createTempDir();
        $jobId = 'job-both-failed-update';
        $this->writeJob($dir, $jobId, ['id' => $jobId, 'type' => 'both', 'status' => 'pending', 'currentStep' => 'created', 'nextStep' => 'apply_update', 'logPath' => $dir . '/job.log']);

        $jobService = $this->mockJobService($dir, $jobId);
        $updateService = $this->createMock(WebinterfaceUpdateServiceInterface::class);
        $updateService->expects(self::once())->method('applyUpdate')->willReturn(new UpdateResult(false, 'update failed', 'download_failed', $dir . '/job.log', '1', '2'));
        $updateService->expects(self::never())->method('applyMigrations');

        (new PanelUpdateTickProcessor($jobService, $updateService))->tick($jobId);

        $written = $this->readJob($dir, $jobId);
        self::assertSame('failed', $written['status']);
        self::assertSame('apply_update', $written['currentStep']);
        self::assertNull($written['nextStep']);
        self::assertSame(1, $written['exitCode']);
        self::assertSame('download_failed', $written['error']);
    }

    public function testConcurrentTickIsBlockedByPerJobFlock(): void
    {
        $dir = $this->createTempDir();
        $jobId = 'job-locked';
        $this->writeJob($dir, $jobId, ['id' => $jobId, 'type' => 'update', 'status' => 'pending', 'currentStep' => 'created', 'nextStep' => 'apply_update', 'logPath' => $dir . '/job.log']);

        $lockHandle = fopen($dir . '/' . $jobId . '.lock', 'c+');
        self::assertIsResource($lockHandle);
        self::assertTrue(flock($lockHandle, LOCK_EX | LOCK_NB));

        $updateService = $this->createMock(WebinterfaceUpdateServiceInterface::class);
        $updateService->expects(self::never())->method('applyUpdate');
        $updateService->expects(self::never())->method('applyMigrations');

        $result = (new PanelUpdateTickProcessor($this->mockJobService($dir, $jobId), $updateService))->tick($jobId);

        self::assertTrue($result['locked']);
        $written = $this->readJob($dir, $jobId);
        self::assertSame('pending', $written['status']);

        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }

    private function createTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/panel-update-tick-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);

        return $dir;
    }

    /** @param array<string, mixed> $job */
    private function writeJob(string $dir, string $jobId, array $job): void
    {
        file_put_contents($dir . '/' . $jobId . '.json', json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        file_put_contents($dir . '/job.log', '');
    }

    /** @return array<string, mixed> */
    private function readJob(string $dir, string $jobId): array
    {
        $decoded = json_decode((string) file_get_contents($dir . '/' . $jobId . '.json'), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function mockJobService(string $dir, string $jobId): UpdateJobServiceInterface
    {
        $jobService = $this->createMock(UpdateJobServiceInterface::class);
        $jobService->method('getJobsDir')->willReturn($dir);
        $jobService->method('getJob')->willReturnCallback(static function (string $id) use ($dir, $jobId): ?array {
            if ($id !== $jobId) {
                return null;
            }
            $path = $dir . '/' . $jobId . '.json';
            $decoded = json_decode((string) file_get_contents($path), true);

            return is_array($decoded) ? $decoded : null;
        });

        return $jobService;
    }
}
