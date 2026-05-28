<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Module\Core\Application\UpdateJobServiceInterface;
use App\Module\Core\Command\UpdateRunCommand;
use App\Module\Core\Update\UpdateResult;
use App\Module\Setup\Application\WebinterfaceUpdateServiceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class UpdateRunCommandTest extends TestCase
{
    public function testRunsUpdateType(): void
    {
        $dir = sys_get_temp_dir() . '/update-run-test-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $jobId = 'job-success';
        $jobPath = $dir . '/' . $jobId . '.json';
        file_put_contents($jobPath, json_encode(['id' => $jobId, 'status' => 'pending', 'logPath' => $dir . '/log.log'], JSON_PRETTY_PRINT));

        $jobService = $this->createMock(UpdateJobServiceInterface::class);
        $jobService->method('getJobsDir')->willReturn($dir);
        $jobService->method('getJob')->willReturnCallback(static function (string $id) use ($jobPath): ?array {
            if ($id !== 'job-success') {
                return null;
            }
            $decoded = json_decode((string) file_get_contents($jobPath), true);
            return is_array($decoded) ? $decoded : null;
        });

        $updateService = $this->createMock(WebinterfaceUpdateServiceInterface::class);
        $updateService->expects(self::once())->method('applyUpdate')->willReturn(new UpdateResult(true, 'ok', null, $dir . '/result.log', '1', '2'));
        $updateService->expects(self::never())->method('applyMigrations');

        $command = new UpdateRunCommand($jobService, $updateService);
        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute(['job-id' => $jobId]));

        $written = json_decode((string) file_get_contents($jobPath), true);
        self::assertSame('success', $written['status']);
        self::assertSame(0, $written['exitCode']);
        self::assertNotEmpty($written['startedAt']);
    }

    public function testRunsMigrateType(): void
    {
        $dir = sys_get_temp_dir() . '/update-run-test-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $jobId = 'job-failed';
        $jobPath = $dir . '/' . $jobId . '.json';
        file_put_contents($jobPath, json_encode(['id' => $jobId, 'status' => 'pending', 'type' => 'migrate', 'logPath' => $dir . '/log.log'], JSON_PRETTY_PRINT));

        $jobService = $this->createMock(UpdateJobServiceInterface::class);
        $jobService->method('getJobsDir')->willReturn($dir);
        $jobService->method('getJob')->willReturnCallback(static function (string $id) use ($jobPath): ?array {
            if ($id !== 'job-failed') {
                return null;
            }
            $decoded = json_decode((string) file_get_contents($jobPath), true);
            return is_array($decoded) ? $decoded : null;
        });

        $updateService = $this->createMock(WebinterfaceUpdateServiceInterface::class);
        $updateService->expects(self::never())->method('applyUpdate');
        $updateService->expects(self::once())->method('applyMigrations')->willReturn(new UpdateResult(false, 'no', 'boom', $dir . '/result.log', '1', '2'));

        $command = new UpdateRunCommand($jobService, $updateService);
        $tester = new CommandTester($command);
        self::assertSame(Command::FAILURE, $tester->execute(['job-id' => $jobId]));

        $written = json_decode((string) file_get_contents($jobPath), true);
        self::assertSame('failed', $written['status']);
        self::assertSame(1, $written['exitCode']);
        self::assertSame('boom', $written['error']);
    }

    public function testBothStopsWhenUpdateFails(): void
    {
        $dir = sys_get_temp_dir() . '/update-run-test-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $jobId = 'job-both';
        $jobPath = $dir . '/' . $jobId . '.json';
        file_put_contents($jobPath, json_encode(['id' => $jobId, 'status' => 'pending', 'type' => 'both', 'logPath' => $dir . '/log.log'], JSON_PRETTY_PRINT));
        $jobService = $this->mockJobService($jobPath, $jobId, $dir);
        $updateService = $this->createMock(WebinterfaceUpdateServiceInterface::class);
        $updateService->expects(self::once())->method('applyUpdate')->willReturn(new UpdateResult(false, 'no', 'boom', $dir . '/result.log', '1', '2'));
        $updateService->expects(self::never())->method('applyMigrations');
        $tester = new CommandTester(new UpdateRunCommand($jobService, $updateService));
        self::assertSame(Command::FAILURE, $tester->execute(['job-id' => $jobId]));
    }

    public function testUnknownTypeFails(): void
    {
        $dir = sys_get_temp_dir() . '/update-run-test-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $jobId = 'job-unknown';
        $jobPath = $dir . '/' . $jobId . '.json';
        file_put_contents($jobPath, json_encode(['id' => $jobId, 'status' => 'pending', 'type' => 'wat', 'logPath' => $dir . '/log.log'], JSON_PRETTY_PRINT));
        $jobService = $this->mockJobService($jobPath, $jobId, $dir);
        $updateService = $this->createMock(WebinterfaceUpdateServiceInterface::class);
        $tester = new CommandTester(new UpdateRunCommand($jobService, $updateService));
        self::assertSame(Command::FAILURE, $tester->execute(['job-id' => $jobId]));
    }

    private function mockJobService(string $jobPath, string $jobId, string $dir): UpdateJobServiceInterface
    {
        $jobService = $this->createMock(UpdateJobServiceInterface::class);
        $jobService->method('getJobsDir')->willReturn($dir);
        $jobService->method('getJob')->willReturnCallback(static function (string $id) use ($jobPath, $jobId): ?array {
            if ($id !== $jobId) {
                return null;
            }
            $decoded = json_decode((string) file_get_contents($jobPath), true);
            return is_array($decoded) ? $decoded : null;
        });
        return $jobService;
    }
}
