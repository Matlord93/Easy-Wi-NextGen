<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotTrack;
use App\Module\Musicbot\Domain\Enum\MusicbotTrackSourceType;
use App\Repository\MusicbotTrackRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class MusicbotTrackService
{
    /** @deprecated Prefer MusicbotPlanLimitResolver::resolve()->maxUploadSizeMb for per-customer limits. */
    public const MAX_UPLOAD_BYTES = 104_857_600;
    private const MIME_TO_EXTENSION = [
        'audio/mpeg' => 'mp3',
        'audio/mp3' => 'mp3',
        'audio/ogg' => 'ogg',
        'application/ogg' => 'ogg',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'audio/wave' => 'wav',
        'audio/flac' => 'flac',
        'audio/x-flac' => 'flac',
        'audio/mp4' => 'm4a',
        'audio/x-m4a' => 'm4a',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MusicbotTrackRepositoryInterface $trackRepository,
        private readonly MusicbotQuotaServiceInterface $quotaService,
        private readonly MusicbotTrackPathResolver $pathResolver,
        private readonly string $projectDir,
        private readonly MusicbotWebradioUrlValidator $webradioValidator = new MusicbotWebradioUrlValidator(),
    ) {
    }

    /** @return MusicbotTrack[] */
    public function libraryForCustomer(User $customer): array
    {
        return $this->trackRepository->findByCustomer($customer);
    }

    public function uploadTrack(User $customer, UploadedFile $file, ?string $title = null, ?string $artist = null, ?MusicbotInstance $instance = null): MusicbotTrack
    {
        if (!$file->isValid()) {
            throw new \InvalidArgumentException('Upload failed.');
        }
        $fileSize = (int) $file->getSize();
        if ($fileSize <= 0) {
            throw new \InvalidArgumentException('Track file size is invalid.');
        }
        $this->quotaService->assertCanUploadTrack($customer, $fileSize);

        $serverMimeType = (string) $file->getMimeType();
        $clientMimeType = (string) $file->getClientMimeType();
        // Prefer server-detected type; fall back to client-claimed type when server detection is inconclusive.
        $mimeType = isset(self::MIME_TO_EXTENSION[$serverMimeType]) ? $serverMimeType : $clientMimeType;
        $extension = self::MIME_TO_EXTENSION[$mimeType] ?? null;
        if ($extension === null) {
            throw new \InvalidArgumentException('Unsupported track file type.');
        }

        $sourcePath = $file->getPathname();
        $sha256 = hash_file('sha256', $sourcePath);
        if (!is_string($sha256) || $sha256 === '') {
            throw new \RuntimeException('Could not calculate track checksum.');
        }

        $safeBaseName = $this->normalizeBaseName($title ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $relativePath = sprintf('customer-%d/%s-%s.%s', $customer->getId() ?? 0, substr($sha256, 0, 16), $safeBaseName, $extension);
        $targetPath = $instance instanceof MusicbotInstance
            ? $this->pathResolver->instanceTrackRoot($instance) . '/' . $relativePath
            : $this->projectDir . '/var/musicbot/tracks/' . $relativePath;
        $targetDir = dirname($targetPath);
        if (!$this->ensureWritableDirectory($targetDir)) {
            $message = sprintf('Could not create customer track storage directory at %s: %s', $targetDir, error_get_last()['message'] ?? 'directory is not writable');
            error_log($message);
            throw new \RuntimeException('Speicherverzeichnis nicht beschreibbar.');
        }

        try {
            $file->move($targetDir, basename($targetPath));
        } catch (\Throwable $exception) {
            error_log(sprintf('Could not store customer track upload at %s: %s', $targetPath, $exception->getMessage()));
            throw new \RuntimeException('Speicherverzeichnis nicht beschreibbar.', 0, $exception);
        }
        @chmod($targetPath, 0660);

        $track = new MusicbotTrack(
            $customer,
            trim($title ?: $safeBaseName),
            MusicbotTrackSourceType::Upload,
            $mimeType,
            $sha256,
            0,
            ['original_name' => $file->getClientOriginalName(), 'size_bytes' => $fileSize]
        );
        $track->setInstance($instance);
        $track->setArtist($artist !== null && trim($artist) !== '' ? trim($artist) : null);
        $track->setFilePath($targetPath);

        if (!is_file($targetPath)) {
            throw new \RuntimeException('Uploaded track file could not be verified in storage.');
        }

        $this->entityManager->persist($track);
        $this->entityManager->flush();

        return $track;
    }


    private function ensureWritableDirectory(string $directory): bool
    {
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            return false;
        }
        @chmod($directory, 0770);

        return is_writable($directory);
    }

    public function deleteTrack(User $customer, MusicbotTrack $track): void
    {
        $this->assertCustomerOwnsTrack($customer, $track);
        $filePath = $track->getFilePath();
        $this->entityManager->remove($track);
        $this->entityManager->flush();

        if ($filePath !== null) {
            $absolutePath = $track->getInstance() instanceof MusicbotInstance
                ? $this->pathResolver->resolveTrackFile($track, $track->getInstance())
                : (str_starts_with($filePath, 'var/musicbot/tracks/customer-') ? $this->projectDir . '/' . $filePath : null);
            if ($absolutePath !== null && is_file($absolutePath)) {
                @unlink($absolutePath);
            }
        }
    }

    public function addWebradioTrack(User $customer, string $title, string $streamUrl): MusicbotTrack
    {
        $title = trim($title);
        if ($title === '') {
            throw new \InvalidArgumentException('Webradio track title must not be empty.');
        }

        $this->webradioValidator->validate($streamUrl);

        // Count against track quota (webradio entries don't take storage but count as tracks).
        $this->quotaService->assertCanUploadTrack($customer, 0);

        $track = new MusicbotTrack(
            $customer,
            $title,
            MusicbotTrackSourceType::Webradio,
            'audio/mpeg',
            hash('sha256', 'webradio:' . $streamUrl),
            0,
            ['stream_url' => $streamUrl],
        );

        $this->entityManager->persist($track);
        $this->entityManager->flush();

        return $track;
    }

    public function addYoutubeTrack(User $customer, string $youtubeUrl, ?string $title = null): MusicbotTrack
    {
        $youtubeUrl = trim($youtubeUrl);
        if ($youtubeUrl === '') {
            throw new \InvalidArgumentException('YouTube URL must not be empty.');
        }

        // Validates scheme and host against allowed YouTube domains.
        $this->assertValidYoutubeUrl($youtubeUrl);

        // Count against track quota.
        $this->quotaService->assertCanUploadTrack($customer, 0);

        $resolvedTitle = trim((string) $title) !== '' ? trim((string) $title) : 'YouTube – ' . $this->extractYoutubeVideoId($youtubeUrl);

        $track = new MusicbotTrack(
            $customer,
            $resolvedTitle,
            MusicbotTrackSourceType::Youtube,
            'audio/mpeg',
            hash('sha256', 'youtube:' . $youtubeUrl),
            0,
            ['youtube_url' => $youtubeUrl],
        );

        $this->entityManager->persist($track);
        $this->entityManager->flush();

        return $track;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function createYoutubeTrack(User $customer, string $youtubeUrl, string $title, ?string $artist = null, int $durationSeconds = 0, array $metadata = [], ?MusicbotInstance $instance = null): MusicbotTrack
    {
        $youtubeUrl = trim($youtubeUrl);
        if ($youtubeUrl === '') {
            throw new \InvalidArgumentException('YouTube URL must not be empty.');
        }
        $this->assertValidYoutubeUrl($youtubeUrl);
        if ($instance instanceof MusicbotInstance && $instance->getCustomer()->getId() !== $customer->getId()) {
            throw new \RuntimeException('Musicbot instance does not belong to the current customer.');
        }
        $this->quotaService->assertCanUploadTrack($customer, 0);

        $track = new MusicbotTrack(
            $customer,
            trim($title) !== '' ? trim($title) : 'YouTube – ' . $this->extractYoutubeVideoId($youtubeUrl),
            MusicbotTrackSourceType::Youtube,
            'audio/mpeg',
            hash('sha256', 'youtube:' . $youtubeUrl),
            $durationSeconds,
            array_merge($metadata, ['youtube_url' => $youtubeUrl]),
        );
        $track->setArtist($artist !== null && trim($artist) !== '' ? trim($artist) : null);
        $track->setInstance($instance);

        $this->entityManager->persist($track);
        $this->entityManager->flush();

        return $track;
    }

    private function assertValidYoutubeUrl(string $url): void
    {
        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            throw new \InvalidArgumentException('YouTube URL is not a valid URL.');
        }
        $scheme = strtolower($parsed['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('YouTube URL must use http or https.');
        }
        $host = strtolower(trim($parsed['host'], '[]'));
        $allowed = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'music.youtube.com'];
        if (!in_array($host, $allowed, true)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a recognized YouTube domain.', $host));
        }
    }

    private function extractYoutubeVideoId(string $url): string
    {
        // youtu.be/<id>
        if (str_contains($url, 'youtu.be/')) {
            $path = parse_url($url, PHP_URL_PATH) ?? '';
            return ltrim($path, '/');
        }
        // youtube.com/watch?v=<id>
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

        return isset($query['v']) && is_string($query['v']) ? $query['v'] : 'unknown';
    }

    public function findTrackForCustomer(int $trackId, User $customer): ?MusicbotTrack
    {
        return $this->trackRepository->findOneForCustomer($trackId, $customer);
    }

    private function assertCustomerOwnsTrack(User $customer, MusicbotTrack $track): void
    {
        if ($track->getCustomer()->getId() !== $customer->getId()) {
            throw new \RuntimeException('Track does not belong to the current customer.');
        }
    }

    private function normalizeBaseName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = (string) preg_replace('/[^a-z0-9._-]+/', '-', $name);
        $name = trim($name, '.-_');

        return substr($name !== '' ? $name : 'track', 0, 80);
    }
}
