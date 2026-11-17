<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

use App\Infrastructure\Config\JsonArray;
use App\Infrastructure\Config\ScalarValue;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches metadata only; server binaries are downloaded on the node.
 *
 * @psalm-type CatalogEntry = array{version: string, url: string, build?: string, sha256?: ?string,
 * java?: string, released?: ?string, metadata: array<array-key, mixed>}
 */
final class MinecraftUpstreamCatalog
{
    public function __construct(private readonly HttpClientInterface $http, private readonly CacheInterface $cache)
    {
    }

    /** @return \Generator<int, CatalogEntry> */
    public function entries(string $channel): iterable
    {
        switch ($channel) {
            case 'vanilla':
                yield from $this->vanilla();
                return;
            case 'paper':
                yield from $this->paper();
                return;
            case 'spigot':
                yield from $this->spigot();
                return;
            case 'bungeecord':
                yield from $this->bungee();
                return;
            case 'bedrock':
                yield from $this->bedrock();
                return;
            default:
                throw new \InvalidArgumentException('Unsupported Minecraft channel.');
        }
    }

    /** @return array<array-key, mixed> */
    private function json(string $url, int $ttl = 3600): array
    {
        return $this->cachedJson($this->cache->get('minecraft.upstream.'.hash('sha256', $url), function (ItemInterface $item) use ($url, $ttl): array {
            $item->expiresAfter($ttl);

            return $this->http->request('GET', $url, ['headers' => ['User-Agent' => 'EasyWi-MinecraftCatalog/1.0 (https://github.com/Matlord93/webinterface)'], 'timeout' => 20, 'max_duration' => 45])->toArray();
        }));
    }

    /** @return array<array-key, mixed> */
    private function cachedJson(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \RuntimeException('Invalid cached upstream metadata.');
        }

