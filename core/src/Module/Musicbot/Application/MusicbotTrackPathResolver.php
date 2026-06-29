<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotTrack;
use App\Module\Musicbot\Domain\Enum\MusicbotTrackSourceType;

final class MusicbotTrackPathResolver
{
    public const MISSING_FILE_MESSAGE = 'Die Audiodatei wurde nicht gefunden. Bitte erneut hochladen.';

    public function __construct(private readonly string $projectDir)
    {
    }

    public function instanceTrackRoot(MusicbotInstance $instance): string
    {
        return rtrim($instance->getInstallPath(), '/\\') . '/data/tracks';
    }

    public function customerTrackDirectory(MusicbotInstance $instance, int $customerId): string
    {
        return $this->instanceTrackRoot($instance) . '/customer-' . $customerId;
    }

    public function resolveTrackFile(MusicbotTrack $track, MusicbotInstance $instance, bool $mustExist = true): ?string
    {
        if ($track->getSourceType() !== MusicbotTrackSourceType::Upload) {
            return null;
        }

        $filePath = $track->getFilePath();
        if ($filePath === null || trim($filePath) === '') {
            return null;
        }

        return $this->resolveLocalPath($filePath, $instance, $mustExist);
    }

    public function resolveLocalPath(string $path, MusicbotInstance $instance, bool $mustExist = true): ?string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '' || preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1 || str_contains($path, "\0")) {
            return null;
        }

        $candidates = [];
        if (str_starts_with($path, '/')) {
            $candidates[] = $path;
        } else {
            $relative = ltrim($path, '/');
            foreach (['tracks/', 'musicbot/tracks/', 'var/musicbot/tracks/'] as $prefix) {
                if (str_starts_with($relative, $prefix)) {
                    $candidates[] = $this->instanceTrackRoot($instance) . '/' . substr($relative, strlen($prefix));
                }
            }
            $candidates[] = $this->instanceTrackRoot($instance) . '/' . $relative;
            $candidates[] = rtrim($this->projectDir, '/\\') . '/' . $relative;
        }

        foreach (array_unique($candidates) as $candidate) {
            $resolved = $this->canonicalizeAllowedPath($candidate, $instance, $mustExist);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function allowedRoots(MusicbotInstance $instance): array
    {
        return [
            $this->instanceTrackRoot($instance),
            rtrim($this->projectDir, '/\\') . '/var/musicbot/tracks',
        ];
    }

    private function canonicalizeAllowedPath(string $path, MusicbotInstance $instance, bool $mustExist): ?string
    {
        $existing = realpath($path);
        if ($existing === false) {
            if ($mustExist) {
                return null;
            }
            $parent = realpath(dirname($path));
            if ($parent === false) {
                return null;
            }
            $existing = rtrim($parent, '/\\') . '/' . basename($path);
        }

        $existing = str_replace('\\', '/', $existing);
        foreach ($this->allowedRoots($instance) as $root) {
            $rootReal = realpath($root) ?: $root;
            $rootReal = rtrim(str_replace('\\', '/', $rootReal), '/');
            if ($existing === $rootReal || str_starts_with($existing, $rootReal . '/')) {
                return $existing;
            }
        }

        return null;
    }
}
