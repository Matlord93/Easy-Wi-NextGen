<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotPlaylist;
use App\Module\Musicbot\Domain\Entity\MusicbotTrack;
use App\Module\Musicbot\Domain\Enum\MusicbotTrackSourceType;
use App\Repository\MusicbotPlaylistRepository;
use Doctrine\ORM\EntityManagerInterface;

final class MusicbotYoutubeService
{
    private const YTDLP_TIMEOUT_SECONDS = 45;
    private const MAX_SEARCH_RESULTS = 10;
    private const MAX_IMPORT_ITEMS = 100;

    public function __construct(
        private readonly MusicbotYoutubeResolverService $resolver,
        private readonly MusicbotTrackService $trackService,
        private readonly MusicbotQueueService $queueService,
        private readonly MusicbotPlaylistService $playlistService,
        private readonly MusicbotPlaybackCommandService $playbackCommandService,
        private readonly MusicbotQuotaService $quotaService,
        private readonly MusicbotSecretConfigService $secretConfigService,
        private readonly MusicbotPlaylistRepository $playlistRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $binary = 'yt-dlp',
    ) {}

    /** @return array<string, mixed> */
    public function diagnostics(MusicbotInstance $instance): array
    {
        return [
            'available' => $this->resolver->isAvailable(),
            'version' => $this->ytDlpVersion(),
            'update_hint' => 'Run Musicbot repair or update yt-dlp on the node if extraction fails.',
            'cookies_configured' => $this->cookiesForInstance($instance) !== '',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function search(User $customer, MusicbotInstance $instance, string $query, string $type = 'song', int $limit = self::MAX_SEARCH_RESULTS): array
    {
        $this->assertCustomerOwnsInstance($customer, $instance);
        $this->quotaService->assertYoutubeAllowed($customer);
        $query = trim($query);
        if ($query === '') { throw new \InvalidArgumentException('YouTube search query must not be empty.'); }
        $limit = max(1, min(self::MAX_SEARCH_RESULTS, $limit));
        $prefix = match ($type) {
            'artist' => 'artist ',
            'album' => 'album ',
            'playlist' => 'playlist ',
            default => '',
        };
        $rows = $this->runYtDlpJson($instance, ['ytsearch' . $limit . ':' . $prefix . $query], true);
        $results = [];
        foreach ($rows as $row) {
            $results[] = $this->normalizeEntry($row);
        }
        $this->rememberYoutubeHistory($instance, ['type' => 'search', 'query' => $query, 'result_count' => count($results)]);

        return $results;
    }

    /** @return array<string, mixed> */
    public function importUrl(User $customer, MusicbotInstance $instance, string $url, array $options = []): array
    {
        $this->assertCustomerOwnsInstance($customer, $instance);
        $this->quotaService->assertYoutubeAllowed($customer);
        $this->resolver->validateYoutubeUrl($url);
        $kind = $this->detectUrlType($url);
        $rows = $this->runYtDlpJson($instance, [$url], (bool) ($options['playlist'] ?? $kind !== 'video'));
        if ($rows === []) { throw new \RuntimeException('No YouTube entries were returned by yt-dlp.'); }
        $rows = array_slice($rows, 0, self::MAX_IMPORT_ITEMS);
        if (!empty($options['shuffle'])) { shuffle($rows); }

        $tracks = [];
        foreach ($rows as $row) {
            $track = $this->createTrackFromEntry($customer, $instance, $row, $url, $kind);
            $tracks[] = $track;
            if (!empty($options['queue'])) { $this->queueService->addTrackToQueue($customer, $instance, $track, $customer); }
        }
        $playlist = null;
        if (!empty($options['create_playlist'])) {
            $playlist = $this->playlistService->createPlaylist($customer, (string) ($options['playlist_name'] ?? 'YouTube Import'), $instance);
            foreach ($tracks as $track) { $this->playlistService->addTrack($customer, $playlist, $track); }
        } elseif (!empty($options['playlist_id'])) {
            $playlist = $this->playlistRepository->findOneForCustomer((int) $options['playlist_id'], $customer);
            if (!$playlist instanceof MusicbotPlaylist) { throw new \InvalidArgumentException('Target playlist not found.'); }
            foreach ($tracks as $track) { $this->playlistService->addTrack($customer, $playlist, $track); }
        }
        $this->rememberYoutubeHistory($instance, ['type' => 'import', 'url' => $url, 'source_kind' => $kind, 'tracks' => count($tracks)]);

        return ['source_type' => $kind, 'tracks_imported' => count($tracks), 'playlist_id' => $playlist?->getId(), 'track_ids' => array_map(fn (MusicbotTrack $track): ?int => $track->getId(), $tracks)];
    }

    /** @return array<string, mixed> */
    public function queueUrl(User $customer, MusicbotInstance $instance, string $url): array
    {
        $result = $this->importUrl($customer, $instance, $url, ['playlist' => false, 'queue' => true]);
        return $result + ['queued' => true];
    }

    /** @return array<string, mixed> */
    public function playUrl(User $customer, MusicbotInstance $instance, string $url): array
    {
        $this->queueService->clearQueue($customer, $instance);
        $result = $this->queueUrl($customer, $instance, $url);
        $job = $this->playbackCommandService->dispatchPlaybackAction($customer, $instance, 'play');
        return $result + ['playback_job_id' => $job->getId()];
    }

    /** @return array<int, mixed> */
    public function history(MusicbotInstance $instance): array
    {
        $config = $instance->getInstanceConfig();
        return is_array($config['youtube']['history'] ?? null) ? $config['youtube']['history'] : [];
    }

    public function saveCookies(User $customer, MusicbotInstance $instance, string $cookies): void
    {
        $this->assertCustomerOwnsInstance($customer, $instance);
        $config = $instance->getInstanceConfig();
        $youtube = is_array($config['youtube'] ?? null) ? $config['youtube'] : [];
        $secrets = is_array($youtube['secrets'] ?? null) ? $youtube['secrets'] : [];
        $youtube['secrets'] = $this->secretConfigService->mergeSecretUpdates($secrets, ['youtube_cookies' => $cookies]);
        $config['youtube'] = $youtube;
        $instance->setInstanceConfig($config);
        $this->entityManager->flush();
    }

    public function detectUrlType(string $url): string
    {
        $this->resolver->validateYoutubeUrl($url);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        if (str_contains($host, 'music.youtube.com') && isset($query['list'])) { return 'album'; }
        if (str_contains($path, '/shorts/')) { return 'short'; }
        if (str_contains($path, 'podcast')) { return 'podcast'; }
        if (isset($query['list']) && str_starts_with((string) $query['list'], 'RD')) { return 'mix'; }
        if (isset($query['list'])) { return 'playlist'; }
        if (str_contains($path, '/live/')) { return 'live'; }
        if (str_contains($path, '/channel/') || str_contains($path, '/@')) { return 'artist'; }
        return 'video';
    }

    /** @return array<int, array<string, mixed>> */
    private function runYtDlpJson(MusicbotInstance $instance, array $targets, bool $playlist): array
    {
        if (!$this->resolver->isAvailable()) { throw new \RuntimeException('yt-dlp fehlt. Bitte Musicbot-Repair ausführen oder yt-dlp installieren.'); }
        $args = [$this->binary, '--dump-json', '--no-warnings', '--ignore-errors'];
        if (!$playlist) { $args[] = '--no-playlist'; }
        $cookies = $this->cookiesForInstance($instance);
        $cookieFile = null;
        if ($cookies !== '') {
            $cookieFile = tempnam(sys_get_temp_dir(), 'easywi-ytdlp-cookies-');
            if ($cookieFile !== false) { file_put_contents($cookieFile, $cookies); $args[] = '--cookies'; $args[] = $cookieFile; }
        }
        foreach ($targets as $target) { $args[] = $target; }
        $result = $this->runProcess($args);
        if ($cookieFile !== null && is_file($cookieFile)) { @unlink($cookieFile); }
        if ($result['exit_code'] !== 0 && trim($result['stdout']) === '') { throw new \RuntimeException($this->humanizeYtDlpError($result['stderr'], $result['exit_code'])); }
        $rows = [];
        foreach (explode("\n", trim($result['stdout'])) as $line) {
            if (trim($line) === '') { continue; }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) { $rows[] = $decoded; }
        }
        return $rows;
    }

    /** @param array<int, string> $args @return array{exit_code:int, stdout:string, stderr:string} */
    private function runProcess(array $args): array
    {
        $process = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new \RuntimeException('yt-dlp konnte nicht gestartet werden.'); }
        fclose($pipes[0]);
        $stdout = ''; $stderr = ''; $deadline = time() + self::YTDLP_TIMEOUT_SECONDS;
        while (!feof($pipes[1]) || !feof($pipes[2])) {
            if (time() > $deadline) { proc_terminate($process); break; }
            $out = fread($pipes[1], 8192); if ($out !== false) { $stdout .= $out; }
            $err = fread($pipes[2], 4096); if ($err !== false) { $stderr .= $err; }
        }
        fclose($pipes[1]); fclose($pipes[2]);
        return ['exit_code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** @param array<string, mixed> $entry */
    private function createTrackFromEntry(User $customer, MusicbotInstance $instance, array $entry, string $sourceUrl, string $kind): MusicbotTrack
    {
        $webpageUrl = (string) ($entry['webpage_url'] ?? $entry['original_url'] ?? $sourceUrl);
        $title = (string) ($entry['title'] ?? 'YouTube');
        $artist = (string) ($entry['artist'] ?? $entry['uploader'] ?? $entry['channel'] ?? '');
        return $this->trackService->createYoutubeTrack($customer, $webpageUrl, $title, $artist !== '' ? $artist : null, (int) ($entry['duration'] ?? 0), [
            'youtube_id' => (string) ($entry['id'] ?? ''),
            'youtube_url' => $webpageUrl,
            'source_url' => $sourceUrl,
            'source_kind' => $kind,
            'thumbnail' => (string) ($entry['thumbnail'] ?? ''),
            'uploader' => (string) ($entry['uploader'] ?? ''),
            'channel' => (string) ($entry['channel'] ?? ''),
            'is_live' => (bool) ($entry['is_live'] ?? false),
            'live_status' => (string) ($entry['live_status'] ?? ''),
        ], $instance);
    }

    /** @param array<string, mixed> $entry @return array<string, mixed> */
    private function normalizeEntry(array $entry): array
    {
        return [
            'youtube_id' => (string) ($entry['id'] ?? ''),
            'title' => (string) ($entry['title'] ?? ''),
            'artist' => (string) ($entry['artist'] ?? $entry['uploader'] ?? $entry['channel'] ?? ''),
            'duration' => (int) ($entry['duration'] ?? 0),
            'thumbnail' => (string) ($entry['thumbnail'] ?? ''),
            'source_url' => (string) ($entry['webpage_url'] ?? $entry['url'] ?? ''),
            'source' => (string) ($entry['extractor_key'] ?? 'YouTube'),
            'source_type' => 'youtube',
        ];
    }

    private function ytDlpVersion(): ?string
    {
        try {
            $result = $this->runProcess([$this->binary, '--version']);
        } catch (\Throwable) {
            return null;
        }

        return $result['exit_code'] === 0 ? trim($result['stdout']) : null;
    }

    private function humanizeYtDlpError(string $stderr, int $exitCode): string
    {
        $lower = strtolower($stderr);
        return match (true) {
            str_contains($lower, 'not available') => 'Video nicht verfügbar.',
            str_contains($lower, 'geo') || str_contains($lower, 'country') => 'Video ist geoblockiert.',
            str_contains($lower, 'age') => 'Video ist altersbeschränkt.',
            str_contains($lower, 'private') => 'Video ist privat.',
            str_contains($lower, 'login') || str_contains($lower, 'sign in') => 'Login oder Cookies erforderlich.',
            default => sprintf('yt-dlp Fehler (%d): %s', $exitCode, trim($stderr) ?: 'Unbekannter Fehler'),
        };
    }

    private function cookiesForInstance(MusicbotInstance $instance): string
    {
        $config = $instance->getInstanceConfig();
        $secrets = $config['youtube']['secrets'] ?? [];
        if (!is_array($secrets)) { return ''; }
        return $this->secretConfigService->normalizeForRuntime($secrets)['youtube_cookies'] ?? '';
    }

    /** @param array<string, mixed> $entry */
    private function rememberYoutubeHistory(MusicbotInstance $instance, array $entry): void
    {
        $config = $instance->getInstanceConfig();
        $youtube = is_array($config['youtube'] ?? null) ? $config['youtube'] : [];
        $history = is_array($youtube['history'] ?? null) ? $youtube['history'] : [];
        array_unshift($history, $entry + ['at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)]);
        $youtube['history'] = array_slice($history, 0, 50);
        $config['youtube'] = $youtube;
        $instance->setInstanceConfig($config);
        $this->entityManager->flush();
    }

    private function assertCustomerOwnsInstance(User $customer, MusicbotInstance $instance): void
    {
        if ($instance->getCustomer()->getId() !== $customer->getId()) { throw new \RuntimeException('Musicbot instance does not belong to this customer.'); }
    }
}
