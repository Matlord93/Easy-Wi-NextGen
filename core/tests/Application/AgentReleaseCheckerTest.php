<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Module\Core\Application\AgentReleaseChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class AgentReleaseCheckerTest extends TestCase
{
    public function testSelectLatestReleaseAssetPrefersHighestVersionNotFirstApiEntry(): void
    {
        $checker = new AgentReleaseChecker(new ArrayAdapter(), 'Matlord93/Easy-Wi-NextGen', 300, 'beta');

        $releases = [
            [
                'draft' => false,
                'prerelease' => true,
                'tag_name' => '1.1.0.4-Alpha',
                'assets' => [
                    ['name' => 'easywi-agent-linux-amd64', 'browser_download_url' => 'https://example.invalid/1.1.0.4/easywi-agent-linux-amd64'],
                    ['name' => 'checksums-agent.txt', 'browser_download_url' => 'https://example.invalid/1.1.0.4/checksums-agent.txt'],
                ],
            ],
            [
                'draft' => false,
                'prerelease' => true,
                'tag_name' => '1.1.1.1-Alpha',
                'assets' => [
                    ['name' => 'easywi-agent-linux-amd64', 'browser_download_url' => 'https://example.invalid/1.1.1.1/easywi-agent-linux-amd64'],
                    ['name' => 'checksums-agent.txt', 'browser_download_url' => 'https://example.invalid/1.1.1.1/checksums-agent.txt'],
                ],
            ],
        ];

        $method = new \ReflectionMethod($checker, 'selectLatestReleaseAsset');

        $selected = $method->invoke($checker, $releases, 'beta', 'easywi-agent-linux-amd64');

        self::assertIsArray($selected);
        self::assertSame('1.1.1.1-Alpha', $selected['tag']);
        self::assertSame('https://example.invalid/1.1.1.1/easywi-agent-linux-amd64', $selected['download_url']);
    }

    public function testFetchLatestVersionComparesSemverLikeTags(): void
    {
        $checker = new AgentReleaseChecker(new ArrayAdapter(), 'Matlord93/Easy-Wi-NextGen', 300, 'beta');

        $method = new \ReflectionMethod($checker, 'compareReleaseTags');

        $result = $method->invoke($checker, '1.1.1.1-Alpha', '1.1.0.4-Alpha');

        self::assertIsInt($result);
        self::assertGreaterThan(0, $result);
    }

    public function testSelectLatestReleaseAssetSupportsPinnedTargetVersion(): void
    {
        $checker = new AgentReleaseChecker(new ArrayAdapter(), 'Matlord93/Easy-Wi-NextGen', 300, 'stable');

        $releases = [
            [
                'draft' => false,
                'prerelease' => false,
                'tag_name' => 'v2.1.0',
                'assets' => [
                    ['name' => 'easywi-agent-linux-amd64', 'browser_download_url' => 'https://example.invalid/v2.1.0/easywi-agent-linux-amd64'],
                    ['name' => 'checksums-agent.txt', 'browser_download_url' => 'https://example.invalid/v2.1.0/checksums-agent.txt'],
                ],
            ],
            [
                'draft' => false,
                'prerelease' => false,
                'tag_name' => 'v2.0.0',
                'assets' => [
                    ['name' => 'easywi-agent-linux-amd64', 'browser_download_url' => 'https://example.invalid/v2.0.0/easywi-agent-linux-amd64'],
                    ['name' => 'checksums-agent.txt', 'browser_download_url' => 'https://example.invalid/v2.0.0/checksums-agent.txt'],
                ],
            ],
        ];

        $method = new \ReflectionMethod($checker, 'selectLatestReleaseAsset');
        $selected = $method->invoke($checker, $releases, 'stable', 'easywi-agent-linux-amd64', '2.0.0');

        self::assertIsArray($selected);
        self::assertSame('v2.0.0', $selected['tag']);
    }


    public function testLatestFromFeedUsesSemverInsteadOfStringSort(): void
    {
        $checker = new AgentReleaseChecker(new ArrayAdapter(), '', 300, 'stable');

        $feed = [
            'agent' => [
                'releases' => [
                    ['version' => '1.9.0', 'channel' => 'stable'],
                    ['version' => '1.10.0', 'channel' => 'stable'],
                    ['version' => '1.2.0', 'channel' => 'stable'],
                ],
            ],
        ];

        $method = new \ReflectionMethod($checker, 'latestFromFeed');
        $latest = $method->invoke($checker, $feed, 'stable');

        self::assertSame('1.10.0', $latest);
    }

    public function testResolveFeedArtifactSupportsMappedArtifactKeys(): void
    {
        $checker = new AgentReleaseChecker(new ArrayAdapter(), '', 300, 'stable');

        $method = new \ReflectionMethod($checker, 'resolveFeedArtifact');
        $artifact = $method->invoke($checker, [
            'linux_amd64_targz' => ['url' => 'https://example.invalid/agent.tar.gz'],
        ], 'easywi-agent-linux-amd64');

        self::assertIsArray($artifact);
        self::assertSame('https://example.invalid/agent.tar.gz', $artifact['url']);
    }

}
