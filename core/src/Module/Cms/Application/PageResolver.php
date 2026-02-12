<?php

declare(strict_types=1);

namespace App\Module\Cms\Application;

use App\Module\Core\Domain\Entity\CmsPage;
use App\Module\Core\Domain\Entity\Site;
use App\Repository\CmsPageRepository;

final class PageResolver
{
    private const RESERVED_SLUGS = [
        'admin', 'api', 'agent', 'assets', 'build', 'bundles', 'css', 'js', 'images',
        'system', 'install', 'login', 'logout', 'register', '2fa', '2fa_check', 'dashboard',
        'profile', 'reseller', 'docs', 'downloads', 'status', 'servers', 'notifications',
        'files', 'tickets', 'instances', 'databases', 'changelog', 'shop', 'blog', 'events', 'teams', 'media', 'forum', '_profiler', '_wdt',
    ];

    public function __construct(private readonly CmsPageRepository $pageRepository)
    {
    }

    public function resolvePublishedPage(Site $site, string $slug): ?CmsPage
    {
        return $this->pageRepository->findOneBy([
            'site' => $site,
            'slug' => $slug,
            'isPublished' => true,
        ]);
    }


    public function resolveHomePage(Site $site, string $preferredSlug = 'startseite'): ?CmsPage
    {
        $preferred = $this->resolvePublishedPage($site, $preferredSlug);
        if ($preferred instanceof CmsPage) {
            return $preferred;
        }

        return $this->pageRepository->findOneBy([
            'site' => $site,
            'isPublished' => true,
        ], ['id' => 'ASC']);
    }

    public function isReservedSlug(string $slug): bool
    {
        return in_array($slug, self::RESERVED_SLUGS, true);
    }

    /**
     * @return list<string>
     */
    public function reservedSlugs(): array
    {
        return self::RESERVED_SLUGS;
    }
}
