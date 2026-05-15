<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Module\Core\Application\AgentReleaseChecker;
use App\Module\Core\Application\GithubReleaseResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;

final class AgentReleaseCheckerTest extends TestCase
{
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

        $method = new \ReflectionMethod($checker, 'selectLatestReleaseAsset');

        $selected = $method->invoke($checker, $releases, 'beta', 'easywi-agent-linux-amd64');

        self::assertIsArray($selected);
        self::assertSame('1.1.1-beta.1', $selected['tag']);
        self::assertSame('https://example.invalid/1.1.1.1/easywi-agent-linux-amd64', $selected['download_url']);
    }

    public function testFetchLatestVersionComparesSemverLikeTags(): void
    {
        $checker = $this->checker('beta');

        $method = new \ReflectionMethod($checker, 'compareReleaseTags');

        $result = $method->invoke($checker, '1.1.1-beta.1', '1.1.0-beta.4');

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

        $method = new \ReflectionMethod($checker, 'selectLatestReleaseAsset');
        $selected = $method->invoke($checker, $releases, 'stable', 'easywi-agent-linux-amd64', '2.0.0');

        self::assertIsArray($selected);
        self::assertSame('v2.0.0', $selected['tag']);
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

        $method = new \ReflectionMethod($checker, 'selectLatestReleaseAsset');

        $stable = $method->invoke($checker, $releases, 'stable', 'easywi-agent-linux-amd64');
        $beta = $method->invoke($checker, $releases, 'beta', 'easywi-agent-linux-amd64');
        $dev = $method->invoke($checker, $releases, 'dev', 'easywi-agent-linux-amd64');

        self::assertSame('v2.0.0', $stable['tag'] ?? null);
        self::assertSame('v2.1.0-beta.1', $beta['tag'] ?? null);
        self::assertSame('v2.2.0-dev.3', $dev['tag'] ?? null);
    }

    public function testAlphaChannelAliasNormalizesToDev(): void
    {
        $checker = $this->checker('alpha');

        self::assertSame('dev', $checker->getChannel());
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