        return $value;
    }

    /** @return \Generator<int, array<array-key, mixed>> */
    private function rows(mixed $value): \Generator
    {
        if (!is_array($value)) {
            return;
        }
        foreach (array_keys($value) as $key) {
            if (is_array($value[$key])) {
                yield $value[$key];
            }
        }
    }

    /** @return \Generator<int, CatalogEntry> */
    private function vanilla(): iterable
    {
        $manifest = $this->json('https://piston-meta.mojang.com/mc/game/version_manifest_v2.json');
        if (!isset($manifest['versions']) || !is_array($manifest['versions'])) {
            throw new \RuntimeException('Invalid Mojang version manifest.');
        }
        foreach ($this->rows($manifest['versions']) as $version) {
            $id = ScalarValue::text($version['id'] ?? null);
            $url = ScalarValue::text($version['url'] ?? null);
            if ('' === $id || !str_starts_with($url, 'https://piston-meta.mojang.com/')) {
                continue;
            }
            $details = $this->json($url, 86400 * 30);
            $server = JsonArray::orEmpty(JsonArray::orEmpty($details['downloads'] ?? null)['server'] ?? null);
            $downloadUrl = ScalarValue::text($server['url'] ?? null);
            if ($downloadUrl === '') {
                continue;
            }
            yield ['version' => $id, 'url' => $downloadUrl, 'java' => ScalarValue::text(JsonArray::orEmpty($details['javaVersion'] ?? null)['majorVersion'] ?? (new MinecraftJavaVersionResolver())->resolve($id)), 'released' => $this->optionalText($details['releaseTime'] ?? null), 'metadata' => ['sha1' => $server['sha1'] ?? null, 'release_type' => $version['type'] ?? 'release']];
        }
    }

    /** @return \Generator<int, CatalogEntry> */
    private function paper(): iterable
    {
        $base = 'https://fill.papermc.io/v3/projects/paper';
        $project = $this->json($base);
        foreach ($this->paperVersions($project['versions'] ?? null) as $version) {
            $builds = $this->json($base.'/versions/'.rawurlencode($version).'/builds');
            foreach ($this->rows($builds['builds'] ?? $builds) as $build) {
                $download = JsonArray::orEmpty(JsonArray::orEmpty($build['downloads'] ?? null)['server:default'] ?? null);
                $downloadUrl = ScalarValue::text($download['url'] ?? null);
                $buildId = ScalarValue::text($build['id'] ?? null);
                if ($downloadUrl === '' || $buildId === '' || $buildId === '0') {
                    continue;
                }
                yield ['version' => $version, 'build' => $buildId, 'url' => $downloadUrl, 'sha256' => $this->optionalText(JsonArray::orEmpty($download['checksums'] ?? null)['sha256'] ?? null), 'java' => (new MinecraftJavaVersionResolver())->resolve($version), 'released' => $this->optionalText($build['time'] ?? null), 'metadata' => ['release_type' => 'stable' === strtolower(ScalarValue::text($build['channel'] ?? 'stable')) && preg_match('/^\d+\.\d+(\.\d+)?$/', $version) ? 'release' : 'snapshot']];
            }
        }
    }

    /** @return \Generator<int, CatalogEntry> */
    private function spigot(): iterable
    {
        $html = $this->http->request('GET', 'https://hub.spigotmc.org/versions/', ['timeout' => 20, 'max_duration' => 45])->getContent();
        preg_match_all('/href="([0-9][A-Za-z0-9.\-]*?)\.json"/', $html, $matches);
        if ([] === $matches[1]) {
            throw new \RuntimeException('Spigot returned no version metadata.');
        }
        $tool = $this->buildTools();
        $number = $tool['number'];
        $url = $tool['url'];
        $sha = $tool['sha256'];
        foreach (array_unique($matches[1]) as $version) {
            $info = $this->json('https://hub.spigotmc.org/versions/'.rawurlencode($version).'.json', 3600);
            $java = (new MinecraftJavaVersionResolver())->resolve($version);
            $minimum = ScalarValue::integer(JsonArray::orEmpty($info['javaVersions'] ?? null)[0] ?? null, 0);
            if ($minimum > 44) {
                $java = (string) ($minimum - 44);
            }
            yield ['version' => $version, 'url' => $url, 'sha256' => $sha, 'java' => $java, 'metadata' => ['strategy' => 'buildtools', 'build_java' => $java, 'release_type' => preg_match('/^\d+\.\d+(\.\d+)?$/', $version) ? 'release' : 'snapshot', 'buildtools_build' => $number, 'source_revision' => hash('sha256', json_encode($info['refs'] ?? [], JSON_THROW_ON_ERROR))]];
        }
    }

    /** @return \Generator<int, CatalogEntry> */
    private function bungee(): iterable
    {
        $base = 'https://hub.spigotmc.org/jenkins/job/BungeeCord';
        try {
            $job = $this->json($base.'/api/json?tree=builds[number,result,timestamp,artifacts[fileName,relativePath]]');
        } catch (\Throwable) {
            $job = [];
        }
        $found = false;
        foreach ($this->rows($job['builds'] ?? null) as $build) {
            if (($build['result'] ?? '') !== 'SUCCESS') {
                continue;
            }
            foreach ($this->rows($build['artifacts'] ?? null) as $artifact) {
                if (($artifact['fileName'] ?? '') !== 'BungeeCord.jar') {
                    continue;
                }
                $id = ScalarValue::text($build['number'] ?? null);
                $path = ScalarValue::text($artifact['relativePath'] ?? null);
                if (preg_match('/^[1-9][0-9]*$/D', $id) !== 1 || $path === '' || str_starts_with($path, '/')
                    || str_contains($path, "\0") || str_contains($path, '\\') || in_array('..', explode('/', $path), true)) {
                    continue;
                }
                $found = true;
                yield ['version' => 'BungeeCord', 'build' => $id, 'url' => $base.'/'.$id.'/artifact/'.implode('/', array_map('rawurlencode', explode('/', $path))), 'java' => '17', 'released' => is_numeric($build['timestamp'] ?? null) ? '@'.(int) ((float) $build['timestamp'] / 1000.0) : null, 'metadata' => ['release_type' => 'release', 'strategy' => 'proxy']];
            }
        }
        if ($found) {
            return;
        }
        try {
            $release = $this->officialGithubRelease('BungeeCord', 'BungeeCord.jar');
        } catch (\Throwable $error) {
            throw new \RuntimeException('BungeeCord Jenkins metadata and official GitHub release are unavailable; no usable build was returned.', 0, $error);
        }
        yield ['version' => 'BungeeCord', 'build' => $release['number'], 'url' => $release['url'], 'sha256' => $release['sha256'],
            'java' => '17', 'released' => $release['released'], 'metadata' => ['release_type' => 'release', 'strategy' => 'proxy']];
    }

    /** @return array{number: int, url: string, sha256: string} */
    private function buildTools(): array
    {
        try {
            $tool = $this->json('https://hub.spigotmc.org/jenkins/job/BuildTools/lastSuccessfulBuild/api/json?tree=number');
            $number = ScalarValue::integer($tool['number'] ?? null, 0);
            if ($number < 1) {
                throw new \RuntimeException('BuildTools build is unavailable.');
            }
            $url = 'https://hub.spigotmc.org/jenkins/job/BuildTools/'.$number.'/artifact/target/BuildTools.jar';

            return ['number' => $number, 'url' => $url, 'sha256' => $this->artifactDigest($url, 'minecraft.buildtools.'.$number)];
        } catch (\Throwable) {
            // Official release publication mirrors BuildTools when Jenkins is unavailable.
        }
        try {
            $release = $this->officialGithubRelease('BuildTools', 'BuildTools.jar');

            $number = filter_var($release['number'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($number === false) {
                throw new \RuntimeException('Invalid official BuildTools build number.');
            }

            return ['number' => $number, 'url' => $release['url'],
                'sha256' => $release['sha256'] ?? $this->artifactDigest($release['url'], 'minecraft.buildtools.github.'.$release['number'])];
        } catch (\Throwable $error) {
            throw new \RuntimeException('BuildTools Jenkins metadata/artifact and official GitHub release are unavailable.', 0, $error);
        }
    }

    private function artifactDigest(string $url, string $cacheKey): string
    {
        return $this->cachedDigest($this->cache->get($cacheKey, function (ItemInterface $item) use ($url): string {
            $item->expiresAfter(86400 * 30);
            $response = $this->http->request('GET', $url, ['timeout' => 30, 'max_duration' => 90, 'buffer' => false]);
            $response->getHeaders();
            $hash = hash_init('sha256');
            foreach ($this->http->stream($response) as $chunk) {
                hash_update($hash, $chunk->getContent());
            }

            return hash_final($hash);
        }));
    }

    /** @return array{number: string, url: string, sha256: ?string, released: ?string} */
    private function officialGithubRelease(string $repository, string $assetName): array
    {
        if (!in_array([$repository, $assetName], [['BungeeCord', 'BungeeCord.jar'], ['BuildTools', 'BuildTools.jar']], true)) {
            throw new \InvalidArgumentException('Unsupported official SpigotMC release repository.');
        }
        $release = $this->json('https://api.github.com/repos/SpigotMC/'.$repository.'/releases/latest');
        $tag = ScalarValue::text($release['tag_name'] ?? null);
        if (preg_match('~^(?:build/)?b?([1-9][0-9]*)$~D', $tag, $match) !== 1
            || ($release['draft'] ?? false) !== false || ($release['prerelease'] ?? false) !== false) {
            throw new \RuntimeException('Invalid official SpigotMC release metadata.');
        }
        $base = 'https://github.com/SpigotMC/'.$repository.'/releases/download/';
        $allowedUrls = [$base.$tag.'/'.$assetName, $base.rawurlencode($tag).'/'.$assetName];
        foreach ($this->rows($release['assets'] ?? null) as $asset) {
            if (($asset['name'] ?? null) !== $assetName || ($asset['state'] ?? 'uploaded') !== 'uploaded') {
                continue;
            }
            $url = ScalarValue::text($asset['browser_download_url'] ?? null);
            if (!in_array($url, $allowedUrls, true)) {
                throw new \RuntimeException('Official SpigotMC release asset has an unexpected download URL.');
            }
            $digest = $asset['digest'] ?? null;
            $sha256 = null;
            if ($digest !== null) {
                if (!is_string($digest) || preg_match('/^sha256:([a-fA-F0-9]{64})$/D', $digest, $checksum) !== 1) {
                    throw new \RuntimeException('Invalid official SpigotMC release SHA256 digest.');
                }
                $sha256 = strtolower($checksum[1]);
            }

            return ['number' => $match[1], 'url' => $url, 'sha256' => $sha256, 'released' => $this->optionalText($release['published_at'] ?? null)];
        }
        throw new \RuntimeException('Official SpigotMC release has no usable '.$assetName.' asset.');
    }

    /** @return \Generator<int, CatalogEntry> */
    private function bedrock(): iterable
    {
        // Microsoft only publishes current downloadable builds; keep historical DB entries.
        $data = $this->json('https://net-secondary.web.minecraft-services.net/api/v1.0/download/links');
        $links = JsonArray::orEmpty(JsonArray::orEmpty($data['result'] ?? null)['links'] ?? null);
        $found = false;
        foreach ($this->rows($links) as $link) {
            $downloadType = ScalarValue::text($link['downloadType'] ?? null);
            if (!in_array($downloadType, ['serverBedrockLinux', 'serverBedrockPreviewLinux'], true)) {
                continue;
            }
            $url = ScalarValue::text($link['downloadUrl'] ?? null);
            if (!preg_match('/bedrock-server-([0-9.]+)\.zip/', $url, $match)) {
                continue;
            }
            $found = true;
            yield ['version' => $match[1], 'url' => $url, 'metadata' => ['release_type' => str_contains($downloadType, 'Preview') ? 'snapshot' : 'release', 'os' => 'linux']];
        }
        if (!$found) {
            throw new \RuntimeException('No downloadable Linux Bedrock release was returned.');
        }
    }
    private function optionalText(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function cachedDigest(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new \RuntimeException('Invalid cached BuildTools digest.');
        }

        return $value;
    }

    /** @return \Generator<int, string> */
    private function paperVersions(mixed $versions): \Generator
    {
        if (!is_array($versions)) {
            throw new \RuntimeException('Paper returned no versions.');
        }
        $seen = [];
        foreach (array_keys($versions) as $key) {
            $group = is_array($versions[$key]) ? $versions[$key] : [$versions[$key]];
            foreach (array_keys($group) as $versionKey) {
                $version = ScalarValue::text($group[$versionKey]);
                if ($version !== '' && !isset($seen[$version])) {
                    $seen[$version] = true;
                    yield $version;
                }
            }
        }
        if ($seen === []) {
            throw new \RuntimeException('Paper returned no versions.');
        }
    }

}
