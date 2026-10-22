<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Gameserver\Application\MinecraftUpstreamCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MinecraftUpstreamCatalogTest extends TestCase
{
    private function provider(array $responses): MinecraftUpstreamCatalog
    {
        $http = new MockHttpClient(static function (string $method, string $url) use ($responses): MockResponse {
            if (!array_key_exists($url, $responses)) {
                throw new \RuntimeException('Unexpected provider request: '.$url);
            }

            return new MockResponse(is_array($responses[$url]) ? json_encode($responses[$url], JSON_THROW_ON_ERROR) : $responses[$url]);
        });

        return new MinecraftUpstreamCatalog($http, new ArrayAdapter());
    }

    public function testVanillaUsesOfficialJavaAndSha1AndSkipsClientOnlyVersions(): void
    {
        $provider = $this->provider([
            'https://piston-meta.mojang.com/mc/game/version_manifest_v2.json' => ['versions' => [
                ['id' => '1.21.11', 'url' => 'https://piston-meta.mojang.com/new.json', 'type' => 'release'],
                ['id' => 'old-client', 'url' => 'https://piston-meta.mojang.com/old.json', 'type' => 'old_alpha'],
            ]],
            'https://piston-meta.mojang.com/new.json' => ['javaVersion' => ['majorVersion' => 21], 'downloads' => ['server' => ['url' => 'https://piston-data.mojang.com/server.jar', 'sha1' => str_repeat('a', 40)]]],
            'https://piston-meta.mojang.com/old.json' => ['downloads' => ['client' => []]],
        ]);
        $entries = iterator_to_array($provider->entries('vanilla'));
        self::assertCount(1, $entries);
        self::assertSame('21', $entries[0]['java']);
        self::assertSame(str_repeat('a', 40), $entries[0]['metadata']['sha1']);
    }

    public function testPaperV3GroupsVersionsAndKeepsSelectableBuilds(): void
    {
        $provider = $this->provider([
            'https://fill.papermc.io/v3/projects/paper' => ['versions' => ['1.21' => ['1.21.11']]],
            'https://fill.papermc.io/v3/projects/paper/versions/1.21.11/builds' => array_map(static fn (int $id): array => ['id' => $id, 'channel' => 'STABLE', 'downloads' => ['server:default' => ['url' => 'https://fill-data.papermc.io/'.$id.'.jar', 'checksums' => ['sha256' => str_repeat('b', 64)]]]], [132, 131]),
        ]);
        $entries = iterator_to_array($provider->entries('paper'));
        self::assertSame(['132', '131'], array_column($entries, 'build'));
        self::assertSame(str_repeat('b', 64), $entries[0]['sha256']);
    }

    public function testSpigotPinsBuildToolsAndHonorsJavaClassVersionMetadata(): void
    {
        $provider = $this->provider([
            'https://hub.spigotmc.org/versions/' => '<a href="1.21.11.json">1.21.11</a>',
            'https://hub.spigotmc.org/versions/1.21.11.json' => ['javaVersions' => [65, 70]],
            'https://hub.spigotmc.org/jenkins/job/BuildTools/lastSuccessfulBuild/api/json?tree=number' => ['number' => 200],
            'https://hub.spigotmc.org/jenkins/job/BuildTools/200/artifact/target/BuildTools.jar' => 'verified HTTPS BuildTools bytes',
        ]);
        $entry = iterator_to_array($provider->entries('spigot'))[0];
        self::assertSame('21', $entry['java']);
        self::assertSame('buildtools', $entry['metadata']['strategy']);
        self::assertSame(hash('sha256', 'verified HTTPS BuildTools bytes'), $entry['sha256']);
    }

    public function testBedrockUsesCurrentOfficialLinuxDownload(): void
    {
        $provider = $this->provider(['https://net-secondary.web.minecraft-services.net/api/v1.0/download/links' => ['result' => ['links' => [
            ['downloadType' => 'serverBedrockWindows', 'downloadUrl' => 'https://www.minecraft.net/bedrock-server-1.26.52.3.zip'],
            ['downloadType' => 'serverBedrockLinux', 'downloadUrl' => 'https://www.minecraft.net/bin-linux/bedrock-server-1.26.52.3.zip'],
        ]]]]);
        $entries = iterator_to_array($provider->entries('bedrock'));
        self::assertCount(1, $entries);
        self::assertSame('1.26.52.3', $entries[0]['version']);
        self::assertArrayNotHasKey('java', $entries[0]);
    }

    public function testBungeeUsesSuccessfulPinnedBuildArtifacts(): void
    {
        $base = 'https://hub.spigotmc.org/jenkins/job/BungeeCord';
        $provider = $this->provider([$base.'/api/json?tree=builds[number,result,timestamp,artifacts[fileName,relativePath]]' => ['builds' => [
            ['number' => 2104, 'result' => 'SUCCESS', 'artifacts' => [['fileName' => 'BungeeCord.jar', 'relativePath' => 'bootstrap/target/BungeeCord.jar']]],
            ['number' => 2105, 'result' => 'FAILURE', 'artifacts' => []],
        ]]]);
        $entries = iterator_to_array($provider->entries('bungeecord'));
        self::assertCount(1, $entries);
        self::assertSame('2104', $entries[0]['build']);
        self::assertSame($base.'/2104/artifact/bootstrap/target/BungeeCord.jar', $entries[0]['url']);
    }

    public function testFailedProviderDoesNotTurnIntoAnEmptySuccessfulCatalog(): void
    {
        $provider = $this->provider(['https://fill.papermc.io/v3/projects/paper' => ['versions' => []]]);
        $this->expectException(\RuntimeException::class);
        iterator_to_array($provider->entries('paper'));
    }
}
