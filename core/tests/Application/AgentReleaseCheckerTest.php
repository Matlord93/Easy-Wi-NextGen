<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Module\Core\Application\AgentReleaseChecker;
use App\Module\Core\Application\GithubReleaseResolver;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AgentReleaseCheckerTest extends TestCase
{
    #[TestWith(['v1.0.0-predev.20261121.1', 'v1.0.0-dev.20261122.1', true])]
    #[TestWith(['v1.0.0-predev.20261121.1', 'v1.0.0-dev.20261121.1', false])]
    #[TestWith(['v1.0.0-predev.20261122.1', 'v1.0.0-dev.20261121.1', false])]
    #[TestWith(['1.0.0-dev.20261121.1', 'agent-v1.0.0-dev.20261122.1', true])]
    #[TestWith(['1.0.0', 'v1.0.0-dev.20261122.1', false])]
    #[TestWith(['1.0.0-predev.20261122.1', 'v1.0.0', true])]
    #[TestWith([null, 'v1.0.0-dev.20261122.1', null])]
    public function testUpdateAvailabilityOrdersDevAliasesAndPreservesStablePrecedence(?string $installed, string $latest, ?bool $expected): void
    {
        self::assertSame($expected, $this->checker('dev')->isUpdateAvailable($installed, $latest));
    }

    public function testStableChannelIgnoresPreviouslyCachedDevTag(): void
    {
        $repository = 'Matlord93/Easy-Wi-NextGen';
        $cache = new ArrayAdapter();
        $item = $cache->getItem('agent.latest_release_version.'.hash('sha256', $repository).'.stable');
        $item->set('v1.0.0-predev.20261121.1');
        $cache->save($item);
        $client = new MockHttpClient([new MockResponse(json_encode([
            ['draft' => false, 'prerelease' => false, 'tag_name' => 'v1.0.0-predev.20261121.1', 'body' => "channel: stable\n"],
        ], JSON_THROW_ON_ERROR))]);
        $checker = new AgentReleaseChecker($cache, $repository, releaseResolver: new GithubReleaseResolver($client));

        self::assertNull($checker->getLatestVersionForChannel('stable'));
    }

    public function testSelectLatestReleaseAssetPrefersHighestVersionNotFirstApiEntry(): void
    {
        $checker = $this->checker('beta');

        $releases = [
            [
                'draft' => false,
                'prerelease' => true,
                'tag_name' => '1.1.0-beta.4',
                'assets' => [
                    ['name' => 'easywi-agent-linux-amd64', 'browser_download_url' => 'https://example.invalid/1.1.0.4/easywi-agent-linux-amd64'],
                    ['name' => 'checksums-agent.txt', 'browser_download_url' => 'https://example.invalid/1.1.0.4/checksums-agent.txt'],
                ],
            ],
            [
                'draft' => false,
                'prerelease' => true,
                'tag_name' => '1.1.1-beta.1',
                'assets' => [
                    ['name' => 'easywi-agent-linux-amd64', 'browser_download_url' => 'https://example.invalid/1.1.1.1/easywi-agent-linux-amd64'],
                    ['name' => 'checksums-agent.txt', 'browser_download_url' => 'https://example.invalid/1.1.1.1/checksums-agent.txt'],
                ],
            ],
        ];

        $resolver = new GithubReleaseResolver(new MockHttpClient());

        $selected = $resolver->selectLatestAsset($releases, 'beta', 'easywi-agent-linux-amd64', 'checksums-agent.txt', 'checksums-agent.txt.asc');

        self::assertIsArray($selected);
        self::assertSame('1.1.1-beta.1', $selected['version']);
        self::assertSame('https://example.invalid/1.1.1.1/easywi-agent-linux-amd64', $selected['download_url']);
    }

    public function testFetchLatestVersionComparesSemverLikeTags(): void
    {
        $checker = $this->checker('beta');

        $resolver = new GithubReleaseResolver(new MockHttpClient());

        $result = $resolver->compareReleaseTags('1.1.1-beta.1', '1.1.0-beta.4');

        self::assertIsInt($result);
        self::assertGreaterThan(0, $result);
    }

    public function testSelectLatestReleaseAssetSupportsPinnedTargetVersion(): void
    {
        $checker = $this->checker('stable');

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

        $resolver = new GithubReleaseResolver(new MockHttpClient());
        $selected = $resolver->selectLatestAsset($releases, 'stable', 'easywi-agent-linux-amd64', 'checksums-agent.txt', 'checksums-agent.txt.asc', '2.0.0');

        self::assertIsArray($selected);
        self::assertSame('v2.0.0', $selected['version']);
    }

    public function testChannelFilteringSeparatesDevBetaAndStableGithubReleases(): void
    {
        $checker = $this->checker('dev');

        $releases = [
            [
                'draft' => false,
                'prerelease' => false,
                'tag_name' => 'v2.0.0',
                'body' => "easywi-channel: stable\n",
                'assets' => [
                    ['name' => 'easywi-agent-linux-amd64', 'browser_download_url' => 'https://example.invalid/v2/easywi-agent-linux-amd64'],
                    ['name' => 'checksums-agent.txt', 'browser_download_url' => 'https://example.invalid/v2/checksums-agent.txt'],
                ],
            ],
            [
                'draft' => false,
                'prerelease' => true,
                'tag_name' => 'v2.1.0-beta.1',
                'body' => "easywi-channel: beta\n",
                'assets' => [
                    ['name' => 'easywi-agent-linux-amd64', 'browser_download_url' => 'https://example.invalid/beta/easywi-agent-linux-amd64'],
                    ['name' => 'checksums-agent.txt', 'browser_download_url' => 'https://example.invalid/beta/checksums-agent.txt'],
                ],
            ],
            [
                'draft' => false,
                'prerelease' => true,
                'tag_name' => 'v2.2.0-dev.3',
                'body' => "easywi-channel: dev\n",
                'assets' => [
                    ['name' => 'easywi-agent-linux-amd64', 'browser_download_url' => 'https://example.invalid/dev/easywi-agent-linux-amd64'],
                    ['name' => 'checksums-agent.txt', 'browser_download_url' => 'https://example.invalid/dev/checksums-agent.txt'],
                ],
            ],
        ];

        $resolver = new GithubReleaseResolver(new MockHttpClient());

        $stable = $resolver->selectLatestAsset($releases, 'stable', 'easywi-agent-linux-amd64', 'checksums-agent.txt', 'checksums-agent.txt.asc');
        $beta = $resolver->selectLatestAsset($releases, 'beta', 'easywi-agent-linux-amd64', 'checksums-agent.txt', 'checksums-agent.txt.asc');
        $dev = $resolver->selectLatestAsset($releases, 'dev', 'easywi-agent-linux-amd64', 'checksums-agent.txt', 'checksums-agent.txt.asc');

        self::assertSame('v2.0.0', $stable['version'] ?? null);
        self::assertSame('v2.1.0-beta.1', $beta['version'] ?? null);
        self::assertSame('v2.2.0-dev.3', $dev['version'] ?? null);
    }

    public function testAlphaChannelAliasNormalizesToDev(): void
    {
        $checker = $this->checker('alpha');

        self::assertSame('dev', $checker->getChannel());
    }


    public function testAgentUpdateCheckUsesResolverCache(): void
    {
        $requests = 0;
        $client = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;
            return new MockResponse((string) json_encode([[
                'draft' => false,
                'prerelease' => false,
                'tag_name' => 'v9.0.0',
            ]], JSON_THROW_ON_ERROR));
        });
        $checker = new AgentReleaseChecker(
            new ArrayAdapter(),
            'Matlord93/Easy-Wi-NextGen',
            3600,
            'stable',
            new GithubReleaseResolver($client, null, new ArrayAdapter(), 3600),
        );

        self::assertSame('v9.0.0', $checker->getLatestVersionForChannel('stable'));
        self::assertSame('v9.0.0', $checker->getLatestVersionForChannel('stable'));
        self::assertSame(1, $requests);
        self::assertTrue($checker->getCacheStatus('stable')['has_cache']);
    }


    public function testPublicGithubBrowserDownloadUrlDoesNotRequirePanelProxy(): void
    {
        $checker = $this->checker('stable');

        self::assertFalse($checker->releaseAssetRequiresPanelProxy([
            'download_url' => 'https://github.com/Matlord93/Easy-Wi-NextGen/releases/download/v1.0.0/easywi-agent-linux-amd64',
            'checksums_url' => 'https://github.com/Matlord93/Easy-Wi-NextGen/releases/download/v1.0.0/checksums-agent.txt',
        ]));
    }

    public function testAuthenticatedGithubApiAssetUrlRequiresPanelProxy(): void
    {
        $checker = $this->checker('stable');

        self::assertTrue($checker->releaseAssetRequiresPanelProxy([
            'download_url' => 'https://api.github.com/repos/Matlord93/Easy-Wi-NextGen/releases/assets/100',
            'checksums_url' => 'https://github.com/Matlord93/Easy-Wi-NextGen/releases/download/v1.0.0/checksums-agent.txt',
        ]));
    }

    public function testCachedPublicAssetCanStillBeUsedAfterRateLimit(): void
    {
        $requests = 0;
        $reset = time() + 3600;
        $client = new MockHttpClient(function () use (&$requests, $reset): MockResponse {
            ++$requests;
            if ($requests === 1) {
                return new MockResponse((string) json_encode([[
                    'draft' => false,
                    'prerelease' => false,
                    'tag_name' => 'v10.0.0',
                    'assets' => [
                        ['name' => 'easywi-agent-linux-amd64', 'url' => 'https://api.github.com/repos/Matlord93/Easy-Wi-NextGen/releases/assets/100', 'browser_download_url' => 'https://github.com/Matlord93/Easy-Wi-NextGen/releases/download/v10.0.0/easywi-agent-linux-amd64'],
                        ['name' => 'checksums-agent.txt', 'url' => 'https://api.github.com/repos/Matlord93/Easy-Wi-NextGen/releases/assets/101', 'browser_download_url' => 'https://github.com/Matlord93/Easy-Wi-NextGen/releases/download/v10.0.0/checksums-agent.txt'],
                    ],
                ]], JSON_THROW_ON_ERROR));
            }

            return new MockResponse('{"message":"API rate limit exceeded"}', [
                'http_code' => 403,
                'response_headers' => [
                    'x-ratelimit-remaining: 0',
                    'x-ratelimit-reset: ' . $reset,
                ],
            ]);
        });
        $checker = new AgentReleaseChecker(
            new ArrayAdapter(),
            'Matlord93/Easy-Wi-NextGen',
            1,
            'stable',
            new GithubReleaseResolver($client, 'secret-token', new ArrayAdapter(), 1),
        );

        $first = $checker->getReleaseAssetUrlsForChannel('easywi-agent-linux-amd64', 'stable');
        self::assertIsArray($first);
        self::assertFalse($checker->releaseAssetRequiresPanelProxy($first));
        sleep(2);
        $stale = $checker->getReleaseAssetUrlsForChannel('easywi-agent-linux-amd64', 'stable');
        self::assertIsArray($stale);
        self::assertSame('https://github.com/Matlord93/Easy-Wi-NextGen/releases/download/v10.0.0/easywi-agent-linux-amd64', $stale['download_url']);
        self::assertFalse($checker->releaseAssetRequiresPanelProxy($stale));
        self::assertSame(2, $requests);
    }



    public function testReleaseAssetSupportsTarGzAndZipWithPlatformChecksumFallback(): void
    {
        $response = new MockResponse((string) json_encode([[
            'draft' => false,
            'prerelease' => true,
            'tag_name' => 'v0.9.8.2-dev.20260521.1',
            'assets' => [
                ['name' => 'checksums-agent-linux.txt', 'browser_download_url' => 'https://example.invalid/checksums-agent-linux.txt'],
                ['name' => 'checksums-agent-linux.txt.asc', 'browser_download_url' => 'https://example.invalid/checksums-agent-linux.txt.asc'],
                ['name' => 'checksums-agent-windows.txt', 'browser_download_url' => 'https://example.invalid/checksums-agent-windows.txt'],
                ['name' => 'checksums-agent.txt', 'browser_download_url' => 'https://example.invalid/checksums-agent.txt'],
                ['name' => 'checksums-agent.txt.asc', 'browser_download_url' => 'https://example.invalid/checksums-agent.txt.asc'],
                ['name' => 'easywi-agent-linux-amd64.tar.gz', 'browser_download_url' => 'https://example.invalid/easywi-agent-linux-amd64.tar.gz'],
                ['name' => 'easywi-agent-linux-arm64.tar.gz', 'browser_download_url' => 'https://example.invalid/easywi-agent-linux-arm64.tar.gz'],
                ['name' => 'easywi-agent-windows-amd64.zip', 'browser_download_url' => 'https://example.invalid/easywi-agent-windows-amd64.zip'],
            ],
        ]], JSON_THROW_ON_ERROR));

        $checker = new AgentReleaseChecker(
            new ArrayAdapter(),
            'Matlord93/Easy-Wi-NextGen',
            3600,
            'dev',
            new GithubReleaseResolver(new MockHttpClient([$response]), null, new ArrayAdapter(), 3600),
        );

        $linux = $checker->getReleaseAssetUrlsForChannel('easywi-agent-linux-amd64.tar.gz', 'dev');
        $arm = $checker->getReleaseAssetUrlsForChannel('easywi-agent-linux-arm64.tar.gz', 'dev');
        $win = $checker->getReleaseAssetUrlsForChannel('easywi-agent-windows-amd64.zip', 'dev');

        self::assertSame('easywi-agent-linux-amd64.tar.gz', $linux['asset_name'] ?? null);
        self::assertSame('https://example.invalid/checksums-agent-linux.txt', $linux['checksums_url'] ?? null);
        self::assertSame('https://example.invalid/checksums-agent-linux.txt.asc', $linux['signature_url'] ?? null);
        self::assertSame('easywi-agent-linux-arm64.tar.gz', $arm['asset_name'] ?? null);
        self::assertSame('easywi-agent-windows-amd64.zip', $win['asset_name'] ?? null);
        self::assertSame('https://example.invalid/checksums-agent.txt', $win['checksums_url'] ?? null);
        self::assertSame('https://example.invalid/checksums-agent.txt.asc', $win['signature_url'] ?? null);
    }

    public function testReleaseAssetAcceptsLegacyAgentOnlyChecksumAndSignature(): void
    {
        $response = new MockResponse(json_encode([[
            'draft' => false,
            'prerelease' => false,
            'tag_name' => 'agent-v1.2.3',
            'assets' => [
                ['name' => 'checksums.sha256', 'browser_download_url' => 'https://example.invalid/checksums.sha256'],
                ['name' => 'checksums.sha256.asc', 'browser_download_url' => 'https://example.invalid/checksums.sha256.asc'],
                ['name' => 'easywi-agent-windows-amd64.zip', 'browser_download_url' => 'https://example.invalid/easywi-agent-windows-amd64.zip'],
            ],
        ]], JSON_THROW_ON_ERROR));
        $checker = new AgentReleaseChecker(
            new ArrayAdapter(),
            'Matlord93/Easy-Wi-NextGen',
            3600,
            'stable',
            new GithubReleaseResolver(new MockHttpClient([$response]), null, new ArrayAdapter(), 3600),
        );

        $release = $checker->getReleaseAssetUrlsForChannel('easywi-agent-windows-amd64.zip', 'stable', 'agent-v1.2.3');

        self::assertSame('https://example.invalid/checksums.sha256', $release['checksums_url'] ?? null);
        self::assertSame('https://example.invalid/checksums.sha256.asc', $release['signature_url'] ?? null);
    }

    private function checker(string $channel): AgentReleaseChecker
    {
        return new AgentReleaseChecker(
            new ArrayAdapter(),
            'Matlord93/Easy-Wi-NextGen',
            300,
            $channel,
            new GithubReleaseResolver(new MockHttpClient()),
        );
    }
}
