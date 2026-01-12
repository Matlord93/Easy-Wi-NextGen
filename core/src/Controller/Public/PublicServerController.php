<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Entity\PublicServer;
use App\Repository\PublicServerRepository;
use App\Service\SiteResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Twig\Environment;

final class PublicServerController
{
    public function __construct(
        private readonly PublicServerRepository $publicServerRepository,
        private readonly SiteResolver $siteResolver,
        #[Autowire(service: 'limiter.public_servers')]
        private readonly RateLimiterFactory $publicServersLimiter,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '/servers', name: 'public_servers', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $limiter = $this->publicServersLimiter->create($request->getClientIp() ?? 'public');
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

        $gameFilter = $this->normalizeFilter($request->query->get('game'));
        $statusFilter = $this->normalizeFilter($request->query->get('status'));
        if ($statusFilter !== null) {
            $statusFilter = strtolower($statusFilter);
        }
        $search = $this->normalizeFilter($request->query->get('search'));

        $servers = $this->publicServerRepository->findVisiblePublicBySite($site->getId() ?? 0, $gameFilter, $search);
        $normalizedServers = $this->normalizeServers($servers);

        if ($statusFilter !== null) {
            $normalizedServers = array_values(array_filter(
                $normalizedServers,
                static fn (array $server): bool => $server['status'] === $statusFilter,
            ));
        }

        $response = new Response($this->twig->render('public/servers/index.html.twig', [
            'servers' => $normalizedServers,
            'games' => $this->publicServerRepository->findPublicGamesForSite($site->getId() ?? 0),
            'filters' => [
                'game' => $gameFilter,
                'status' => $statusFilter,
                'search' => $search,
            ],
        ]));

        $response->setPublic();
        $response->setMaxAge(30);
        $response->headers->addCacheControlDirective('s-maxage', '30');
        $response->headers->addCacheControlDirective('stale-while-revalidate', '30');

        return $response;
    }

    /**
     * @param PublicServer[] $servers
     */
    private function normalizeServers(array $servers): array
    {
        return array_map(function (PublicServer $server): array {
            $statusCache = $server->getStatusCache();
            $statusValue = $statusCache['status'] ?? ($statusCache['online'] ?? null);
            $status = $this->normalizeStatus($statusValue);

            return [
                'id' => $server->getId(),
                'name' => $server->getName(),
                'game_key' => $server->getGameKey(),
                'address' => sprintf('%s:%d', $server->getIp(), $server->getPort()),
                'status' => $status,
                'players' => is_numeric($statusCache['players'] ?? null) ? (int) $statusCache['players'] : null,
                'max_players' => is_numeric($statusCache['max_players'] ?? null) ? (int) $statusCache['max_players'] : null,
                'map' => is_string($statusCache['map'] ?? null) ? $statusCache['map'] : null,
                'last_checked_at' => $server->getLastCheckedAt(),
            ];
        }, $servers);
    }

    private function normalizeStatus(mixed $statusValue): string
    {
        if (is_string($statusValue) && $statusValue !== '') {
            return strtolower($statusValue);
        }

        if ($statusValue === true) {
            return 'online';
        }

        if ($statusValue === false) {
            return 'offline';
        }

        return 'unknown';
    }

    private function normalizeFilter(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return $value;
    }
}
