<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Fetches metadata only; server binaries are downloaded on the node. */
final class MinecraftUpstreamCatalog
{
    public function __construct(private readonly HttpClientInterface $http, private readonly CacheInterface $cache)
    {
    }

    public function entries(string $channel): iterable
    {
        yield from match ($channel) {
            'vanilla' => $this->vanilla(),
            'paper' => $this->paper(),
            'spigot' => $this->spigot(),
            'bungeecord' => $this->bungee(),
            'bedrock' => $this->bedrock(),
            default => throw new \InvalidArgumentException('Unsupported Minecraft channel.'),
        };
    }

    private function json(string $url, int $ttl = 3600): array
    {
        return $this->cache->get('minecraft.upstream.'.hash('sha256', $url), function (ItemInterface $item) use ($url, $ttl): array {
            $item->expiresAfter($ttl);

            return $this->http->request('GET', $url, ['headers' => ['User-Agent' => 'EasyWi-MinecraftCatalog/1.0 (https://github.com/Matlord93/webinterface)'], 'timeout' => 20, 'max_duration' => 45])->toArray();
        });
    }

    private function vanilla(): iterable
    {
        $manifest = $this->json('https://piston-meta.mojang.com/mc/game/version_manifest_v2.json');
        if (!isset($manifest['versions']) || !is_array($manifest['versions'])) {
            throw new \RuntimeException('Invalid Mojang version manifest.');
        }
        foreach ($manifest['versions'] as $version) {
            $id = (string) ($version['id'] ?? '');
            $url = (string) ($version['url'] ?? '');
            if ('' === $id || !str_starts_with($url, 'https://piston-meta.mojang.com/')) {
                continue;
            }
            $details = $this->json($url, 86400 * 30);
            $server = $details['downloads']['server'] ?? null;
            if (!is_array($server) || empty($server['url'])) {
                continue;
            }
            yield ['version' => $id, 'url' => $server['url'], 'java' => (string) ($details['javaVersion']['majorVersion'] ?? (new MinecraftJavaVersionResolver())->resolve($id)), 'released' => $details['releaseTime'] ?? null, 'metadata' => ['sha1' => $server['sha1'] ?? null, 'release_type' => $version['type'] ?? 'release']];
        }
    }

    private function paper(): iterable
    {
        $base = 'https://fill.papermc.io/v3/projects/paper';
        $project = $this->json($base);
        $versions = $project['versions'] ?? [];
        // v3 groups versions by Minecraft release family.
        $flat = [];
        foreach ($versions as $value) {
            foreach (is_array($value) ? $value : [$value] as $version) {
                $flat[] = $version;
            }
        }
        if ([] === $flat) {
            throw new \RuntimeException('Paper returned no versions.');
        }
        foreach (array_unique($flat) as $version) {
            $builds = $this->json($base.'/versions/'.rawurlencode((string) $version).'/builds');
            foreach ($builds['builds'] ?? $builds as $build) {
                if (!is_array($build)) {
                    continue;
                }
                $download = $build['downloads']['server:default'] ?? null;
                if (!is_array($download) || empty($download['url']) || empty($build['id'])) {
                    continue;
                }
                yield ['version' => (string) $version, 'build' => (string) $build['id'], 'url' => $download['url'], 'sha256' => $download['checksums']['sha256'] ?? null, 'java' => (new MinecraftJavaVersionResolver())->resolve((string) $version), 'released' => $build['time'] ?? null, 'metadata' => ['release_type' => 'stable' === strtolower((string) ($build['channel'] ?? 'stable')) && preg_match('/^\d+\.\d+(\.\d+)?$/', (string) $version) ? 'release' : 'snapshot']];
            }
        }
    }

