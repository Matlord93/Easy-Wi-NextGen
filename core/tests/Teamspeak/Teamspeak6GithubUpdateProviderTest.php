<?php

declare(strict_types=1);

namespace App\Tests\Teamspeak;

use App\Module\Teamspeak\Application\Update\Teamspeak6GithubUpdateProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class Teamspeak6GithubUpdateProviderTest extends TestCase
{
    public function testSelectsMatchingAsset(): void
    {
        $provider = new Teamspeak6GithubUpdateProvider($this->clientWith([]));
        $asset = $provider->selectAsset([
            ['name' => 'teamspeak6-server-linux-amd64.tar.xz', 'browser_download_url' => 'https://github.com/teamspeak/teamspeak6-server/releases/download/v1/file.tar.xz'],
        ], 'linux', 'amd64');
        self::assertNotNull($asset);
    }

    public function testNoUpdateWhenVersionsEqual(): void
    {
        $provider = new Teamspeak6GithubUpdateProvider($this->clientWith([[ 'tag_name' => 'v1.2.3', 'prerelease' => false, 'assets' => [['name'=>'teamspeak6-server-linux-amd64.tar.xz','browser_download_url'=>'https://github.com/teamspeak/teamspeak6-server/releases/download/v1/f.tar.xz', 'size' => 123]], 'body'=>'notes' ]]));
        $result = $provider->checkForUpdates('1.2.3', 'linux', 'amd64');
        self::assertFalse($result->updateAvailable);
        self::assertSame('up_to_date', $result->status);
        self::assertSame('teamspeak6-server-linux-amd64.tar.xz', $result->assetName);
        self::assertSame('https://github.com/teamspeak/teamspeak6-server/releases/download/v1/f.tar.xz', $result->assetUrl);
    }

    public function testHandlesGithubFailure(): void
    {
        $client = new class implements HttpClientInterface { public function request(string $method, string $url, array $options = []): ResponseInterface { throw new \RuntimeException('offline'); } public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface { return new class implements ResponseStreamInterface { public function key(): ResponseInterface { throw new \LogicException('empty'); } public function current(): ChunkInterface { throw new \LogicException('empty'); } public function valid(): bool { return false; } public function next(): void {} public function rewind(): void {} }; } public function withOptions(array $options): static { return $this; } };
        $provider = new Teamspeak6GithubUpdateProvider($client);
        $result = $provider->checkForUpdates('1.0.0', 'linux', 'amd64');
        self::assertSame('github_unreachable', $result->status);
    }

    public function testRecognizesUpdateAndNormalizesInstalledVersion(): void
    {
        $provider = new Teamspeak6GithubUpdateProvider($this->clientWith([
            ['tag_name' => 'v6.0.0-beta10', 'prerelease' => true, 'assets' => [['name' => 'teamspeak6-server-linux-amd64.tar.xz', 'browser_download_url' => 'https://github.com/teamspeak/teamspeak6-server/releases/download/v6.0.0-beta10/teamspeak6-server-linux-amd64.tar.xz']]],
        ]));
        $result = $provider->checkForUpdates('6.0.0-beta8.tar.bz2', 'linux', 'amd64', 'beta');
        self::assertTrue($result->updateAvailable);
        self::assertSame('6.0.0-beta8', $result->installedVersion);
        self::assertSame('6.0.0-beta10', $result->availableVersion);
    }

    public function testSelectsLegacyAssetNameVariants(): void
    {
        $provider = new Teamspeak6GithubUpdateProvider($this->clientWith([]));
        $asset = $provider->selectAsset([
            ['name' => 'teamspeak-server_linux_amd64-v6.0.0-beta8.tar.bz2', 'browser_download_url' => 'https://github.com/teamspeak/teamspeak6-server/releases/download/v6.0.0-beta8/teamspeak-server_linux_amd64-v6.0.0-beta8.tar.bz2'],
        ], 'linux', 'amd64');
        self::assertNotNull($asset);
    }

    public function testUsesHighestVersionWhenGithubReturnsOlderReleaseFirst(): void
    {
        $provider = new Teamspeak6GithubUpdateProvider($this->clientWith([
            ['tag_name' => 'v6.0.0-beta8', 'prerelease' => true, 'published_at' => '2026-01-01T00:00:00Z', 'assets' => [['name' => 'teamspeak6-server-linux-amd64.tar.xz', 'browser_download_url' => 'https://github.com/teamspeak/teamspeak6-server/releases/download/v6.0.0-beta8/teamspeak6-server-linux-amd64.tar.xz']]],
            ['tag_name' => 'v6.0.0-beta10', 'prerelease' => true, 'published_at' => '2026-02-01T00:00:00Z', 'assets' => [['name' => 'teamspeak6-server-linux-amd64.tar.xz', 'browser_download_url' => 'https://github.com/teamspeak/teamspeak6-server/releases/download/v6.0.0-beta10/teamspeak6-server-linux-amd64.tar.xz']]],
        ]));

        $result = $provider->checkForUpdates('6.0.0-beta8', 'linux', 'amd64', 'beta');

        self::assertTrue($result->updateAvailable);
        self::assertSame('6.0.0-beta10', $result->availableVersion);
        self::assertSame('https://github.com/teamspeak/teamspeak6-server/releases/download/v6.0.0-beta10/teamspeak6-server-linux-amd64.tar.xz', $result->assetUrl);
    }

    public function testLatestAssetUrlSkipsReleaseWithoutMatchingAsset(): void
    {
        $provider = new Teamspeak6GithubUpdateProvider($this->clientWith([
            ['tag_name' => 'v6.0.0-beta11', 'prerelease' => true, 'assets' => [['name' => 'teamspeak6-server-linux-arm64.tar.xz', 'browser_download_url' => 'https://github.com/teamspeak/teamspeak6-server/releases/download/v6.0.0-beta11/teamspeak6-server-linux-arm64.tar.xz']]],
            ['tag_name' => 'v6.0.0-beta10', 'prerelease' => true, 'assets' => [['name' => 'teamspeak6-server-linux-amd64.tar.xz', 'browser_download_url' => 'https://github.com/teamspeak/teamspeak6-server/releases/download/v6.0.0-beta10/teamspeak6-server-linux-amd64.tar.xz']]],
        ]));

        self::assertSame('https://github.com/teamspeak/teamspeak6-server/releases/download/v6.0.0-beta10/teamspeak6-server-linux-amd64.tar.xz', $provider->resolveLatestAssetUrl('linux', 'amd64', 'beta'));
    }

    private function clientWith(array $payload): HttpClientInterface
    {
        return new class($payload) implements HttpClientInterface {
            public function __construct(private array $payload) {}
            public function request(string $method, string $url, array $options = []): ResponseInterface { return new class($this->payload) implements ResponseInterface { public function __construct(private array $payload) {} public function getStatusCode(): int { return 200; } public function getHeaders(bool $throw = true): array { return []; } public function getContent(bool $throw = true): string { return json_encode($this->payload) ?: '[]'; } public function toArray(bool $throw = true): array { return $this->payload; } public function cancel(): void {} public function getInfo(?string $type = null): mixed { return null; } }; }
            public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface { return new class implements ResponseStreamInterface { public function key(): ResponseInterface { throw new \LogicException('empty'); } public function current(): ChunkInterface { throw new \LogicException('empty'); } public function valid(): bool { return false; } public function next(): void {} public function rewind(): void {} }; }
            public function withOptions(array $options): static { return $this; }
        };
    }
}
