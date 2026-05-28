<?php

declare(strict_types=1);

namespace App\Tests\Module\Setup\Application;

use App\Module\Setup\Application\WebinterfaceUpdateService;
use App\Module\Setup\Application\WebinterfaceUpdateSettingsService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WebinterfaceUpdateServiceTest extends TestCase
{
    public function testCheckForUpdateParsesFeedArtifact(): void
    {
        $feed = [
            'latest' => '1.2.3',
            'releases' => [[
                'version' => '1.2.3',
                'artifacts' => [
                    'core_novendor_targz' => [
                        'url' => 'https://github.com/Matlord93/Easy-Wi-NextGen/releases/download/v1.2.3/easywi-core.tar.gz',
                        'sha256' => 'tar123',
                    ],
                    'webinterface_zip' => [
                        'url' => 'https://github.com/Matlord93/Easy-Wi-NextGen/releases/download/v1.2.3/easywi-webinterface-1.2.3.zip',
                        'sha256' => 'zip123',
                        'asset_name' => 'easywi-webinterface-1.2.3.zip',
                    ],
                ],
                'changelog' => 'Fixes',
            ]],
        ];

        $service = $this->newWebinterfaceUpdateService([
            'httpClient' => new MockHttpClient([new MockResponse((string) json_encode($feed))]),
            'settingsService' => new WebinterfaceUpdateSettingsService('/tmp'),
            'manifestUrl' => 'https://example.invalid/feed.json',
            'installDir' => '/tmp/install',
            'releasesDir' => '/tmp/releases',
            'currentSymlink' => '/tmp/current',
            'lockFile' => '/tmp/lock',
            'releaseChannel' => 'stable',
            'kernelEnvironment' => 'test',
            'kernelDebug' => false,
            'fallbackVersion' => '1.0.0',
            'releaseRepository' => 'Matlord93/Easy-Wi-NextGen',
            'excludes' => '',
        ]);

        $status = $service->checkForUpdate();
        self::assertSame('1.2.3', $status->latestVersion);
        self::assertSame('https://github.com/Matlord93/Easy-Wi-NextGen/releases/download/v1.2.3/easywi-webinterface-1.2.3.zip', $status->assetUrl);
        self::assertSame('zip123', $status->assetSha256);
    }

    public function testEasywiPhpBinIsPreferredForConsoleCommands(): void
    {
        $php84 = $this->createFakePhpBinary('usr/bin/php8.4');
        $service = $this->newWebinterfaceUpdateService([
            'phpCliBinary' => $php84,
            'httpClient' => new MockHttpClient(),
            'settingsService' => new WebinterfaceUpdateSettingsService('/tmp'),
            'manifestUrl' => 'https://example.invalid/feed.json',
            'installDir' => '/tmp/install',
            'releasesDir' => '/tmp/releases',
            'currentSymlink' => '/tmp/current',
            'lockFile' => '/tmp/lock',
            'releaseChannel' => 'stable',
            'kernelEnvironment' => 'test',
            'kernelDebug' => false,
            'fallbackVersion' => '1.0.0',
            'releaseRepository' => 'Matlord93/Easy-Wi-NextGen',
            'excludes' => '',
        ]);

        $command = $this->invokeBuildConsoleCommand($service, 'doctrine:migrations:migrate', ['--no-interaction']);

        self::assertSame($php84, $command[0]);
        self::assertStringContainsString('/usr/bin/php8.4', $command[0]);
        self::assertSame('bin/console', $command[1]);
        self::assertSame('doctrine:migrations:migrate', $command[2]);
    }

    public function testPhpFpmBinaryIsRejected(): void
    {
        $phpFpm = $this->createFakePhpBinary('usr/sbin/php-fpm8.4');
        $service = $this->newWebinterfaceUpdateService([
            'phpCliBinary' => $phpFpm,
            'httpClient' => new MockHttpClient(),
            'settingsService' => new WebinterfaceUpdateSettingsService('/tmp'),
            'manifestUrl' => 'https://example.invalid/feed.json',
            'installDir' => '/tmp/install',
            'releasesDir' => '/tmp/releases',
            'currentSymlink' => '/tmp/current',
            'lockFile' => '/tmp/lock',
            'releaseChannel' => 'stable',
            'kernelEnvironment' => 'test',
            'kernelDebug' => false,
            'fallbackVersion' => '1.0.0',
            'releaseRepository' => 'Matlord93/Easy-Wi-NextGen',
            'excludes' => '',
        ]);

        $method = new \ReflectionMethod($service, 'isValidPhpCliBinary');
        $method->setAccessible(true);

        self::assertFalse($method->invoke($service, $phpFpm));
    }

    public function testBuildConsoleCommandUsesPhp84CliAndNeverPhpFpm(): void
    {
        $php84 = $this->createFakePhpBinary('usr/bin/php8.4');
        $service = $this->newWebinterfaceUpdateService([
            'phpCliBinary' => $php84,
            'httpClient' => new MockHttpClient(),
            'settingsService' => new WebinterfaceUpdateSettingsService('/tmp'),
            'manifestUrl' => 'https://example.invalid/feed.json',
            'installDir' => '/tmp/install',
            'releasesDir' => '/tmp/releases',
            'currentSymlink' => '/tmp/current',
            'lockFile' => '/tmp/lock',
            'releaseChannel' => 'stable',
            'kernelEnvironment' => 'test',
            'kernelDebug' => false,
            'fallbackVersion' => '1.0.0',
            'releaseRepository' => 'Matlord93/Easy-Wi-NextGen',
            'excludes' => '',
        ]);

        $command = $this->invokeBuildConsoleCommand($service, 'doctrine:migrations:migrate', ['--no-interaction']);
        $commandLine = implode(' ', $command);

        self::assertStringContainsString('/usr/bin/php8.4', $command[0]);
        self::assertStringNotContainsString('/usr/sbin/php-fpm8.4', $commandLine);
    }

    /**
     * @param array<string, mixed> $namedOverrides
     */
    private function newWebinterfaceUpdateService(array $namedOverrides): WebinterfaceUpdateService
    {
        $namedOverrides = array_merge([
            'keepReleases' => 2,
        ], $namedOverrides);

        $reflection = new \ReflectionClass(WebinterfaceUpdateService::class);
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $args = [];
        foreach ($constructor->getParameters() as $parameter) {
            if (array_key_exists($parameter->getName(), $namedOverrides)) {
                $args[] = $namedOverrides[$parameter->getName()];
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $args[] = $parameter->getDefaultValue();
                continue;
            }

            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $dependency = $this->tryInstantiateDependency($type->getName());
                if ($dependency !== null) {
                    $args[] = $dependency;
                    continue;
                }
            }

            throw new \RuntimeException(sprintf('No test value available for "%s".', $parameter->getName()));
        }

        /** @var WebinterfaceUpdateService $service */
        $service = $reflection->newInstanceArgs($args);

        return $service;
    }

    /**
     * @param list<string> $arguments
     * @return list<string>
     */
    private function invokeBuildConsoleCommand(WebinterfaceUpdateService $service, string $name, array $arguments = []): array
    {
        $method = new \ReflectionMethod($service, 'buildConsoleCommand');
        $method->setAccessible(true);

        return $method->invoke($service, $name, $arguments);
    }

    private function createFakePhpBinary(string $relativePath): string
    {
        $path = sys_get_temp_dir() . '/easywi-php-bin-test-' . bin2hex(random_bytes(6)) . '/' . $relativePath;
        mkdir(dirname($path), 0770, true);
        file_put_contents($path, <<<'PHP_SCRIPT'
#!/bin/sh
if [ "$1" = "-r" ]; then
    echo -n "cli"
    exit 0
fi
exit 0
PHP_SCRIPT);
        chmod($path, 0770);

        return $path;
    }

    private function tryInstantiateDependency(string $className): object|null
    {
        if (!class_exists($className)) {
            return null;
        }

        $dependencyReflection = new \ReflectionClass($className);
        if (!$dependencyReflection->isInstantiable()) {
            return null;
        }

        $constructor = $dependencyReflection->getConstructor();
        if ($constructor === null || $constructor->getNumberOfRequiredParameters() === 0) {
            return $dependencyReflection->newInstance();
        }

        return null;
    }
}
