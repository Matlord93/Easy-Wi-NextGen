<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Infrastructure\Filesystem\ConfinedLocalPath;
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
        $root = ConfinedLocalPath::resolve($instance->getInstallPath(), 'data/tracks');
        if (null === $root) {
            throw new \RuntimeException('Invalid instance track storage root.');
        }

        return $root;
    }

    public function customerTrackDirectory(MusicbotInstance $instance, int $customerId): string
    {
        return $this->instanceTrackRoot($instance) . '/customer-' . $customerId;
    }

    public function resolveTrackFile(MusicbotTrack $track, MusicbotInstance $instance, bool $mustExist = true): ?string
    {
        if ($track->getSourceType() !== MusicbotTrackSourceType::Upload || $track->getCustomer()->getId() !== $instance->getCustomer()->getId()) {
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
        if (str_starts_with($path, '/') || 1 === preg_match('#^[A-Za-z]:/#D', $path)) {
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
            $this->checkedCustomerRoot($this->globalTrackRoot(), $instance->getCustomer()->getId() ?? 0),
        ];
    }

    private function globalTrackRoot(): string
    {
        $root = ConfinedLocalPath::resolve($this->projectDir, 'var/musicbot/tracks');
        if (null === $root) {
            throw new \RuntimeException('Invalid global track storage root.');
        }

        return $root;
    }

    private function checkedCustomerRoot(string $root, int $id): string
    {
        $expected = rtrim($root, '/').'/customer-'.$id;
        $actual = ConfinedLocalPath::resolve($root, 'customer-'.$id);
        if ($id < 1 || null === $actual || ('\\' === DIRECTORY_SEPARATOR ? strtolower($actual) !== strtolower($expected) : $actual !== $expected)) {
            throw new \RuntimeException('Invalid customer track storage root.');
        }

        return $actual;
    }

    /**
     * Only canonical, root-confined local paths may be used as file sinks.
     */
    private function canonicalizeAllowedPath(string $path, MusicbotInstance $instance, bool $mustExist): ?string
    {
        return $this->withinRoots($path, $this->allowedRoots($instance), $mustExist);
    }

    /**
     * @param list<string> $roots
     */
    private function withinRoots(string $path, array $roots, bool $mustExist): ?string
    {
        $path = str_replace('\\', '/', $path);
        foreach ($roots as $root) {
            $root = rtrim(str_replace('\\', '/', $root), '/');
            if (str_starts_with('\\' === DIRECTORY_SEPARATOR ? strtolower($path) : $path, ('\\' === DIRECTORY_SEPARATOR ? strtolower($root) : $root).'/')) {
                $resolved = ConfinedLocalPath::resolve($root, substr($path, strlen($root) + 1), $mustExist);
                if (null !== $resolved) {
                    return $resolved;
                }
            }
        }

        return null;
    }

    public function uploadPath(?MusicbotInstance $instance, int $customerId, string $filename): string
    {
        if ($customerId < 1 || !preg_match('/^[a-f0-9]{16}-[a-z0-9._-]{1,80}\.(mp3|ogg|wav|flac|m4a)$/D', $filename)) {
            throw new \InvalidArgumentException('Invalid track storage filename.');
        }
        $root = $instance ? $this->instanceTrackRoot($instance) : $this->globalTrackRoot();
        $path = ConfinedLocalPath::resolve($this->checkedCustomerRoot($root, $customerId), $filename);
        if (null === $path) {
            throw new \RuntimeException('Invalid track storage path.');
        }

        return $path;
    }

    public function customerTrackFile(MusicbotTrack $track): ?string
    {
        $path = $track->getFilePath();
        if (null === $path) {
            return null;
        }
        $customerId = $track->getCustomer()->getId();
        if (!$customerId) {
            return null;
        }
        $instance = $track->getInstance();
        if ($instance && $instance->getCustomer()->getId() !== $customerId) {
            return null;
        }
        $roots = [$this->checkedCustomerRoot($this->globalTrackRoot(), $customerId)];
        if ($instance) {
            return $this->resolveTrackFile($track, $instance);
        }
        if (!str_starts_with($path, '/') && 1 !== preg_match('#^[A-Za-z]:/#D', $path)) {
            $path = rtrim($this->projectDir, '/\\').'/'.$path;
        }

        return $this->withinRoots($path, $roots, true);
    }
}
