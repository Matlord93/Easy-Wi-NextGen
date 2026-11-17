<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Gameserver\Application\MinecraftUpstreamCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MinecraftOfficialReleaseFallbackTest extends TestCase
{
    private const BUNGEE_JENKINS = 'https://hub.spigotmc.org/jenkins/job/BungeeCord/api/json?tree=builds[number,result,timestamp,artifacts[fileName,relativePath]]';
    private const BUNGEE_RELEASE = 'https://api.github.com/repos/SpigotMC/BungeeCord/releases/latest';
    private const BUNGEE_ASSET = 'https://github.com/SpigotMC/BungeeCord/releases/download/build/b2113/BungeeCord.jar';
    private const TOOLS_JENKINS = 'https://hub.spigotmc.org/jenkins/job/BuildTools/lastSuccessfulBuild/api/json?tree=number';
    private const TOOLS_RELEASE = 'https://api.github.com/repos/SpigotMC/BuildTools/releases/latest';
    private const TOOLS_ASSET = 'https://github.com/SpigotMC/BuildTools/releases/download/build/b202/BuildTools.jar';

    public function testBungeeJenkins403UsesPinnedOfficialReleaseAndVerifiedDigest(): void
    {
        $requests = [];
        $catalog = $this->catalog([self::BUNGEE_JENKINS => new MockResponse('Forbidden', ['http_code' => 403]), self::BUNGEE_RELEASE => $this->release()], $requests);
        $entries = iterator_to_array($catalog->entries('bungeecord'));
        self::assertCount(1, $entries);
        self::assertSame('BungeeCord', $entries[0]['version']);
        self::assertSame('2113', $entries[0]['build']);
        self::assertSame(self::BUNGEE_ASSET, $entries[0]['url']);
        self::assertSame(str_repeat('a', 64), $entries[0]['sha256']);
        self::assertSame('17', $entries[0]['java']);
        self::assertSame('proxy', $entries[0]['metadata']['strategy']);
        self::assertSame([self::BUNGEE_JENKINS, self::BUNGEE_RELEASE], $requests);
    }

    #[DataProvider('unusableJenkins')]
    public function testBungeeInvalidOrEmptyJenkinsFallsBackWithoutInventingBuilds(mixed $data): void
    {
        $requests = [];
        $catalog = $this->catalog([self::BUNGEE_JENKINS => $data, self::BUNGEE_RELEASE => $this->release()], $requests);
        self::assertSame('2113', iterator_to_array($catalog->entries('bungeecord'))[0]['build']);
    }

    public static function unusableJenkins(): iterable
    {
        yield 'invalid json' => ['{invalid'];
        yield 'missing build list' => [[]];
        yield 'no builds' => [['builds' => []]];
        yield 'failed builds only' => [['builds' => [['number' => 2114, 'result' => 'FAILURE', 'artifacts' => [['fileName' => 'BungeeCord.jar', 'relativePath' => 'BungeeCord.jar']]]]]];
        yield 'no jar' => [['builds' => [['number' => 2114, 'result' => 'SUCCESS', 'artifacts' => [['fileName' => 'source.zip', 'relativePath' => 'source.zip']]]]]];
        yield 'invalid build identity' => [['builds' => [['number' => 'invalid', 'result' => 'SUCCESS', 'artifacts' => [['fileName' => 'BungeeCord.jar', 'relativePath' => 'BungeeCord.jar']]]]]];
    }

    public function testBungeeKeepsAllSuccessfulJenkinsHistoryWithoutRequestingGithub(): void
    {
        $requests = [];
        $catalog = $this->catalog([self::BUNGEE_JENKINS => ['builds' => array_map(static fn (int $number): array => ['number' => $number, 'result' => 'SUCCESS', 'artifacts' => [['fileName' => 'BungeeCord.jar', 'relativePath' => 'bootstrap/target/BungeeCord.jar']]], [2112, 2111])]], $requests);
        self::assertSame(['2112', '2111'], array_column(iterator_to_array($catalog->entries('bungeecord')), 'build'));
        self::assertSame([self::BUNGEE_JENKINS], $requests);
    }

    #[DataProvider('unsafeAssets')]
    public function testGithubFallbackRejectsUnrelatedAndUnsafeAssetUrls(string $url): void
    {
        $requests = [];
        $release = $this->release();
        $release['assets'][0]['browser_download_url'] = $url;
        $catalog = $this->catalog([self::BUNGEE_JENKINS => ['builds' => []], self::BUNGEE_RELEASE => $release], $requests);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Jenkins metadata and official GitHub release are unavailable');
        iterator_to_array($catalog->entries('bungeecord'));
    }

    public static function unsafeAssets(): iterable
    {
        foreach (['http://github.com/SpigotMC/BungeeCord/releases/download/build/b2113/BungeeCord.jar', 'https://github.com.evil.test/SpigotMC/BungeeCord/releases/download/build/b2113/BungeeCord.jar', 'https://github.com/Other/BungeeCord/releases/download/build/b2113/BungeeCord.jar', 'https://github.com/SpigotMC/BuildTools/releases/download/build/b2113/BungeeCord.jar', 'https://github.com/SpigotMC/BungeeCord/releases/download/build/b2112/BungeeCord.jar', self::BUNGEE_ASSET.'?redirect=evil', self::BUNGEE_ASSET.'#fragment', 'https://user@github.com/SpigotMC/BungeeCord/releases/download/build/b2113/BungeeCord.jar'] as $url) {
            yield $url => [$url];
        }
    }

    public function testBothUnavailableSourcesFailInsteadOfReturningEmptySuccess(): void
    {
        $requests = [];
        $catalog = $this->catalog([self::BUNGEE_JENKINS => new MockResponse('', ['http_code' => 403]), self::BUNGEE_RELEASE => new MockResponse('', ['http_code' => 503])], $requests);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no usable build was returned');
        iterator_to_array($catalog->entries('bungeecord'));
    }

    public function testMalformedPublishedDigestIsRejected(): void
    {
        $requests = [];
        $release = $this->release();
        $release['assets'][0]['digest'] = 'sha256:not-a-digest';
        $catalog = $this->catalog([self::BUNGEE_JENKINS => ['builds' => []], self::BUNGEE_RELEASE => $release], $requests);
        $this->expectException(\RuntimeException::class);
        iterator_to_array($catalog->entries('bungeecord'));
    }

    public function testBungeeMissingDigestDoesNotInventChecksum(): void
    {
        $requests = [];
        $release = $this->release();
        unset($release['assets'][0]['digest']);
        $catalog = $this->catalog([self::BUNGEE_JENKINS => ['builds' => []], self::BUNGEE_RELEASE => $release], $requests);
        self::assertNull(iterator_to_array($catalog->entries('bungeecord'))[0]['sha256']);
    }

    public function testBuildToolsJenkins403UsesOfficialReleaseDigestAndJavaMetadata(): void
    {
        $requests = [];
        $catalog = $this->catalog($this->spigotMetadata() + [self::TOOLS_JENKINS => new MockResponse('', ['http_code' => 403]), self::TOOLS_RELEASE => $this->release('BuildTools', '202')], $requests);
        $entry = iterator_to_array($catalog->entries('spigot'))[0];
        self::assertSame(self::TOOLS_ASSET, $entry['url']);
        self::assertSame(str_repeat('a', 64), $entry['sha256']);
        self::assertSame('21', $entry['java']);
        self::assertSame('buildtools', $entry['metadata']['strategy']);
        self::assertSame(202, $entry['metadata']['buildtools_build']);
        self::assertNotContains(self::TOOLS_ASSET, $requests, 'Published checksum avoids an unnecessary binary download on the panel.');
    }

    public function testBuildToolsArtifactFailureAlsoFallsBackToOfficialRelease(): void
    {
        $requests = [];
        $catalog = $this->catalog($this->spigotMetadata() + [self::TOOLS_JENKINS => ['number' => 201], 'https://hub.spigotmc.org/jenkins/job/BuildTools/201/artifact/target/BuildTools.jar' => new MockResponse('', ['http_code' => 403]), self::TOOLS_RELEASE => $this->release('BuildTools', '202')], $requests);
        self::assertSame(self::TOOLS_ASSET, iterator_to_array($catalog->entries('spigot'))[0]['url']);
    }

    public function testBuildToolsWithoutPublishedDigestStreamsActualPinnedArtifact(): void
    {
        $requests = [];
        $release = $this->release('BuildTools', '202');
        unset($release['assets'][0]['digest']);
        $catalog = $this->catalog($this->spigotMetadata() + [self::TOOLS_JENKINS => [], self::TOOLS_RELEASE => $release, self::TOOLS_ASSET => 'actual verified BuildTools bytes'], $requests);
        self::assertSame(hash('sha256', 'actual verified BuildTools bytes'), iterator_to_array($catalog->entries('spigot'))[0]['sha256']);
        self::assertContains(self::TOOLS_ASSET, $requests);
    }

    public function testBuildToolsBothSourcesUnavailableFailsVisibly(): void
    {
        $requests = [];
        $catalog = $this->catalog($this->spigotMetadata() + [self::TOOLS_JENKINS => [], self::TOOLS_RELEASE => ['assets' => []]], $requests);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('BuildTools Jenkins metadata/artifact and official GitHub release are unavailable');
        iterator_to_array($catalog->entries('spigot'));
    }

    private function release(string $repository = 'BungeeCord', string $build = '2113'): array
    {
        return ['tag_name' => 'build/b'.$build, 'draft' => false, 'prerelease' => false, 'published_at' => '2026-11-17T12:00:00Z',
            'assets' => [['name' => $repository.'.jar', 'state' => 'uploaded', 'browser_download_url' => 'https://github.com/SpigotMC/'.$repository.'/releases/download/build/b'.$build.'/'.$repository.'.jar', 'digest' => 'sha256:'.str_repeat('a', 64)]]];
    }

    private function spigotMetadata(): array
    {
        return ['https://hub.spigotmc.org/versions/' => '<a href="1.21.11.json">1.21.11</a>', 'https://hub.spigotmc.org/versions/1.21.11.json' => ['javaVersions' => [65, 70], 'refs' => ['BuildData' => 'pinned']]];
    }

    private function catalog(array $responses, array &$requests): MinecraftUpstreamCatalog
    {
        return new MinecraftUpstreamCatalog(new MockHttpClient(static function (string $method, string $url) use ($responses, &$requests): MockResponse {
            $requests[] = $url;
            if (!array_key_exists($url, $responses)) { throw new \RuntimeException('Unexpected catalog request: '.$url); }
            $response = $responses[$url];
            return $response instanceof MockResponse ? $response : new MockResponse(is_array($response) ? json_encode($response, JSON_THROW_ON_ERROR) : $response);
        }), new ArrayAdapter());
    }
}
