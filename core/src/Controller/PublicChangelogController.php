<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ChangelogEntry;
use App\Repository\ChangelogEntryRepository;
use App\Service\ChangelogFetcher;
use App\Service\SiteResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Twig\Environment;

final class PublicChangelogController
{
    public function __construct(
        private readonly ChangelogEntryRepository $changelogRepository,
        private readonly ChangelogFetcher $changelogFetcher,
        private readonly SiteResolver $siteResolver,
        #[Autowire(service: 'limiter.public_changelog')]
        private readonly RateLimiterFactory $changelogLimiter,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '/changelog', name: 'public_changelog', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $limiter = $this->changelogLimiter->create($request->getClientIp() ?? 'public');
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

        $manualEntries = $this->changelogRepository->findVisiblePublicBySite($site->getId() ?? 0);
        $releaseEntries = $this->normalizeReleaseEntries($this->changelogFetcher->getReleases());

        $entries = array_merge($releaseEntries, $this->normalizeManualEntries($manualEntries));
        usort($entries, static function (array $left, array $right): int {
            $leftDate = $left['published_at'];
            $rightDate = $right['published_at'];

            if (!$leftDate instanceof \DateTimeImmutable && !$rightDate instanceof \DateTimeImmutable) {
                return 0;
            }
            if (!$leftDate instanceof \DateTimeImmutable) {
                return 1;
            }
            if (!$rightDate instanceof \DateTimeImmutable) {
                return -1;
            }

            return $rightDate->getTimestamp() <=> $leftDate->getTimestamp();
        });

        $response = new Response($this->twig->render('public/changelog/index.html.twig', [
            'entries' => $entries,
            'repository' => $this->changelogFetcher->getRepository(),
            'activeNav' => 'changelog',
        ]));

        $response->setPublic();
        $response->setMaxAge(60);
        $response->headers->addCacheControlDirective('s-maxage', '60');
        $response->headers->addCacheControlDirective('stale-while-revalidate', '60');

        return $response;
    }

    /**
     * @param ChangelogEntry[] $entries
     */
    private function normalizeManualEntries(array $entries): array
    {
        return array_map(static function (ChangelogEntry $entry): array {
            return [
                'title' => $entry->getTitle(),
                'version' => $entry->getVersion(),
                'content' => $entry->getContent(),
                'published_at' => $entry->getPublishedAt(),
                'source' => 'manual',
            ];
        }, $entries);
    }

    /**
     * @param array<int, array{title: string, version: string|null, content: string, published_at: \DateTimeImmutable|null}> $entries
     */
    private function normalizeReleaseEntries(array $entries): array
    {
        return array_map(static function (array $entry): array {
            return [
                'title' => $entry['title'],
                'version' => $entry['version'],
                'content' => $entry['content'],
                'published_at' => $entry['published_at'],
                'source' => 'github',
            ];
        }, $entries);
    }
}
