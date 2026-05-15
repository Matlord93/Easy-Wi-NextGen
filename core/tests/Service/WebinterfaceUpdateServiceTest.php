<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Setup\Application\WebinterfaceUpdateService;
use App\Module\Setup\Application\WebinterfaceUpdateSettingsService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WebinterfaceUpdateServiceTest extends TestCase
{
    public function testReportsErrorWhenConfiguredManifestAndReleasePackageAreUnavailable(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('', ['http_code' => 404]),
        ]);

        $tmpDir = sys_get_temp_dir() . '/easywi-update-test-' . bin2hex(random_bytes(4));
        mkdir($tmpDir, 0775, true);
        file_put_contents($tmpDir . '/VERSION', "1.2.2\n");

        $service = $this->newWebinterfaceUpdateService([
            'httpClient' => $httpClient,
            'settingsService' => new WebinterfaceUpdateSettingsService($tmpDir),
            'manifestUrl' => 'https://localhost/manifest.json',
            'installDir' => $tmpDir,
            'releasesDir' => '',
            'currentSymlink' => '',
            'lockFile' => $tmpDir . '/update.lock',
            'fallbackVersion' => '1.2.2',
            'releaseRepository' => 'Matlord93/Easy-Wi-NextGen',
            'releaseChannel' => 'stable',
            'kernelEnvironment' => 'test',
            'kernelDebug' => false,
            'excludes' => '',
        ]);

        $status = $service->checkForUpdate();

        self::assertStringContainsString('Kein gültiges Core-Paket', (string) $status->error);
        self::assertSame('1.2.2', $status->installedVersion);
        self::assertNull($status->latestVersion);
        self::assertNull($status->updateAvailable);
    }

    public function testAcceptsManifestAssetUrlOnlyFromConfiguredRepository(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse(json_encode([
                'latest' => '2.0.0',
                'asset_url' => 'https://github.com/Matlord93/Easy-Wi-NextGen/releases/download/v2.0.0/easywi-core.tar.gz',
                'sha256' => 'abc',
                'notes' => 'Generated fallback notes',
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ]);

        $tmpDir = sys_get_temp_dir() . '/easywi-update-test-' . bin2hex(random_bytes(4));
        mkdir($tmpDir, 0775, true);
        file_put_contents($tmpDir . '/VERSION', "1.9.0\n");

        $service = $this->newWebinterfaceUpdateService([
            'httpClient' => $httpClient,
            'settingsService' => new WebinterfaceUpdateSettingsService($tmpDir),
            'manifestUrl' => 'https://localhost/manifest.json',
            'installDir' => $tmpDir,
            'releasesDir' => '',
            'currentSymlink' => '',
            'lockFile' => $tmpDir . '/update.lock',
            'fallbackVersion' => '1.9.0',
            'releaseRepository' => 'Matlord93/Easy-Wi-NextGen',
            'releaseChannel' => 'stable',
            'kernelEnvironment' => 'test',
            'kernelDebug' => false,
            'excludes' => '',
        ]);

        $status = $service->checkForUpdate();

        self::assertNull($status->error);
        self::assertSame('2.0.0', $status->latestVersion);
        self::assertTrue($status->updateAvailable);
        self::assertSame('Generated fallback notes', $status->notes);
        self::assertSame('https://github.com/Matlord93/Easy-Wi-NextGen/releases/download/v2.0.0/easywi-core.tar.gz', $status->assetUrl);
    }


    public function testFetchChecksumUsesSelectedAssetNameExactly(): void
    {
        $service = $this->newWebinterfaceUpdateService([
            'httpClient' => new MockHttpClient([new MockResponse("aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa  easywi-core.tar.gz
")]),
            'settingsService' => new WebinterfaceUpdateSettingsService(sys_get_temp_dir()),
            'manifestUrl' => '',
            'installDir' => sys_get_temp_dir(),
            'releasesDir' => '',
            'currentSymlink' => '',
            'lockFile' => sys_get_temp_dir() . '/update.lock',
            'fallbackVersion' => '1.0.0',
            'releaseRepository' => 'Matlord93/Easy-Wi-NextGen',
            'releaseChannel' => 'dev',
            'kernelEnvironment' => 'test',
            'kernelDebug' => false,
            'excludes' => '',
        ]);

        $method = new \ReflectionMethod($service, 'fetchChecksumForAsset');
        $logPath = tempnam(sys_get_temp_dir(), 'easywi-log-');

        self::assertNull($method->invoke($service, 'https://example.invalid/checksums-core.txt', 'easywi-webinterface-0.9.7-dev.20260515.1.zip', $logPath));
    }

    public function testFetchChecksumReturnsHashForSelectedVersionedZipAsset(): void
    {
        $service = $this->newWebinterfaceUpdateService([
            'httpClient' => new MockHttpClient([new MockResponse("e78002c856e60968afd38fee74e4616f0d6d6ebf44476bd92896a80f5ed47b78  easywi-webinterface-0.9.7-dev.20260515.1.zip
")]),
            'settingsService' => new WebinterfaceUpdateSettingsService(sys_get_temp_dir()),
            'manifestUrl' => '',
            'installDir' => sys_get_temp_dir(),
            'releasesDir' => '',
            'currentSymlink' => '',
            'lockFile' => sys_get_temp_dir() . '/update.lock',
            'fallbackVersion' => '1.0.0',
            'releaseRepository' => 'Matlord93/Easy-Wi-NextGen',
            'releaseChannel' => 'dev',
            'kernelEnvironment' => 'test',
            'kernelDebug' => false,
            'excludes' => '',
        ]);

        $method = new \ReflectionMethod($service, 'fetchChecksumForAsset');
        $logPath = tempnam(sys_get_temp_dir(), 'easywi-log-');

        self::assertSame(
            'e78002c856e60968afd38fee74e4616f0d6d6ebf44476bd92896a80f5ed47b78',
            $method->invoke($service, 'https://example.invalid/checksums-core.txt', 'easywi-webinterface-0.9.7-dev.20260515.1.zip', $logPath),
        );
    }

    public function testZipExtractionAcceptsSafeArchiveAndRejectsTraversal(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ZipArchive extension is not available.');
        }

        $service = $this->newWebinterfaceUpdateService([
            'httpClient' => new MockHttpClient(),
            'settingsService' => new WebinterfaceUpdateSettingsService(sys_get_temp_dir()),
            'manifestUrl' => '',
            'installDir' => sys_get_temp_dir(),
            'releasesDir' => '',
            'currentSymlink' => '',
            'lockFile' => sys_get_temp_dir() . '/update.lock',
            'fallbackVersion' => '1.0.0',
            'releaseRepository' => 'Matlord93/Easy-Wi-NextGen',
            'releaseChannel' => 'stable',
            'kernelEnvironment' => 'test',
            'kernelDebug' => false,
            'excludes' => '',
        ]);
        $method = new \ReflectionMethod($service, 'extractArchive');
        $logPath = tempnam(sys_get_temp_dir(), 'easywi-log-');

        $safeZip = $this->createZip(['core/VERSION' => '2.0.0']);
        $safeWorkDir = sys_get_temp_dir() . '/easywi-safe-' . bin2hex(random_bytes(4));
        mkdir($safeWorkDir, 0770, true);
        $safeStaging = $method->invoke($service, $safeZip, $safeWorkDir, $logPath);

        self::assertIsString($safeStaging);
        self::assertFileExists($safeStaging . '/core/VERSION');

        $traversalZip = $this->createZip(['../evil.php' => 'bad']);
        $badWorkDir = sys_get_temp_dir() . '/easywi-bad-' . bin2hex(random_bytes(4));
        mkdir($badWorkDir, 0770, true);

        self::assertNull($method->invoke($service, $traversalZip, $badWorkDir, $logPath));
    }

    public function testZipExtractionRejectsAbsolutePaths(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ZipArchive extension is not available.');
        }

        $service = $this->newWebinterfaceUpdateService([
            'httpClient' => new MockHttpClient(),
            'settingsService' => new WebinterfaceUpdateSettingsService(sys_get_temp_dir()),
            'manifestUrl' => '',
            'installDir' => sys_get_temp_dir(),
            'releasesDir' => '',
            'currentSymlink' => '',
            'lockFile' => sys_get_temp_dir() . '/update.lock',
            'fallbackVersion' => '1.0.0',
            'releaseRepository' => 'Matlord93/Easy-Wi-NextGen',
            'releaseChannel' => 'stable',
            'kernelEnvironment' => 'test',
            'kernelDebug' => false,
            'excludes' => '',
        ]);
        $method = new \ReflectionMethod($service, 'extractArchive');
        $logPath = tempnam(sys_get_temp_dir(), 'easywi-log-');
        $absoluteZip = $this->createZip(['/absolute.php' => 'bad']);
        $workDir = sys_get_temp_dir() . '/easywi-absolute-' . bin2hex(random_bytes(4));
        mkdir($workDir, 0770, true);

        self::assertNull($method->invoke($service, $absoluteZip, $workDir, $logPath));
    }

    /** @param array<string, string> $entries */
    private function createZip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'easywi-zip-') . '.zip';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach ($entries as $name => $contents) {
            self::assertTrue($zip->addFromString($name, $contents));
        }
        self::assertTrue($zip->close());

        return $path;
    }

    /**
     * @param array<string, mixed> $namedOverrides
     */
    private function newWebinterfaceUpdateService(array $namedOverrides): WebinterfaceUpdateService
    {
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
