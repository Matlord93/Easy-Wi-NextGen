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
    public function testSuccessfulRunTransitionsToSuccess(): void
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
        $updateService->method('applyUpdate')->willReturn(new UpdateResult(true, 'ok', null, $dir . '/result.log', '1', '2'));

        $command = new UpdateRunCommand($jobService, $updateService);
        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute(['job-id' => $jobId]));

        $written = json_decode((string) file_get_contents($jobPath), true);
        self::assertSame('success', $written['status']);
        self::assertSame(0, $written['exitCode']);
        self::assertNotEmpty($written['startedAt']);
    }

    public function testFailedRunTransitionsToFailed(): void
    {
        $dir = sys_get_temp_dir() . '/update-run-test-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $jobId = 'job-failed';
        $jobPath = $dir . '/' . $jobId . '.json';
        file_put_contents($jobPath, json_encode(['id' => $jobId, 'status' => 'pending', 'logPath' => $dir . '/log.log'], JSON_PRETTY_PRINT));

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
        $updateService->method('applyUpdate')->willReturn(new UpdateResult(false, 'no', 'boom', $dir . '/result.log', '1', '2'));

        $command = new UpdateRunCommand($jobService, $updateService);
        $tester = new CommandTester($command);
        self::assertSame(Command::FAILURE, $tester->execute(['job-id' => $jobId]));

        $written = json_decode((string) file_get_contents($jobPath), true);
        self::assertSame('failed', $written['status']);
        self::assertSame(1, $written['exitCode']);
        self::assertSame('boom', $written['error']);
    }
}
