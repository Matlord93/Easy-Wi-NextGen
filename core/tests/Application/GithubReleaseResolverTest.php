<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Module\Core\Application\GithubReleaseResolver;
use PHPUnit\Framework\TestCase;
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
        self::assertSame('https://api.github.com/repos/Matlord93/webinterface/releases/assets/100', $asset['download_url'] ?? null);
        self::assertSame('https://api.github.com/repos/Matlord93/webinterface/releases/assets/101', $asset['checksums_url'] ?? null);
        self::assertArrayNotHasKey('token', $asset);
        self::assertStringNotContainsString('secret-token', json_encode($asset, JSON_THROW_ON_ERROR));
        $headers = implode('\n', $response->getRequestOptions()['headers'] ?? []);
        self::assertStringContainsString('authorization: bearer secret-token', strtolower($headers));
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
