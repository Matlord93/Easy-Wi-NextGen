<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Entity\DownloadItem;
use App\Repository\DownloadItemRepository;
use App\Service\SiteResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Twig\Environment;

final class PublicDownloadController
{
    public function __construct(
        private readonly DownloadItemRepository $downloadRepository,
        private readonly SiteResolver $siteResolver,
        #[Autowire(service: 'limiter.public_downloads')]
        private readonly RateLimiterFactory $downloadsLimiter,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '/downloads', name: 'public_downloads', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $limiter = $this->downloadsLimiter->create($request->getClientIp() ?? 'public');
        $limit = $limiter->consume(1);
        if (!$limit->isAccepted()) {
            $response = new Response('Too Many Requests.', Response::HTTP_TOO_MANY_REQUESTS);
            $retryAfter = $limit->getRetryAfter();
            if ($retryAfter !== null) {
                $seconds = max(1, $retryAfter->getTimestamp() - time());
                $response->headers->set('Retry-After', (string) $seconds);
            }

            return $response;
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $items = $this->downloadRepository->findVisiblePublicBySite($site->getId() ?? 0);

        $response = new Response($this->twig->render('public/downloads/index.html.twig', [
            'items' => $this->normalizeItems($items),
            'activeNav' => 'downloads',
        ]));

        $response->setPublic();
        $response->setMaxAge(60);
        $response->headers->addCacheControlDirective('s-maxage', '60');
        $response->headers->addCacheControlDirective('stale-while-revalidate', '60');

        return $response;
    }

    /**
     * @param DownloadItem[] $items
     */
    private function normalizeItems(array $items): array
    {
        return array_map(static function (DownloadItem $item): array {
            return [
                'title' => $item->getTitle(),
                'description' => $item->getDescription(),
                'url' => $item->getUrl(),
                'version' => $item->getVersion(),
                'file_size' => $item->getFileSize(),
            ];
        }, $items);
    }
}
