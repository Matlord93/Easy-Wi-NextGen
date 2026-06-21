<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotTrack;
use App\Module\Musicbot\Domain\Enum\MusicbotTrackSourceType;
use App\Repository\MusicbotTrackRepository;
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
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MusicbotTrackRepository $trackRepository,
        private readonly MusicbotQuotaService $quotaService,
        private readonly string $projectDir,
    ) {
    }

    /** @return MusicbotTrack[] */
    public function libraryForCustomer(User $customer): array
    {
        return $this->trackRepository->findByCustomer($customer);
    }

    public function uploadTrack(User $customer, UploadedFile $file, ?string $title = null, ?string $artist = null): MusicbotTrack
    {
        if (!$file->isValid()) {
            throw new \InvalidArgumentException('Upload failed.');
        }
        if ($file->getSize() === null || $file->getSize() <= 0) {
            throw new \InvalidArgumentException('Track file size is invalid.');
        }
        $this->quotaService->assertCanUploadTrack($customer, (int) $file->getSize());

        $mimeType = (string) ($file->getMimeType() ?: $file->getClientMimeType());
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
        $relativePath = sprintf('musicbot/tracks/customer-%d/%s-%s.%s', $customer->getId() ?? 0, substr($sha256, 0, 16), $safeBaseName, $extension);
        $targetPath = $this->projectDir . '/var/' . $relativePath;
        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0770, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('Could not create customer track storage directory.');
        }
        $file->move($targetDir, basename($targetPath));

        $track = new MusicbotTrack(
            $customer,
            trim($title ?: $safeBaseName),
            MusicbotTrackSourceType::Upload,
            $mimeType,
            $sha256,
            0,
            ['original_name' => $file->getClientOriginalName(), 'size_bytes' => $file->getSize()]
        );
        $track->setArtist($artist !== null && trim($artist) !== '' ? trim($artist) : null);
        $track->setFilePath('var/' . $relativePath);

        $this->entityManager->persist($track);
        $this->entityManager->flush();

        return $track;
    }

    public function deleteTrack(User $customer, MusicbotTrack $track): void
    {
        $this->assertCustomerOwnsTrack($customer, $track);
        $filePath = $track->getFilePath();
        $this->entityManager->remove($track);
        $this->entityManager->flush();

        if ($filePath !== null && str_starts_with($filePath, 'var/musicbot/tracks/customer-')) {
            $absolutePath = $this->projectDir . '/' . $filePath;
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }
        }
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
