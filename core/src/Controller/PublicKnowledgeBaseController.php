<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\KnowledgeBaseArticle;
use App\Enum\TicketCategory;
use App\Repository\KnowledgeBaseArticleRepository;
use App\Service\SiteResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Twig\Environment;

final class PublicKnowledgeBaseController
{
    public function __construct(
        private readonly KnowledgeBaseArticleRepository $kbRepository,
        private readonly SiteResolver $siteResolver,
        #[Autowire(service: 'limiter.public_docs')]
        private readonly RateLimiterFactory $docsLimiter,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '/docs', name: 'public_docs', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $limiter = $this->docsLimiter->create($request->getClientIp() ?? 'public');
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

        $articles = $this->kbRepository->findVisiblePublicBySite($site->getId() ?? 0);

        $response = new Response($this->twig->render('public/docs/index.html.twig', [
            'categories' => $this->groupByCategory($articles),
            'activeNav' => 'docs',
        ]));

        $response->setPublic();
        $response->setMaxAge(60);
        $response->headers->addCacheControlDirective('s-maxage', '60');
        $response->headers->addCacheControlDirective('stale-while-revalidate', '60');

        return $response;
    }

    #[Route(path: '/docs/{slug}', name: 'public_docs_show', methods: ['GET'])]
    public function show(Request $request, string $slug): Response
    {
        $limiter = $this->docsLimiter->create($request->getClientIp() ?? 'public');
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

        $article = $this->kbRepository->findOneBy([
            'siteId' => $site->getId(),
            'slug' => $slug,
            'visiblePublic' => true,
        ]);

        if (!$article instanceof KnowledgeBaseArticle) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $response = new Response($this->twig->render('public/docs/show.html.twig', [
            'article' => $this->normalizeArticle($article),
            'activeNav' => 'docs',
        ]));

        $response->setPublic();
        $response->setMaxAge(60);
        $response->headers->addCacheControlDirective('s-maxage', '60');
        $response->headers->addCacheControlDirective('stale-while-revalidate', '60');

        return $response;
    }

    /**
     * @param KnowledgeBaseArticle[] $articles
     * @return array<int, array{key: string, label: string, items: array<int, array{title: string, slug: string, excerpt: string}>}>
     */
    private function groupByCategory(array $articles): array
    {
        $grouped = [];
        foreach ($articles as $article) {
            $key = $article->getCategory()->value;
            $grouped[$key]['key'] = $key;
            $grouped[$key]['label'] = $this->categoryLabel($article->getCategory());
            $grouped[$key]['items'][] = [
                'title' => $article->getTitle(),
                'slug' => $article->getSlug(),
                'excerpt' => $this->excerpt($article->getContent()),
            ];
        }

        return array_values($grouped);
    }

    private function categoryLabel(TicketCategory $category): string
    {
        return match ($category) {
            TicketCategory::General => 'General',
            TicketCategory::Billing => 'Billing',
            TicketCategory::Technical => 'Technical',
            TicketCategory::Abuse => 'Abuse',
        };
    }

    private function excerpt(string $content): string
    {
        $text = trim(strip_tags($content));
        if (mb_strlen($text) <= 140) {
            return $text;
        }

        return mb_substr($text, 0, 140) . '…';
    }

    private function normalizeArticle(KnowledgeBaseArticle $article): array
    {
        return [
            'title' => $article->getTitle(),
            'content' => $article->getContent(),
            'category' => $this->categoryLabel($article->getCategory()),
        ];
    }
}
