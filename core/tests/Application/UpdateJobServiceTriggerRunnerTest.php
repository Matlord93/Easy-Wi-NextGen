<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Infrastructure\Config\DbConfigProvider;
use App\Module\Core\Application\UpdateJobService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class UpdateJobServiceTriggerRunnerTest extends TestCase
{
    public function testDetachedPhpRunnerReturnsTrueWhenPidIsReturned(): void
    {
        $dir = $this->createCoreDir('#!/usr/bin/env php\n<?php usleep(700000);\n');
        $service = $this->createService('', $dir);

        self::assertTrue($service->triggerRunner('job-1'));
    }

    public function testDetachedPhpRunnerUsesJobLogPathForOutput(): void
    {
        $dir = $this->createCoreDir('#!/usr/bin/env php\n<?php echo "runner-output\\n";\n');
        $service = $this->createService('', $dir);

        $job = $service->createJob('update', 'tester');
        self::assertTrue($service->triggerRunner((string) $job['id']));

        usleep(900000);

        $logPath = (string) $job['logPath'];
        self::assertFileExists($logPath);
        self::assertStringContainsString('runner-output', (string) file_get_contents($logPath));
    }

    public function testMissingCoreDirectoryReturnsFalse(): void
    {
        $service = $this->createService('', '/path/does/not/exist-core');

        self::assertFalse($service->triggerRunner('job-3'));
    }

    private function createService(string $runnerCommand, ?string $coreDir = null): UpdateJobService
    {
        $coreDir ??= $this->createCoreDir();
        $tmp = sys_get_temp_dir() . '/update-job-test-' . bin2hex(random_bytes(4));
        mkdir($tmp, 0777, true);

        $configProvider = $this->createDbConfigProviderFixture();
        $logger = $this->createMock(LoggerInterface::class);

        return new UpdateJobService(
            $configProvider,
            $logger,
            $tmp,
            $runnerCommand,
            $tmp . '/jobs',
            $tmp . '/logs',
            $tmp . '/backups',
            $coreDir,
            null,
        );
    }


    private function createDbConfigProviderFixture(): DbConfigProvider
    {
        $reflection = new \ReflectionClass(DbConfigProvider::class);

        /** @var DbConfigProvider $provider */
        $provider = $reflection->newInstanceWithoutConstructor();

        return $provider;
    }

    private function createCoreDir(string $consoleScript = '#!/usr/bin/env php\n<?php usleep(700000);\n'): string
    {
        $dir = sys_get_temp_dir() . '/update-core-' . bin2hex(random_bytes(4));
        mkdir($dir . '/bin', 0777, true);
        file_put_contents($dir . '/bin/console', str_replace('\\n', "\n", $consoleScript));
        chmod($dir . '/bin/console', 0755);

        return $dir;
    }
}
