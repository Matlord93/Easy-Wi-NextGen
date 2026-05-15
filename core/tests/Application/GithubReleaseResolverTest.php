<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Module\Core\Application\GithubReleaseResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GithubReleaseResolverTest extends TestCase
{
    public function testChannelDetectionSupportsStableBetaRcDevNightlyAndExplicitOverrides(): void
    {
        $resolver = $this->resolver([]);

        self::assertSame('stable', $resolver->detectReleaseChannel(['prerelease' => false, 'tag_name' => 'v1.4.0']));
        self::assertSame('beta', $resolver->detectReleaseChannel(['prerelease' => true, 'tag_name' => 'v1.5.0-beta.1']));
        self::assertSame('beta', $resolver->detectReleaseChannel(['prerelease' => true, 'tag_name' => 'v1.5.0-rc.1']));
        self::assertSame('dev', $resolver->detectReleaseChannel(['prerelease' => true, 'tag_name' => 'v1.5.0-dev.20260515']));
        self::assertSame('dev', $resolver->detectReleaseChannel(['prerelease' => true, 'tag_name' => 'v1.5.0-nightly.20260515']));
        self::assertSame('stable', $resolver->detectReleaseChannel(['prerelease' => true, 'tag_name' => 'v2.0.0-beta.1', 'body' => "channel: stable\n"]));
        self::assertSame('beta', $resolver->detectReleaseChannel(['prerelease' => false, 'tag_name' => 'v2.0.0', 'body' => "easywi-channel: beta\n"]));
    }

    public function testLatestVersionSelectionKeepsChannelsIsolated(): void
    {
        $resolver = $this->resolver([]);
        $releases = $this->mixedReleases();

        self::assertSame('v1.4.1', $resolver->selectLatestRelease($releases, 'stable')['tag_name'] ?? null);
        self::assertSame('v1.5.0-rc.1', $resolver->selectLatestRelease($releases, 'beta')['tag_name'] ?? null);
        self::assertSame('v1.6.0-nightly.20260515', $resolver->selectLatestRelease($releases, 'dev')['tag_name'] ?? null);
    }

    public function testCoreAndAgentAssetsRequireChecksumAndAllowOptionalSignature(): void
    {
        $resolver = $this->resolver([]);
        $releases = [[
            'draft' => false,
            'prerelease' => false,
            'tag_name' => 'v1.4.0',
            'assets' => [
                ['name' => 'easywi-core.tar.gz', 'browser_download_url' => 'https://example.invalid/easywi-core.tar.gz'],
                ['name' => 'checksums-core.txt', 'browser_download_url' => 'https://example.invalid/checksums-core.txt'],
                ['name' => 'easywi-agent-linux-amd64', 'browser_download_url' => 'https://example.invalid/easywi-agent-linux-amd64'],
                ['name' => 'easywi-agent-windows-amd64.exe', 'browser_download_url' => 'https://example.invalid/easywi-agent-windows-amd64.exe'],
                ['name' => 'checksums-agent.txt', 'browser_download_url' => 'https://example.invalid/checksums-agent.txt'],
                ['name' => 'checksums-agent.txt.asc', 'browser_download_url' => 'https://example.invalid/checksums-agent.txt.asc'],
            ],
        ]];

        $core = $resolver->selectLatestAsset($releases, 'stable', 'easywi-core.tar.gz', 'checksums-core.txt', 'checksums-core.txt.asc');
        $linux = $resolver->selectLatestAsset($releases, 'stable', 'easywi-agent-linux-amd64', 'checksums-agent.txt', 'checksums-agent.txt.asc');
        $windows = $resolver->selectLatestAsset($releases, 'stable', 'easywi-agent-windows-amd64.exe', 'checksums-agent.txt', 'checksums-agent.txt.asc');

        self::assertSame('https://example.invalid/easywi-core.tar.gz', $core['download_url'] ?? null);
        self::assertNull($core['signature_url'] ?? null);
        self::assertSame('https://example.invalid/checksums-agent.txt.asc', $linux['signature_url'] ?? null);
        self::assertSame('easywi-agent-windows-amd64.exe', $windows['asset_name'] ?? null);
    }

    public function testAssetSelectionReturnsNullWhenChecksumsAreMissing(): void
    {
        $resolver = $this->resolver([]);
        $releases = [[
            'draft' => false,
            'prerelease' => false,
            'tag_name' => 'v1.4.0',
            'assets' => [
                ['name' => 'easywi-core.tar.gz', 'browser_download_url' => 'https://example.invalid/easywi-core.tar.gz'],
            ],
        ]];

        self::assertNull($resolver->selectLatestAsset($releases, 'stable', 'easywi-core.tar.gz', 'checksums-core.txt'));
    }

    public function testHttpResolverSendsAuthorizationHeaderWithoutExposingTokenInResult(): void
    {
        $response = new MockResponse((string) json_encode([[
            'draft' => false,
            'prerelease' => false,
            'tag_name' => 'v1.4.0',
            'assets' => [
                ['name' => 'easywi-core.tar.gz', 'url' => 'https://api.github.com/repos/Matlord93/webinterface/releases/assets/100', 'browser_download_url' => 'https://github.com/Matlord93/webinterface/releases/download/v1.4.0/easywi-core.tar.gz'],
                ['name' => 'checksums-core.txt', 'url' => 'https://api.github.com/repos/Matlord93/webinterface/releases/assets/101', 'browser_download_url' => 'https://github.com/Matlord93/webinterface/releases/download/v1.4.0/checksums-core.txt'],
            ],
        ]]));
        $client = new MockHttpClient([$response]);
        $resolver = new GithubReleaseResolver($client, 'secret-token');

        $asset = $resolver->getLatestAsset('Matlord93/webinterface', 'stable', 'easywi-core.tar.gz', 'checksums-core.txt');

        self::assertIsArray($asset);
        self::assertSame('https://github.com/Matlord93/webinterface/releases/download/v1.4.0/easywi-core.tar.gz', $asset['download_url'] ?? null);
        self::assertSame('https://github.com/Matlord93/webinterface/releases/download/v1.4.0/checksums-core.txt', $asset['checksums_url'] ?? null);
        self::assertArrayNotHasKey('token', $asset);
        self::assertStringNotContainsString('secret-token', json_encode($asset, JSON_THROW_ON_ERROR));
        $headers = implode('\n', $response->getRequestOptions()['headers'] ?? []);
        self::assertStringContainsString('authorization: bearer secret-token', strtolower($headers));
    }


    public function testAuthenticatedMatchingAssetUsesPublicBrowserUrlWithoutExposingToken(): void
    {
        $response = new MockResponse((string) json_encode([[
            'draft' => false,
            'prerelease' => true,
            'tag_name' => 'v0.9.7-dev.20260515.1',
            'body' => "channel: dev\n",
            'assets' => [
                ['name' => 'easywi-webinterface-0.9.7-dev.20260515.1.zip', 'url' => 'https://api.github.com/repos/Matlord93/webinterface/releases/assets/200', 'browser_download_url' => 'https://github.com/Matlord93/webinterface/releases/download/v0.9.7-dev.20260515.1/easywi-webinterface-0.9.7-dev.20260515.1.zip'],
                ['name' => 'checksums-core.txt', 'url' => 'https://api.github.com/repos/Matlord93/webinterface/releases/assets/201', 'browser_download_url' => 'https://github.com/Matlord93/webinterface/releases/download/v0.9.7-dev.20260515.1/checksums-core.txt'],
            ],
        ]], JSON_THROW_ON_ERROR));
        $resolver = new GithubReleaseResolver(new MockHttpClient([$response]), 'secret-token');

        $asset = $resolver->getLatestAssetMatching(
            'Matlord93/webinterface',
            'dev',
            static fn (string $name, string $tag): bool => $name === 'easywi-webinterface-0.9.7-dev.20260515.1.zip',
            'checksums-core.txt',
        );

        self::assertIsArray($asset);
        self::assertSame('https://github.com/Matlord93/webinterface/releases/download/v0.9.7-dev.20260515.1/easywi-webinterface-0.9.7-dev.20260515.1.zip', $asset['download_url'] ?? null);
        self::assertSame('https://github.com/Matlord93/webinterface/releases/download/v0.9.7-dev.20260515.1/checksums-core.txt', $asset['checksums_url'] ?? null);
        self::assertArrayNotHasKey('token', $asset);
        self::assertStringNotContainsString('secret-token', json_encode($asset, JSON_THROW_ON_ERROR));
    }


    public function testAutomaticChecksUseCacheAndForceBypassesIt(): void
    {
        $requests = 0;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            ++$requests;
            return new MockResponse((string) json_encode([[
                'draft' => false,
                'prerelease' => false,
                'tag_name' => 'v1.4.' . $requests,
            ]], JSON_THROW_ON_ERROR), [
                'response_headers' => [
                    'etag: "release-' . $requests . '"',
                    'last-modified: Fri, 15 May 2026 00:00:00 GMT',
                ],
            ]);
        });
        $resolver = new GithubReleaseResolver($client, null, new ArrayAdapter(), 3600);

        self::assertSame('v1.4.1', $resolver->getLatestVersion('Matlord93/webinterface', 'stable', 'core'));
        self::assertSame('v1.4.1', $resolver->getLatestVersion('Matlord93/webinterface', 'stable', 'core'));
        self::assertSame(1, $requests);

        self::assertSame('v1.4.2', $resolver->getLatestVersion('Matlord93/webinterface', 'stable', 'core', true));
        self::assertSame(2, $requests);
    }

    public function testExpiredCacheRefreshesAndUsesConditionalHeaders(): void
    {
        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = $options['headers'] ?? [];
            if (count($requests) === 2) {
                return new MockResponse('', ['http_code' => 304]);
            }

            return new MockResponse((string) json_encode([[
                'draft' => false,
                'prerelease' => false,
                'tag_name' => 'v2.0.0',
            ]], JSON_THROW_ON_ERROR), [
                'response_headers' => [
                    'etag: "abc"',
                    'last-modified: Fri, 15 May 2026 00:00:00 GMT',
                ],
            ]);
        });
        $resolver = new GithubReleaseResolver($client, null, new ArrayAdapter(), 1);

        self::assertSame('v2.0.0', $resolver->getLatestVersion('Matlord93/webinterface', 'stable', 'core'));
        sleep(2);
        self::assertSame('v2.0.0', $resolver->getLatestVersion('Matlord93/webinterface', 'stable', 'core'));
        self::assertCount(2, $requests);
        self::assertStringContainsString('if-none-match: "abc"', strtolower(implode("\n", $requests[1])));
    }

    public function testRateLimitKeepsStaleCacheAndBlocksAutomaticRequestsUntilReset(): void
    {
        $requests = 0;
        $reset = time() + 3600;
        $client = new MockHttpClient(function () use (&$requests, $reset): MockResponse {
            ++$requests;
            if ($requests === 1) {
                return new MockResponse((string) json_encode([[
                    'draft' => false,
                    'prerelease' => false,
                    'tag_name' => 'v3.0.0',
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
        $resolver = new GithubReleaseResolver($client, null, new ArrayAdapter(), 1);

        self::assertSame('v3.0.0', $resolver->getLatestVersion('Matlord93/webinterface', 'stable', 'core'));
        sleep(2);
        self::assertSame('v3.0.0', $resolver->getLatestVersion('Matlord93/webinterface', 'stable', 'core'));
        self::assertSame('v3.0.0', $resolver->getLatestVersion('Matlord93/webinterface', 'stable', 'core'));
        self::assertSame(2, $requests);
        $status = $resolver->getCacheStatus('Matlord93/webinterface', 'stable', 'core');
        self::assertSame($reset, $status['rate_limit_reset']);
        self::assertSame(GithubReleaseResolver::ERROR_RATE_LIMIT, $status['last_error_type']);
        self::assertTrue($status['has_cache']);
    }


    public function testParallelAutomaticCheckReturnsStaleCacheWhenLockIsActive(): void
    {
        $requests = 0;
        $client = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;
            return new MockResponse((string) json_encode([[
                'draft' => false,
                'prerelease' => false,
                'tag_name' => 'v4.0.0',
            ]], JSON_THROW_ON_ERROR));
        });
        $lockDir = sys_get_temp_dir() . '/easywi-test-locks-' . bin2hex(random_bytes(4));
        $resolver = new GithubReleaseResolver($client, null, new ArrayAdapter(), 1, $lockDir);

        self::assertSame('v4.0.0', $resolver->getLatestVersion('Matlord93/webinterface', 'stable', 'core'));
        sleep(2);

        $lockPath = $lockDir . '/' . sha1('Matlord93/webinterface|stable|core') . '.lock';
        $handle = fopen($lockPath, 'c');
        self::assertIsResource($handle);
        self::assertTrue(flock($handle, LOCK_EX | LOCK_NB));
        try {
            self::assertSame('v4.0.0', $resolver->getLatestVersion('Matlord93/webinterface', 'stable', 'core'));
            self::assertSame(1, $requests);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function testRateLimitWithoutCacheReturnsClearStatusWithoutTokenLeak(): void
    {
        $reset = time() + 3600;
        $response = new MockResponse('{"message":"API rate limit exceeded"}', [
            'http_code' => 403,
            'response_headers' => [
                'x-ratelimit-remaining: 0',
                'x-ratelimit-reset: ' . $reset,
            ],
        ]);
        $resolver = new GithubReleaseResolver(new MockHttpClient([$response]), 'secret-token', new ArrayAdapter(), 3600);

        self::assertNull($resolver->getLatestVersion('Matlord93/webinterface', 'stable', 'core'));
        $status = $resolver->getCacheStatus('Matlord93/webinterface', 'stable', 'core');
        self::assertFalse($status['has_cache']);
        self::assertSame($reset, $status['rate_limit_reset']);
        self::assertSame(GithubReleaseResolver::ERROR_RATE_LIMIT, $status['last_error_type']);
        self::assertStringContainsString('GitHub API Rate Limit erreicht', (string) $status['last_error']);
        self::assertStringNotContainsString('secret-token', (string) $status['last_error']);
    }


    public function testForbiddenWithoutRateLimitIsAccessDenied(): void
    {
        $response = new MockResponse('{"message":"Forbidden"}', [
            'http_code' => 403,
            'response_headers' => [
                'x-ratelimit-remaining: 42',
            ],
        ]);
        $resolver = new GithubReleaseResolver(new MockHttpClient([$response]), null, new ArrayAdapter(), 3600);

        self::assertNull($resolver->getLatestVersion('Matlord93/webinterface', 'stable', 'core'));
        $status = $resolver->getCacheStatus('Matlord93/webinterface', 'stable', 'core');
        self::assertSame(403, $status['last_http_status']);
        self::assertSame(42, $status['rate_limit_remaining']);
        self::assertSame(GithubReleaseResolver::ERROR_ACCESS_DENIED, $status['last_error_type']);
    }


    public function testNotFoundIsClassifiedSeparately(): void
    {
        $resolver = new GithubReleaseResolver(new MockHttpClient([
            new MockResponse('{"message":"Not Found"}', ['http_code' => 404]),
        ]), null, new ArrayAdapter(), 3600);

        self::assertNull($resolver->getLatestVersion('Matlord93/missing', 'stable', 'core'));
        $status = $resolver->getCacheStatus('Matlord93/missing', 'stable', 'core');
        self::assertSame(404, $status['last_http_status']);
        self::assertSame(GithubReleaseResolver::ERROR_NOT_FOUND, $status['last_error_type']);
    }

    public function testApiAssetUrlIsNotPreferredWhenPublicBrowserDownloadUrlExists(): void
    {
        $response = new MockResponse((string) json_encode([[
            'draft' => false,
            'prerelease' => false,
            'tag_name' => 'v5.0.0',
            'assets' => [
                ['name' => 'easywi-agent-linux-amd64', 'url' => 'https://api.github.com/repos/Matlord93/Easy-Wi-NextGen/releases/assets/900', 'browser_download_url' => 'https://github.com/Matlord93/Easy-Wi-NextGen/releases/download/v5.0.0/easywi-agent-linux-amd64'],
                ['name' => 'checksums-agent.txt', 'url' => 'https://api.github.com/repos/Matlord93/Easy-Wi-NextGen/releases/assets/901', 'browser_download_url' => 'https://github.com/Matlord93/Easy-Wi-NextGen/releases/download/v5.0.0/checksums-agent.txt'],
            ],
        ]], JSON_THROW_ON_ERROR));
        $resolver = new GithubReleaseResolver(new MockHttpClient([$response]), 'secret-token', new ArrayAdapter(), 3600);

        $asset = $resolver->getLatestAsset('Matlord93/Easy-Wi-NextGen', 'stable', 'easywi-agent-linux-amd64', 'checksums-agent.txt', null, null, 'agent');

        self::assertIsArray($asset);
        self::assertSame('https://github.com/Matlord93/Easy-Wi-NextGen/releases/download/v5.0.0/easywi-agent-linux-amd64', $asset['download_url']);
        self::assertSame('https://github.com/Matlord93/Easy-Wi-NextGen/releases/download/v5.0.0/checksums-agent.txt', $asset['checksums_url']);
    }

    /** @return array<int, array<string, mixed>> */
    private function mixedReleases(): array
    {
        return [
            ['draft' => false, 'prerelease' => false, 'tag_name' => 'v1.4.0'],
            ['draft' => false, 'prerelease' => false, 'tag_name' => 'v1.4.1'],
            ['draft' => false, 'prerelease' => true, 'tag_name' => 'v1.5.0-beta.1'],
            ['draft' => false, 'prerelease' => true, 'tag_name' => 'v1.5.0-rc.1'],
            ['draft' => false, 'prerelease' => true, 'tag_name' => 'v1.6.0-dev.20260515'],
            ['draft' => false, 'prerelease' => true, 'tag_name' => 'v1.6.0-nightly.20260515'],
        ];
    }

    /** @param array<int, mixed> $responses */
    private function resolver(array $responses): GithubReleaseResolver
    {
        return new GithubReleaseResolver(new MockHttpClient($responses));
    }
}
