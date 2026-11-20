<?php

declare(strict_types=1);

namespace App\Module\Cms\Application;

use App\Module\Core\Domain\Entity\Site;
use App\Repository\SiteRepository;
use Symfony\Component\HttpFoundation\Request;

final readonly class CmsSiteResolver
{
    private const CACHE_KEY = '_easywi_cms_site';

    public function __construct(private SiteRepository $sites)
    {
    }

    public function resolve(Request $request): ?Site
    {
        if ($request->attributes->has(self::CACHE_KEY)) {
            return self::asSite($request->attributes->get(self::CACHE_KEY));
        }
        $site = $this->sites->findOneByHost($request->getHost());
        if (null === $site) {
            // Preserve single-site aliases; an unknown host must not select an
            // arbitrary website when more than one website is configured.
            $candidates = $this->sites->findBy([], ['id' => 'ASC'], 2);
            $site = 1 === count($candidates) ? $candidates[0] : null;
        }
        $request->attributes->set(self::CACHE_KEY, $site);

        return $site;
    }

    private static function asSite(mixed $value): ?Site
    {
        return $value instanceof Site ? $value : null;
    }
}
