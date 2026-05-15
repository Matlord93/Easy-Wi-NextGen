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
    public function testGetReleasePackageForChannelReturnsCoreAssetAndChecksum(): void
    {
        $client = new MockHttpClient([new MockResponse((string) json_encode([[
            'draft' => false,
            'prerelease' => false,
            'tag_name' => 'v1.4.0',
            'body' => "channel: stable\n",
            'assets' => [
                ['name' => 'easywi-core.tar.gz', 'browser_download_url' => 'https://example.invalid/easywi-core.tar.gz'],
                ['name' => 'checksums-core.txt', 'browser_download_url' => 'https://example.invalid/checksums-core.txt'],
                ['name' => 'checksums-core.txt.asc', 'browser_download_url' => 'https://example.invalid/checksums-core.txt.asc'],
            ],
        ]]))]);
        $checker = new CoreReleaseChecker(new ArrayAdapter(), 'Matlord93/webinterface', 300, 'stable', new GithubReleaseResolver($client));

        $package = $checker->getReleasePackageForChannel('stable');

        self::assertSame('v1.4.0', $package['version'] ?? null);
        self::assertSame('https://example.invalid/easywi-core.tar.gz', $package['download_url'] ?? null);
        self::assertSame('https://example.invalid/checksums-core.txt', $package['checksums_url'] ?? null);
        self::assertSame('https://example.invalid/checksums-core.txt.asc', $package['signature_url'] ?? null);
        self::assertSame('stable', $package['channel'] ?? null);
    }
}
