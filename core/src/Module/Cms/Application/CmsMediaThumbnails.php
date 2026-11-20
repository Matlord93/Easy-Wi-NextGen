<?php

declare(strict_types=1);

namespace App\Module\Cms\Application;

use App\Infrastructure\Filesystem\ConfinedLocalPath;
use App\Module\Core\Domain\Entity\MediaAsset;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

/** Bounded, local-only renditions; originals and saved CMS paths are never changed. */
final readonly class CmsMediaThumbnails
{
    private const int MAX_BYTES = 16 * 1024 * 1024;
    private const int MAX_RESULT_BYTES = 4 * 1024 * 1024;
    private const array SIZES = ['list' => [144, 108], 'picker' => [400, 240], 'editor' => [800, 440]];
    private const string PLACEHOLDER = '<svg xmlns="http://www.w3.org/2000/svg" width="144" height="108" viewBox="0 0 144 108"><rect width="144" height="108" fill="#64748b"/><path d="M32 78l24-28 18 18 14-14 24 24M48 36h1" fill="none" stroke="#e2e8f0" stroke-width="6"/></svg>';

    public function __construct(
        private CmsMediaPicker $picker,
        #[Autowire('%kernel.cache_dir%/cms-media-thumbnails')] private string $cacheDirectory,
    ) {
    }

    /** @return array{data:string,mime:string}|null */
    public function image(MediaAsset $asset, string $variant): ?array
    {
        $size = self::SIZES[$variant] ?? null;
        $source = $this->picker->sourceFile($asset);
        if (null === $size || null === $source) {
            return null;
        }
        $placeholder = ['data' => self::PLACEHOLDER, 'mime' => 'image/svg+xml'];
        try {
            $length = $this->quiet(static fn () => filesize($source));
            if (false === $length || $length > self::MAX_BYTES || !$this->fitsMemory(0, $length, $size)) {
                return $placeholder;
            }
            $bytes = $this->quiet(static fn () => file_get_contents($source, false, null, 0, $length + 1));
            if (false === $bytes || '' === $bytes || strlen($bytes) > $length) {
                return $placeholder;
            }
            $webp = function_exists('imagewebp') && (imagetypes() & IMG_WEBP) !== 0;
            $mime = $webp ? 'image/webp' : 'image/png';
            $key = hash('sha256', 'cms-thumb-v2:'.$variant.':'.$mime.':'.$bytes);
            $cache = $this->cacheFile($asset, $variant);
            if (null !== $cache && is_file($cache)) {
                $cached = $this->quiet(static fn () => file_get_contents($cache, false, null, 0, self::MAX_RESULT_BYTES + 66));
                if (is_string($cached) && str_starts_with($cached, $key."\n") && strlen($cached) > 65 && strlen($cached) <= self::MAX_RESULT_BYTES + 65) {
                    return ['data' => substr($cached, 65), 'mime' => $mime];
                }
            }
            $markers = [];
            $info = $this->quiet(static function () use ($bytes, &$markers) {
                return getimagesizefromstring($bytes, $markers);
            });
            if (false === $info || $info[0] < 1 || $info[1] < 1 || max($info[0], $info[1]) > 16384 || $info[0] * $info[1] > 24_000_000 || !$this->fitsMemory($info[0] * $info[1], strlen($bytes), $size)) {
                return $placeholder;
            }
            $input = $this->quiet(static fn () => imagecreatefromstring($bytes));
            if (false === $input) {
                return $placeholder;
            }
            $orientation = $this->orientation(isset($markers['APP1']) && is_string($markers['APP1']) ? $markers['APP1'] : '');
            $swap = $orientation >= 5;
            $scale = (float) min(1.0, $size[0] / $info[$swap ? 1 : 0], $size[1] / $info[$swap ? 0 : 1]);
            $width = max(1, (int) round((float) $info[0] * $scale));
            $height = max(1, (int) round((float) $info[1] * $scale));
            $output = imagecreatetruecolor($width, $height);
            if (false === $output) {
                return $placeholder;
            }
            imagealphablending($output, false);
            imagesavealpha($output, true);
            $transparent = imagecolorallocatealpha($output, 0, 0, 0, 127);
            if (false === $transparent) {
                return $placeholder;
            }
            imagefill($output, 0, 0, $transparent);
            imagecopyresampled($output, $input, 0, 0, 0, 0, $width, $height, $info[0], $info[1]);
            unset($input);
            $angle = match ($orientation) {
                3 => 180, 5, 6, 7 => -90, 8 => 90, default => 0
            };
            if (0 !== $angle) {
                $rotated = imagerotate($output, $angle, $transparent);
                if (false === $rotated) {
                    return $placeholder;
                }
                $output = $rotated;
                imagesavealpha($output, true);
            }
            if (in_array($orientation, [2, 4, 5, 7], true)) {
                imageflip($output, in_array($orientation, [2, 5], true) ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL);
            }
            ob_start();
            try {
                $ok = $this->quiet(static fn () => $webp ? imagewebp($output, null, 82) : imagepng($output));
                $encoded = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            if (!$ok || false === $encoded || '' === $encoded || strlen($encoded) > self::MAX_RESULT_BYTES) {
                return $placeholder;
            }
            if (null !== $cache) {
                try {
                    $filesystem = new Filesystem();
                    $filesystem->mkdir(dirname($cache), 0700);
                    $filesystem->dumpFile($cache, $key."\n".$encoded);
                } catch (IOExceptionInterface) {
                    // A disabled/unwritable cache must not prevent media selection.
                }
            }

            return ['data' => $encoded, 'mime' => $mime];
        } catch (\ErrorException|\ValueError) {
            return $placeholder;
        }
    }

    public function invalidate(MediaAsset $asset): void
    {
        foreach (array_keys(self::SIZES) as $variant) {
            $file = $this->cacheFile($asset, $variant);
            if (null !== $file) {
                try {
                    (new Filesystem())->remove($file);
                } catch (IOExceptionInterface) {
                    // Cache expiry/clear will reclaim any unavailable file later.
                }
            }
        }
    }

    private function cacheFile(MediaAsset $asset, string $variant): ?string
    {
        $id = $asset->getId();

        return null === $id ? null : ConfinedLocalPath::resolve($this->cacheDirectory, $id.'/'.$variant.'.bin');
    }

    /** Read only the bounded, inline IFD0 orientation; never follow EXIF offsets to external data. */
    private function orientation(string $app1): int
    {
        if (!str_starts_with($app1, "Exif\0\0")) {
            return 1;
        }
        $tiff = substr($app1, 6);
        $little = str_starts_with($tiff, "II\x2a\0");
        if (strlen($tiff) < 8 || (!$little && !str_starts_with($tiff, "MM\0\x2a"))) {
            return 1;
        }
        $offset = $this->unsigned($tiff, 4, 4, $little);
        $count = $this->unsigned($tiff, $offset, 2, $little);
        if ($offset < 8 || $count > 256) {
            return 1;
        }
        for ($index = 0; $index < $count; ++$index) {
            $entry = $offset + 2 + $index * 12;
            if ($entry + 12 > strlen($tiff)) {
                break;
            }
            if (274 === $this->unsigned($tiff, $entry, 2, $little) && 3 === $this->unsigned($tiff, $entry + 2, 2, $little) && 1 === $this->unsigned($tiff, $entry + 4, 4, $little)) {
                $value = $this->unsigned($tiff, $entry + 8, 2, $little);

                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }

        return 1;
    }

    private function unsigned(string $bytes, int $offset, int $length, bool $little): int
    {
        if ($offset < 0 || $offset + $length > strlen($bytes)) {
            return 0;
        }
        $value = 0;
        for ($index = 0; $index < $length; ++$index) {
            $value = ($value << 8) | ord($bytes[$offset + ($little ? $length - 1 - $index : $index)]);
        }

        return $value;
    }

    /** @param array{int,int} $size */
    private function fitsMemory(int $pixels, int $bytes, array $size): bool
    {
        $setting = ini_get('memory_limit');
        $limit = ini_parse_quantity(false === $setting || '' === $setting ? '128M' : $setting);

        return $limit < 0 || memory_get_usage(true) + $pixels * 16 + $bytes * 2 + $size[0] * $size[1] * 8 + 24 * 1024 * 1024 < $limit;
    }

    /**
     * @template T
     * @param callable():T $operation
     * @return T
     */
    private function quiet(callable $operation): mixed
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        }, E_WARNING | E_NOTICE);
        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
