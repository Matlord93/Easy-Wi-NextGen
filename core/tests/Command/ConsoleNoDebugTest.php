<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Infrastructure\Config\DbConfigProvider;
use App\Infrastructure\Security\CryptoService;
use App\Infrastructure\Security\SecretKeyLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class ConsoleNoDebugTest extends TestCase
{
    public function testCacheClearHonorsNoDebugEvenWhenTheEnvironmentEnablesDebug(): void
    {
        $core = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir().'/easywi-console-no-debug-'.bin2hex(random_bytes(8));
        $filesystem = new Filesystem();
        $filesystem->mkdir($directory);

        try {
            file_put_contents($directory.'/secret.key', base64_encode(random_bytes(32)));
            $keyLoader = new SecretKeyLoader($directory.'/secret.key');
            $database = new DbConfigProvider(new CryptoService($keyLoader), $keyLoader, $directory.'/db.json', $directory);
            // A known platform version lets Doctrine warm metadata without a live DB.
            $database->store(['host' => '127.0.0.1', 'port' => 1, 'dbname' => 'cache_fixture', 'user' => 'cache_fixture', 'serverVersion' => '8.4.0']);

            $process = new Process([PHP_BINARY, $core.'/bin/console', 'cache:clear', '--env=prod', '--no-debug', '--no-ansi'], $core, [
                'APP_ENV' => 'prod',
                'APP_DEBUG' => '1',
                'EASYWI_CACHE_DIR' => $directory.'/cache',
                'EASYWI_LOG_DIR' => $directory.'/log',
                'EASYWI_DB_CONFIG_PATH' => $directory.'/db.json',
                'EASYWI_SECRET_KEY_PATH' => $directory.'/secret.key',
            ]);
            $process->setTimeout(120);
            $process->run();

            self::assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            self::assertStringContainsString('(debug=false)', $process->getOutput());
        } finally {
            $filesystem->remove($directory);
        }
    }
}
