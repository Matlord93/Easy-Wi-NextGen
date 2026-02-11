<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Controller\Public;

use App\Module\Core\Application\SiteResolver;
use App\Repository\CmsPageRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicSeoController
{
    public function __construct(
        private readonly SiteResolver $siteResolver,
        private readonly CmsPageRepository $pageRepository,
    ) {
    }

    #[Route(path: '/robots.txt', name: 'public_robots', methods: ['GET'])]
    public function robots(): Response
    {
        return new Response("User-agent: *\nAllow: /\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    #[Route(path: '/sitemap.xml', name: 'public_sitemap', methods: ['GET'])]
    public function sitemap(Request $request): Response
    {
        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"/>', 200, ['Content-Type' => 'application/xml']);
        }

        $pages = $this->pageRepository->findBy(['site' => $site, 'isPublished' => true], ['slug' => 'ASC']);
        $host = $request->getSchemeAndHttpHost();

        $urls = [];
        foreach ($pages as $page) {
            $slug = $page->getSlug();
            $loc = $slug === 'startseite' ? $host . '/' : $host . '/' . $slug;
            $urls[] = sprintf('<url><loc>%s</loc></url>', htmlspecialchars($loc, ENT_XML1));
        }

        $xml = sprintf('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">%s</urlset>', implode('', $urls));

        return new Response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
