<?php

declare(strict_types=1);

namespace App\Tests\Module\Setup\Application;

use App\Module\Setup\Application\WebinterfaceUpdateService;
use App\Module\Setup\Application\WebinterfaceUpdateSettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpClient\MockHttpClient;

final class WebinterfaceUpdateServicePostUpdateTest extends TestCase
{
    public function testApplyMigrationsRunsPostUpdateCommandsSuccessfully(): void
    {
        $appRoot = $this->createFakeAppRoot([
            'doctrine:migrations:migrate' => 0,
            'doctrine:schema:validate' => 0,
            'app:settings:ensure-defaults' => 0,
            'cache:clear' => 0,
        ]);
        $logger = new CapturingLogger();
        $service = $this->newService($appRoot, $logger);

        $result = $service->applyMigrations();

        self::assertTrue($result->success, (string) $result->error);
        self::assertSame([
            'doctrine:migrations:migrate',
            'doctrine:schema:validate',
            'app:settings:ensure-defaults',
            'cache:clear',
        ], $this->readCommandLog($appRoot));
        self::assertStringContainsString('doctrine:schema:validate exit code: 0', (string) file_get_contents($result->logPath ?? ''));
        self::assertNotEmpty($logger->records);
    }

    public function testFailedMigrationCommandFailsUpdateAndStopsFurtherCommands(): void
    {
        $appRoot = $this->createFakeAppRoot([
            'doctrine:migrations:migrate' => 7,
            'doctrine:schema:validate' => 0,
            'app:settings:ensure-defaults' => 0,
            'cache:clear' => 0,
        ]);
        $service = $this->newService($appRoot, new CapturingLogger());

        $result = $service->applyMigrations();

        self::assertFalse($result->success);
        self::assertSame('Migrationen fehlgeschlagen.', $result->message);
        self::assertStringContainsString('Doctrine migration stderr', (string) $result->error);
        self::assertSame(['doctrine:migrations:migrate'], $this->readCommandLog($appRoot));
    }

    public function testSchemaValidationFailureIsNotReportedAsSuccessful(): void
    {
        $appRoot = $this->createFakeAppRoot([
            'doctrine:migrations:migrate' => 0,
            'doctrine:schema:validate' => 3,
            'app:settings:ensure-defaults' => 0,
            'cache:clear' => 0,
        ]);
        $service = $this->newService($appRoot, new CapturingLogger());

        $result = $service->applyMigrations();

        self::assertFalse($result->success);
        self::assertSame('Schema-Validierung fehlgeschlagen.', $result->message);
        self::assertStringContainsString('Schema validation stderr', (string) $result->error);
        self::assertSame(['doctrine:migrations:migrate', 'doctrine:schema:validate'], $this->readCommandLog($appRoot));
    }

    public function testRunCommandPreservesStdoutAndStderr(): void
    {
        $appRoot = $this->createFakeAppRoot([]);
        $service = $this->newService($appRoot, new CapturingLogger());
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('runCommand');
        $method->setAccessible(true);

        /** @var array{exitCode:int, stdout:string, stderr:string, output:string} $result */
        $result = $method->invoke($service, [PHP_BINARY, '-r', 'fwrite(STDOUT, "out-line\\n"); fwrite(STDERR, "err-line\\n"); exit(5);'], $appRoot, 30);

        self::assertSame(5, $result['exitCode']);
        self::assertSame('out-line', $result['stdout']);
        self::assertSame('err-line', $result['stderr']);
        self::assertStringContainsString('out-line', $result['output']);
        self::assertStringContainsString('err-line', $result['output']);
    }

    public function testCleanupKeepOneRemovesOlderReleases(): void
    {
        $appRoot = $this->createFakeAppRoot([]);
        $service = $this->newService($appRoot, new CapturingLogger(), 1);
        $this->seedReleases($appRoot, ['1.0.0', '1.1.0', '1.2.0']);

        $this->invokeCleanup($service, $appRoot . '/releases/1.2.0', $appRoot . '/update.log');

        self::assertDirectoryExists($appRoot . '/releases/1.2.0');
        self::assertDirectoryDoesNotExist($appRoot . '/releases/1.1.0');
        self::assertDirectoryDoesNotExist($appRoot . '/releases/1.0.0');
    }

