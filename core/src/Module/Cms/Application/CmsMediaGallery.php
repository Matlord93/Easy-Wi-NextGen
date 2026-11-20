<?php

declare(strict_types=1);

namespace App\Module\Cms\Application;

use App\Infrastructure\Filesystem\ConfinedLocalPath;
use App\Module\Core\Domain\Entity\MediaAsset;
use App\Module\Core\Domain\Entity\Site;
use App\Repository\MediaAssetRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class CmsMediaGallery
{
    public function __construct(
        private MediaAssetRepository $media,
        private CmsMediaPicker $picker,
        #[Autowire('%kernel.project_dir%/public/uploads')] private string $uploadsDirectory,
    ) {
    }

    /** @return array{assets:list<array{id:int,original:?string,preview:?string,raster:bool,title:?string,alt:string}>,has_next:bool} */
    public function page(Site $site, string $search, int $page): array
    {
        $rows = $this->media->findGalleryPage($site, $search, $page);
        $assets = [];
        foreach (array_slice($rows, 0, 24) as $asset) {
            $id = $asset->getId();
            if (null === $id || !$this->isPublicImage($asset)) {
                continue;
            }
            $original = $this->originalUrl($asset->getPath());
            $local = $this->picker->preview($asset);
            $remote = null !== $original && !str_starts_with($original, '/');
            $assets[] = ['id' => $id, 'original' => $original, 'preview' => $local['path'] ?? ($remote ? $original : null), 'raster' => $local['raster'] ?? false, 'title' => $asset->getTitle(), 'alt' => $asset->getAlt() ?? $asset->getTitle() ?? ''];
        }

        return ['assets' => $assets, 'has_next' => count($rows) > 24];
    }

    public function isPublicImage(MediaAsset $asset): bool
    {
        return str_starts_with($asset->getMime() ?? '', 'image/');
    }

    private function originalUrl(string $path): ?string
    {
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $path)) {
            return null;
        }
        if (str_starts_with($path, '/uploads/')) {
            $relative = rawurldecode(substr($path, 9));
            if (strpbrk($path, '?#') !== false || !in_array(strtolower(pathinfo($relative, PATHINFO_EXTENSION)), ['png', 'apng', 'jpg', 'jpeg', 'jpe', 'gif', 'webp', 'avif', 'bmp', 'ico', 'svg'], true)) {
                return null;
            }

            return null !== ConfinedLocalPath::resolve($this->uploadsDirectory, $relative) ? $path : null;
        }
        $url = parse_url($path);
        if (false === filter_var($path, FILTER_VALIDATE_URL) || false === $url || !in_array(strtolower($url['scheme'] ?? ''), ['https', 'http'], true) || isset($url['user']) || isset($url['pass'])) {
            return null;
        }

        return $path;
    }
}
