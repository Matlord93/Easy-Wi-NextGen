<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Setup\Application\WebinterfaceUpdateService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WebinterfaceUpdateServiceTest extends TestCase
{
    public function testFallsBackToGithubReleaseManifestWhenConfiguredManifestIsUnavailable(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('', ['http_code' => 404]),
            new MockResponse(json_encode([
                [
                    'draft' => false,
                    'prerelease' => false,
                    'tag_name' => 'v1.2.3',
                    'assets' => [
                        [
                            'name' => 'manifest.json',
                            'browser_download_url' => 'https://example.test/manifest.json',
                        ],
                    ],
                    'body' => 'release notes',
                ],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]),
            new MockResponse(json_encode([
                'latest' => '1.2.3',
                'asset_url' => 'https://example.test/easywi-webinterface-1.2.3.zip',
                'sha256' => 'abc',
                'notes' => 'notes from manifest',
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ]);

        $tmpDir = sys_get_temp_dir() . '/easywi-update-test-' . bin2hex(random_bytes(4));
        mkdir($tmpDir, 0775, true);
        file_put_contents($tmpDir . '/VERSION', "1.2.2\n");

        $service = $this->newWebinterfaceUpdateService([
            'httpClient' => $httpClient,
            'manifestUrl' => 'https://localhost/manifest.json',
            'installPath' => $tmpDir,
            'releaseStoragePath' => '',
            'currentReleasePath' => '',
            'lockFilePath' => $tmpDir . '/update.lock',
            'installedVersion' => '1.2.2',
            'githubRepository' => 'Matlord93/Easy-Wi-NextGen',
            'channel' => 'stable',
            'environment' => 'test',
            'allowPrerelease' => false,
        ]);

        $status = $service->checkForUpdate();

        self::assertNull($status->error);
        self::assertSame('1.2.2', $status->installedVersion);
        self::assertSame('1.2.3', $status->latestVersion);
        self::assertTrue($status->updateAvailable);
        self::assertSame('notes from manifest', $status->notes);
    }

    public function testBuildsManifestFromReleaseAssetsWhenManifestAssetMissing(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('', ['http_code' => 500]),
            new MockResponse(json_encode([
                [
                    'draft' => false,
                    'prerelease' => false,
                    'tag_name' => 'v2.0.0',
                    'assets' => [
                        [
                            'name' => 'easywi-webinterface-2.0.0.zip',
                            'browser_download_url' => 'https://example.test/easywi-webinterface-2.0.0.zip',
                        ],
                    ],
                    'body' => 'Generated fallback notes',
                ],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ]);

        $tmpDir = sys_get_temp_dir() . '/easywi-update-test-' . bin2hex(random_bytes(4));
        mkdir($tmpDir, 0775, true);
        file_put_contents($tmpDir . '/VERSION', "1.9.0\n");

        $service = $this->newWebinterfaceUpdateService([
            'httpClient' => $httpClient,
            'manifestUrl' => 'https://localhost/manifest.json',
            'installPath' => $tmpDir,
            'releaseStoragePath' => '',
            'currentReleasePath' => '',
            'lockFilePath' => $tmpDir . '/update.lock',
            'installedVersion' => '1.9.0',
            'githubRepository' => 'Matlord93/Easy-Wi-NextGen',
            'channel' => 'stable',
            'environment' => 'test',
            'allowPrerelease' => false,
        ]);

        $status = $service->checkForUpdate();

        self::assertNull($status->error);
        self::assertSame('2.0.0', $status->latestVersion);
        self::assertTrue($status->updateAvailable);
        self::assertSame('Generated fallback notes', $status->notes);
        self::assertSame('https://example.test/easywi-webinterface-2.0.0.zip', $status->assetUrl);
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