    public function testCleanupKeepTwoKeepsCurrentAndPrevious(): void
    {
        $appRoot = $this->createFakeAppRoot([]);
        $service = $this->newService($appRoot, new CapturingLogger(), 2);
        $this->seedReleases($appRoot, ['1.0.0', '1.1.0', '1.2.0']);

        $this->invokeCleanup($service, $appRoot . '/releases/1.2.0', $appRoot . '/update.log');

        self::assertDirectoryExists($appRoot . '/releases/1.2.0');
        self::assertDirectoryExists($appRoot . '/releases/1.1.0');
        self::assertDirectoryDoesNotExist($appRoot . '/releases/1.0.0');
    }

    public function testCleanupNeverDeletesCurrentSymlinkTarget(): void
    {
        $appRoot = $this->createFakeAppRoot([]);
        $service = $this->newService($appRoot, new CapturingLogger(), 1);
        $this->seedReleases($appRoot, ['1.0.0', '1.1.0']);

        $this->invokeCleanup($service, $appRoot . '/releases/1.0.0', $appRoot . '/update.log');

        self::assertDirectoryExists($appRoot . '/releases/1.0.0');
    }

    /**
     * @param array<string, int> $exitCodes
     */
    private function createFakeAppRoot(array $exitCodes): string
    {
        $appRoot = sys_get_temp_dir() . '/easywi-update-test-' . bin2hex(random_bytes(6));
        mkdir($appRoot . '/bin', 0770, true);
        file_put_contents($appRoot . '/VERSION', '1.0.0' . PHP_EOL);
        $script = <<<'PHP_SCRIPT'
<?php
$command = $argv[1] ?? '';
$map = json_decode((string) getenv('EASYWI_TEST_EXIT_CODES'), true) ?: [];
file_put_contents(__DIR__ . '/../commands.log', $command . PHP_EOL, FILE_APPEND);
if ($command === 'doctrine:migrations:migrate') {
    fwrite(STDOUT, "Doctrine migration stdout\n");
    fwrite(STDERR, "Doctrine migration stderr\n");
} elseif ($command === 'doctrine:schema:validate') {
    fwrite(STDOUT, "Schema validation stdout\n");
    fwrite(STDERR, "Schema validation stderr\n");
} elseif ($command === 'cache:clear') {
    fwrite(STDOUT, "Cache clear stdout\n");
}
exit((int) ($map[$command] ?? 0));
PHP_SCRIPT;
        file_put_contents($appRoot . '/bin/console', $script);
        putenv('EASYWI_TEST_EXIT_CODES=' . json_encode($exitCodes, JSON_THROW_ON_ERROR));

        return $appRoot;
    }

    /**
     * @return list<string>
     */
    private function readCommandLog(string $appRoot): array
    {
        $path = $appRoot . '/commands.log';
        if (!is_file($path)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", (string) file_get_contents($path)))));
    }

    private function newService(string $appRoot, CapturingLogger $logger, int $keepReleases = 2): WebinterfaceUpdateService
    {
        return new WebinterfaceUpdateService(
            new MockHttpClient(),
            new WebinterfaceUpdateSettingsService($appRoot . '/settings'),
            'https://example.invalid/manifest.json',
            $appRoot,
            $appRoot . '/releases',
            $appRoot . '/current',
            $appRoot . '/var/update.lock',
            '',
            '1.0.0',
            'Matlord93/Easy-Wi-NextGen',
            'stable',
            'test',
            false,
            $keepReleases,
            null,
            null,
            null,
            $logger,
        );
    }

    /** @param list<string> $versions */
    private function seedReleases(string $appRoot, array $versions): void
    {
        @mkdir($appRoot . '/releases', 0770, true);
        foreach ($versions as $index => $version) {
            $dir = $appRoot . '/releases/' . $version;
            mkdir($dir, 0770, true);
            file_put_contents($dir . '/VERSION', $version);
            touch($dir, time() - (count($versions) - $index) * 10);
        }
    }

    private function invokeCleanup(WebinterfaceUpdateService $service, string $currentReleaseDir, string $logPath): void
    {
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('cleanupOldReleases');
        $method->setAccessible(true);
        $method->invoke($service, $currentReleaseDir, $logPath);
    }
}

final class CapturingLogger extends AbstractLogger
{
    /** @var list<array{level:mixed, message:string, context:array<string,mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
