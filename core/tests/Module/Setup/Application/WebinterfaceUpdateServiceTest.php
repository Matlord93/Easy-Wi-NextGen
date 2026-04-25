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
                        'url' => 'https://example.invalid/core-novendor-1.2.3.tar.gz',
                        'sha256' => 'abc123',
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
        self::assertSame('https://example.invalid/core-novendor-1.2.3.tar.gz', $status->assetUrl);
        self::assertSame('abc123', $status->assetSha256);
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