    private function spigot(): iterable
    {
        $html = $this->http->request('GET', 'https://hub.spigotmc.org/versions/', ['timeout' => 20, 'max_duration' => 45])->getContent();
        preg_match_all('/href="([0-9][A-Za-z0-9.\-]*?)\.json"/', $html, $matches);
        if ([] === $matches[1]) {
            throw new \RuntimeException('Spigot returned no version metadata.');
        }
        $tool = $this->json('https://hub.spigotmc.org/jenkins/job/BuildTools/lastSuccessfulBuild/api/json?tree=number');
        $number = (int) ($tool['number'] ?? 0);
        if ($number < 1) {
            throw new \RuntimeException('BuildTools build is unavailable.');
        }
        $url = 'https://hub.spigotmc.org/jenkins/job/BuildTools/'.$number.'/artifact/target/BuildTools.jar';
        // Pin the official HTTPS artifact and pass its digest to the node.
        $sha = $this->cache->get('minecraft.buildtools.'.$number, function (ItemInterface $item) use ($url): string {
            $item->expiresAfter(86400 * 30);

            return hash('sha256', $this->http->request('GET', $url, ['timeout' => 30, 'max_duration' => 90])->getContent());
        });
        foreach (array_unique($matches[1]) as $version) {
            $info = $this->json('https://hub.spigotmc.org/versions/'.rawurlencode($version).'.json', 3600);
            $java = (new MinecraftJavaVersionResolver())->resolve($version);
            $minimum = (int) ($info['javaVersions'][0] ?? 0);
            if ($minimum > 44) {
                $java = (string) ($minimum - 44);
            }
            yield ['version' => $version, 'url' => $url, 'sha256' => $sha, 'java' => $java, 'metadata' => ['strategy' => 'buildtools', 'build_java' => $java, 'release_type' => preg_match('/^\d+\.\d+(\.\d+)?$/', $version) ? 'release' : 'snapshot', 'buildtools_build' => $number, 'source_revision' => hash('sha256', json_encode($info['refs'] ?? [], JSON_THROW_ON_ERROR))]];
        }
    }

    private function bungee(): iterable
    {
        $base = 'https://hub.spigotmc.org/jenkins/job/BungeeCord';
        $job = $this->json($base.'/api/json?tree=builds[number,result,timestamp,artifacts[fileName,relativePath]]');
        if (!is_array($job['builds'] ?? null)) {
            throw new \RuntimeException('Invalid BungeeCord build list.');
        }
        foreach ($job['builds'] as $build) {
            if (($build['result'] ?? '') !== 'SUCCESS') {
                continue;
            }
            foreach ($build['artifacts'] ?? [] as $artifact) {
                if (($artifact['fileName'] ?? '') !== 'BungeeCord.jar') {
                    continue;
                }
                $id = (string) $build['number'];
                yield ['version' => 'BungeeCord', 'build' => $id, 'url' => $base.'/'.$id.'/artifact/'.implode('/', array_map('rawurlencode', explode('/', $artifact['relativePath']))), 'java' => '17', 'released' => isset($build['timestamp']) ? '@'.(int) ($build['timestamp'] / 1000) : null, 'metadata' => ['release_type' => 'release', 'strategy' => 'proxy']];
            }
        }
    }

    private function bedrock(): iterable
    {
        // Microsoft only publishes current downloadable builds; keep historical DB entries.
        $data = $this->json('https://net-secondary.web.minecraft-services.net/api/v1.0/download/links');
        $links = $data['result']['links'] ?? [];
        $found = false;
        foreach ($links as $link) {
            if (!in_array($link['downloadType'] ?? '', ['serverBedrockLinux', 'serverBedrockPreviewLinux'], true)) {
                continue;
            }
            $url = (string) ($link['downloadUrl'] ?? '');
            if (!preg_match('/bedrock-server-([0-9.]+)\.zip/', $url, $match)) {
                continue;
            }
            $found = true;
            yield ['version' => $match[1], 'url' => $url, 'metadata' => ['release_type' => str_contains($link['downloadType'], 'Preview') ? 'snapshot' : 'release', 'os' => 'linux']];
        }
        if (!$found) {
            throw new \RuntimeException('No downloadable Linux Bedrock release was returned.');
        }
    }
}
