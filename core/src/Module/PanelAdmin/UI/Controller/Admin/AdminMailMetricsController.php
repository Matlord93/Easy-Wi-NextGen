<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Domain\Entity\User;
use App\Repository\MailMetricBucketRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/api/v1/admin/mail')]
final class AdminMailMetricsController
{
    public function __construct(private readonly MailMetricBucketRepository $metricBucketRepository)
    {
    }

    #[Route(path: '/overview', methods: ['GET'])]
    public function overview(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return new JsonResponse(['error' => 'Forbidden'], JsonResponse::HTTP_FORBIDDEN);
        }

        [$from, $to] = $this->resolveRange($request);
        $overview = $this->metricBucketRepository->fetchOverview($from, $to);
        $topSenders = $this->metricBucketRepository->fetchTopDimensions('mail.sent', 'sender', $from, $to, 10);
        $topDomains = $this->metricBucketRepository->fetchTopDimensions('mail.sent', 'domain', $from, $to, 10);

        return new JsonResponse([
            'range' => ['from' => $from->format(DATE_ATOM), 'to' => $to->format(DATE_ATOM)],
            'overview' => $overview,
            'top_senders' => $topSenders,
            'top_domains' => $topDomains,
        ]);
    }

    #[Route(path: '/queue', methods: ['GET'])]
    public function queue(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return new JsonResponse(['error' => 'Forbidden'], JsonResponse::HTTP_FORBIDDEN);
        }

        [$from, $to] = $this->resolveRange($request);
        $bucketSeconds = max(60, min((int) $request->query->get('bucket', 300), 3600));

        return new JsonResponse([
            'range' => ['from' => $from->format(DATE_ATOM), 'to' => $to->format(DATE_ATOM), 'bucket' => $bucketSeconds],
            'series' => $this->metricBucketRepository->fetchQueueSeries($from, $to, $bucketSeconds),
        ]);
    }

    #[Route(path: '/metrics', methods: ['GET'])]
    public function metrics(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return new JsonResponse(['error' => 'Forbidden'], JsonResponse::HTTP_FORBIDDEN);
        }

        [$from, $to] = $this->resolveRange($request);
        $metric = strtolower(trim((string) $request->query->get('metric', 'mail.sent')));
        $dimension = strtolower(trim((string) $request->query->get('dimension', 'sender')));
        $limit = (int) $request->query->get('limit', 20);

        return new JsonResponse([
            'range' => ['from' => $from->format(DATE_ATOM), 'to' => $to->format(DATE_ATOM)],
            'metric' => $metric,
            'dimension' => $dimension,
            'items' => $this->metricBucketRepository->fetchTopDimensions($metric, $dimension, $from, $to, $limit),
        ]);
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');
        return $actor instanceof User && $actor->isAdmin();
    }

    /** @return array{0:\DateTimeImmutable,1:\DateTimeImmutable} */
    private function resolveRange(Request $request): array
    {
        $to = $this->parseDate((string) $request->query->get('to', '')) ?? new \DateTimeImmutable('now');
        $from = $this->parseDate((string) $request->query->get('from', '')) ?? $to->sub(new \DateInterval('PT1H'));

        if ($from > $to) {
            return [$to->sub(new \DateInterval('PT1H')), $to];
        }

        return [$from, $to];
    }

    private function parseDate(string $raw): ?\DateTimeImmutable
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }
}
