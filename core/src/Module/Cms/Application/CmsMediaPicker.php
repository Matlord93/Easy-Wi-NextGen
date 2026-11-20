<?php

declare(strict_types=1);

namespace App\Module\Cms\Application;

use App\Infrastructure\Filesystem\ConfinedLocalPath;
use App\Module\Core\Domain\Entity\MediaAsset;
use App\Module\Core\Domain\Entity\Site;
use App\Repository\MediaAssetRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class CmsMediaPicker
{
    public function __construct(private MediaAssetRepository $media, #[Autowire('%kernel.project_dir%/public/uploads')] private string $uploadsDirectory)
    {
    }

    /** @return array{images:list<array{id:int,path:string,title:string,alt:string,raster:bool}>,has_next:bool} */
    public function page(Site $site, string $search, int $page): array
    {
        $rows = $this->media->findImagePage($site, $search, $page);
        $images = [];
        foreach (array_slice($rows, 0, 24) as $asset) {
            $image = $this->preview($asset);
            if (null !== $image) {
                $images[] = $image;
            }
        }

        return ['images' => $images, 'has_next' => count($rows) > 24];
    }

    /** @return array{id:int,path:string,title:string,alt:string,raster:bool}|null */
    public function preview(MediaAsset $asset): ?array
    {
        $file = $this->sourceFile($asset);
        $id = $asset->getId();
        if (null === $file || null === $id) {
            return null;
        }
        $title = $asset->getTitle();

        return ['id' => $id, 'path' => $asset->getPath(), 'title' => null !== $title && '' !== $title ? $title : basename(rawurldecode($asset->getPath())), 'alt' => $asset->getAlt() ?? $title ?? '', 'raster' => !in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['svg', 'ico'], true)];
    }

    public function sourceFile(MediaAsset $asset): ?string
    {
        $id = $asset->getId();
        $path = $asset->getPath();
        $mime = $asset->getMime();
        if (null === $id || (null !== $mime && !str_starts_with(strtolower($mime), 'image/')) || !str_starts_with($path, '/uploads/') || preg_match('/[\x00-\x1f\x7f\\\\?#]/', $path)) {
            return null;
        }
        $relative = rawurldecode(substr($path, 9));
        if (!in_array(strtolower(pathinfo($relative, PATHINFO_EXTENSION)), ['png', 'apng', 'jpg', 'jpeg', 'jpe', 'webp', 'gif', 'avif', 'svg', 'bmp', 'ico'], true)) {
            return null;
        }
        $file = ConfinedLocalPath::resolve($this->uploadsDirectory, $relative);
        if (null === $file || !is_file($file)) {
            return null;
        }
        return $file;
    }
}
