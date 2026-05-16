<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Module\Core\Application\CoreReleaseChecker;
use App\Module\Core\Application\GithubReleaseResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CoreReleaseCheckerTest extends TestCase
{
    public function testGetReleasePackageForChannelReturnsStaticCoreAssetAndChecksum(): void
    {
        $checker = $this->checker([[
            'draft' => false,
            'prerelease' => false,
            'tag_name' => 'v1.4.0',
            'body' => "channel: stable\n",
            'assets' => [
                ['name' => 'easywi-core.tar.gz', 'browser_download_url' => 'https://example.invalid/easywi-core.tar.gz'],
                ['name' => 'checksums-core.txt', 'browser_download_url' => 'https://example.invalid/checksums-core.txt'],
                ['name' => 'checksums-core.txt.asc', 'browser_download_url' => 'https://example.invalid/checksums-core.txt.asc'],
            ],
        ]]);

        $package = $checker->getReleasePackageForChannel('stable');

        self::assertSame('v1.4.0', $package['version'] ?? null);
        self::assertSame('https://example.invalid/easywi-core.tar.gz', $package['download_url'] ?? null);
        self::assertSame('https://example.invalid/checksums-core.txt', $package['checksums_url'] ?? null);
        self::assertSame('https://example.invalid/checksums-core.txt.asc', $package['signature_url'] ?? null);
        self::assertSame('easywi-core.tar.gz', $package['asset_name'] ?? null);
        self::assertSame('stable', $package['channel'] ?? null);
    }

    public function testGetReleasePackageForChannelAcceptsVersionedWebinterfaceZip(): void
    {
        $checker = $this->checker([[
            'draft' => false,
            'prerelease' => true,
            'tag_name' => 'v0.9.7-dev.20260515.1',
            'body' => "channel: dev\n",
            'assets' => [
                ['name' => 'easywi-webinterface-update-agent-v0.9.6-to-0.9.7.zip', 'browser_download_url' => 'https://example.invalid/agent.zip'],
                ['name' => 'easywi-webinterface-0.9.7-dev.20260515.1.zip', 'browser_download_url' => 'https://example.invalid/core.zip'],
                ['name' => 'checksums-core.txt', 'browser_download_url' => 'https://example.invalid/checksums-core.txt'],
            ],
        ]]);

        $package = $checker->getReleasePackageForChannel('dev');

        self::assertSame('easywi-webinterface-0.9.7-dev.20260515.1.zip', $package['asset_name'] ?? null);
        self::assertSame('https://example.invalid/core.zip', $package['download_url'] ?? null);
    }

    public function testGetReleasePackageForChannelAcceptsVersionedWebinterfaceZipWithVPrefix(): void
    {
        $checker = $this->checker([[
            'draft' => false,
            'prerelease' => true,
            'tag_name' => 'v0.9.7-dev.20260515.1',
            'body' => "channel: dev\n",
            'assets' => [
                ['name' => 'easywi-webinterface-v0.9.7-dev.20260515.1.zip', 'browser_download_url' => 'https://example.invalid/core-v.zip'],
                ['name' => 'checksums-core.txt', 'browser_download_url' => 'https://example.invalid/checksums-core.txt'],
            ],
        ]]);

        $package = $checker->getReleasePackageForChannel('dev');

        self::assertSame('easywi-webinterface-v0.9.7-dev.20260515.1.zip', $package['asset_name'] ?? null);
    }

    public function testUpdateAgentPackageIsNeverSelectedAsCorePackage(): void
    {
        $checker = $this->checker([[
            'draft' => false,
            'prerelease' => true,
            'tag_name' => 'v0.9.7-dev.20260515.1',
            'body' => "channel: dev\n",
            'assets' => [
                ['name' => 'easywi-webinterface-update-agent-v0.9.6-to-0.9.7.zip', 'browser_download_url' => 'https://example.invalid/agent.zip'],
                ['name' => 'checksums-core.txt', 'browser_download_url' => 'https://example.invalid/checksums-core.txt'],
            ],
        ]]);

        self::assertNull($checker->getReleasePackageForChannel('dev'));
    }

    public function testVersionedWebinterfaceZipWinsOverStaticCoreAsset(): void
    {
        $checker = $this->checker([[
            'draft' => false,
            'prerelease' => true,
            'tag_name' => 'v0.9.7-dev.20260515.1',
            'body' => "channel: dev\n",
            'assets' => [
                ['name' => 'easywi-webinterface-update-agent-v0.9.6-to-0.9.7.zip', 'browser_download_url' => 'https://example.invalid/agent.zip'],
                ['name' => 'easywi-webinterface-0.9.7-dev.20260515.1.zip', 'browser_download_url' => 'https://example.invalid/versioned.zip'],
                ['name' => 'easywi-core.zip', 'browser_download_url' => 'https://example.invalid/core.zip'],
                ['name' => 'checksums-core.txt', 'browser_download_url' => 'https://example.invalid/checksums-core.txt'],
            ],
        ]]);

        $package = $checker->getReleasePackageForChannel('dev');

        self::assertSame('easywi-webinterface-0.9.7-dev.20260515.1.zip', $package['asset_name'] ?? null);
        self::assertSame('https://example.invalid/versioned.zip', $package['download_url'] ?? null);
    }

    public function testChannelIsolationForCorePackages(): void
    {
        $releases = [
            [
                'draft' => false,
                'prerelease' => false,
                'tag_name' => 'v1.0.0',
                'body' => "channel: stable\n",
                'assets' => [
                    ['name' => 'easywi-core.zip', 'browser_download_url' => 'https://example.invalid/stable.zip'],
                    ['name' => 'checksums-core.txt', 'browser_download_url' => 'https://example.invalid/stable-checksums.txt'],
                ],
            ],
            [
                'draft' => false,
                'prerelease' => true,
                'tag_name' => 'v1.1.0-beta.1',
                'body' => "channel: beta\n",
                'assets' => [
                    ['name' => 'easywi-core.zip', 'browser_download_url' => 'https://example.invalid/beta.zip'],
                    ['name' => 'checksums-core.txt', 'browser_download_url' => 'https://example.invalid/beta-checksums.txt'],
                ],
            ],
            [
                'draft' => false,
                'prerelease' => true,
                'tag_name' => 'v1.2.0-dev.1',
                'body' => "channel: dev\n",
                'assets' => [
                    ['name' => 'easywi-core.zip', 'browser_download_url' => 'https://example.invalid/dev.zip'],
                    ['name' => 'checksums-core.txt', 'browser_download_url' => 'https://example.invalid/dev-checksums.txt'],
                ],
            ],
        ];

        self::assertSame('https://example.invalid/stable.zip', $this->checker($releases)->getReleasePackageForChannel('stable')['download_url'] ?? null);
        self::assertSame('https://example.invalid/beta.zip', $this->checker($releases)->getReleasePackageForChannel('beta')['download_url'] ?? null);
        self::assertSame('https://example.invalid/dev.zip', $this->checker($releases)->getReleasePackageForChannel('dev')['download_url'] ?? null);
    }

    private function checker(array $releases): CoreReleaseChecker
    {
        $client = new MockHttpClient([new MockResponse((string) json_encode($releases, JSON_THROW_ON_ERROR))]);

        return new CoreReleaseChecker(new ArrayAdapter(), 'Matlord93/webinterface', 300, 'stable', new GithubReleaseResolver($client));
    }
}
