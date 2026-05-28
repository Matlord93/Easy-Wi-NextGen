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

    public function testVendorDirectoryIsAlwaysExcludedFromUpdateDeletes(): void
    {
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
            'excludes' => 'var/,storage/',
        ]);

        $parseExcludes = new \ReflectionMethod($service, 'parseExcludes');

        self::assertContains('vendor/', $parseExcludes->invoke($service));
    }

    public function testDefaultUpdateExcludesPreserveVendorDirectory(): void
    {
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

        $parseExcludes = new \ReflectionMethod($service, 'parseExcludes');
        $excludes = $parseExcludes->invoke($service);

        self::assertContains('vendor/', $excludes);
        self::assertSame(1, array_count_values($excludes)['vendor/']);
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

    public function testUnzipFallbackExtractsSafeZipAndRejectsTraversal(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ZipArchive extension is not available to create fixtures.');
        }
        if (!function_exists('shell_exec') || trim((string) shell_exec('command -v unzip')) === '') {
            self::markTestSkipped('unzip command is not available.');
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
        $method = new \ReflectionMethod($service, 'extractZipArchiveWithUnzip');
        $logPath = tempnam(sys_get_temp_dir(), 'easywi-log-');

        $safeZip = $this->createZip(['core/VERSION' => '2.0.0']);
        $safeStaging = sys_get_temp_dir() . '/easywi-unzip-safe-' . bin2hex(random_bytes(4));
        mkdir($safeStaging, 0770, true);

        self::assertTrue($method->invoke($service, $safeZip, $safeStaging, $logPath));
        self::assertFileExists($safeStaging . '/core/VERSION');

        $traversalZip = $this->createZip(['../evil.php' => 'bad']);
        $badStaging = sys_get_temp_dir() . '/easywi-unzip-bad-' . bin2hex(random_bytes(4));
        mkdir($badStaging, 0770, true);

        self::assertFalse($method->invoke($service, $traversalZip, $badStaging, $logPath));
    }

    public function testDownloadUsesManifestAssetNameForGithubApiAssetUrl(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ZipArchive extension is not available.');
        }

        $zipPath = $this->createZip(['core/VERSION' => '2.0.0']);
        $service = $this->newWebinterfaceUpdateService([
            'httpClient' => new MockHttpClient([new MockResponse((string) file_get_contents($zipPath), ['http_code' => 200])]),
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

        $download = new \ReflectionMethod($service, 'downloadAsset');
        $extract = new \ReflectionMethod($service, 'extractArchive');
        $workDir = sys_get_temp_dir() . '/easywi-api-asset-' . bin2hex(random_bytes(4));
        mkdir($workDir, 0770, true);
        $logPath = tempnam(sys_get_temp_dir(), 'easywi-log-');

        $archivePath = $download->invoke(
            $service,
            'https://api.github.com/repos/Matlord93/Easy-Wi-NextGen/releases/assets/200',
            $workDir,
            $logPath,
            'easywi-webinterface-0.9.7-dev.20260515.2.zip',
        );

        self::assertSame($workDir . '/easywi-webinterface-0.9.7-dev.20260515.2.zip', $archivePath);
        self::assertFileExists($archivePath);

        $staging = $extract->invoke($service, $archivePath, $workDir, $logPath);

        self::assertIsString($staging);
        self::assertFileExists($staging . '/core/VERSION');
    }


    public function testExtractArchiveDetectsZipWithoutFilenameExtension(): void
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

        $zipPath = $this->createZip(['core/VERSION' => '2.0.0']);
        $archivePath = tempnam(sys_get_temp_dir(), 'easywi-package-');
        copy($zipPath, $archivePath);
        $workDir = sys_get_temp_dir() . '/easywi-extensionless-' . bin2hex(random_bytes(4));
        mkdir($workDir, 0770, true);
        $logPath = tempnam(sys_get_temp_dir(), 'easywi-log-');

        $extract = new \ReflectionMethod($service, 'extractArchive');
        $staging = $extract->invoke($service, $archivePath, $workDir, $logPath);

        self::assertIsString($staging);
        self::assertFileExists($staging . '/core/VERSION');
    }


    public function testTarCommandFallbackExtractsExtensionlessTarGz(): void
    {
        if (!function_exists('shell_exec') || trim((string) shell_exec('command -v tar')) === '') {
            self::markTestSkipped('tar command is not available.');
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

        $tarGzPath = $this->createTarGz(['core/VERSION' => '2.0.0']);
        $archivePath = tempnam(sys_get_temp_dir(), 'easywi-targz-package-');
        copy($tarGzPath, $archivePath);
        $stagingDir = sys_get_temp_dir() . '/easywi-targz-staging-' . bin2hex(random_bytes(4));
        mkdir($stagingDir, 0770, true);
        $logPath = tempnam(sys_get_temp_dir(), 'easywi-log-');

        $extract = new \ReflectionMethod($service, 'extractTarArchiveWithCommand');

        self::assertTrue($extract->invoke($service, $archivePath, $stagingDir, true, $logPath));
        self::assertFileExists($stagingDir . '/core/VERSION');
    }

    public function testDownloadFallsBackToContentDispositionFilename(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ZipArchive extension is not available.');
        }

        $zipPath = $this->createZip(['core/VERSION' => '2.0.0']);
        $service = $this->newWebinterfaceUpdateService([
            'httpClient' => new MockHttpClient([new MockResponse((string) file_get_contents($zipPath), [
                'http_code' => 200,
                'response_headers' => ['content-disposition: attachment; filename="easywi-webinterface-from-header.zip"'],
            ])]),
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

        $download = new \ReflectionMethod($service, 'downloadAsset');
        $workDir = sys_get_temp_dir() . '/easywi-header-asset-' . bin2hex(random_bytes(4));
        mkdir($workDir, 0770, true);
        $logPath = tempnam(sys_get_temp_dir(), 'easywi-log-');

        $archivePath = $download->invoke(
            $service,
            'https://api.github.com/repos/Matlord93/Easy-Wi-NextGen/releases/assets/200',
            $workDir,
            $logPath,
            null,
        );

        self::assertSame($workDir . '/easywi-webinterface-from-header.zip', $archivePath);
        self::assertFileExists($archivePath);
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


    /** @param array<string, string> $entries */
    private function createTarGz(array $entries): string
    {
        $sourceDir = sys_get_temp_dir() . '/easywi-targz-src-' . bin2hex(random_bytes(4));
        mkdir($sourceDir, 0770, true);
        foreach ($entries as $name => $contents) {
            $path = $sourceDir . '/' . ltrim($name, '/');
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0770, true);
            }
            file_put_contents($path, $contents);
        }

        $path = tempnam(sys_get_temp_dir(), 'easywi-targz-') . '.tar.gz';
        $command = sprintf('tar -czf %s -C %s .', escapeshellarg($path), escapeshellarg($sourceDir));
        exec($command, $output, $exitCode);
        self::assertSame(0, $exitCode, implode("\n", $output));

        return $path;
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
