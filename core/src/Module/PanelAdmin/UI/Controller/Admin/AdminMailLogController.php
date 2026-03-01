<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Domain\Entity\User;
use App\Repository\DomainRepository;
use App\Repository\MailLogRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/api/v1/admin/mail/logs')]
final class AdminMailLogController
{
    public function __construct(
        private readonly MailLogRepository $mailLogRepository,
        private readonly DomainRepository $domainRepository,
    ) {
    }

    #[Route(path: '', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new JsonResponse(['error' => 'Forbidden'], JsonResponse::HTTP_FORBIDDEN);
        }

        $domainName = trim((string) $request->query->get('domain', ''));
        $level = trim((string) $request->query->get('level', ''));
        $fromRaw = trim((string) $request->query->get('from', ''));
        $toRaw = trim((string) $request->query->get('to', ''));
        $limit = (int) $request->query->get('limit', 200);

        $domain = $domainName !== '' ? $this->domainRepository->findOneBy(['name' => strtolower($domainName)]) : null;
        $from = $this->parseDate($fromRaw);
        $to = $this->parseDate($toRaw);

        $logs = $this->mailLogRepository->findByAdminFilters($domain, $level !== '' ? $level : null, $from, $to, $limit);

        $rows = array_map(static function ($log): array {
            return [
                'id' => $log->getId(),
                'created_at' => $log->getCreatedAt()->format(DATE_ATOM),
                'level' => $log->getLevel(),
                'source' => $log->getSource(),
                'event_type' => $log->getEventType(),
                'message' => $log->getMessage(),
                'domain_id' => $log->getDomain()->getId(),
                'domain' => $log->getDomain()->getName(),
                'user_id' => $log->getUser()?->getId(),
                'payload' => $log->getPayload(),
            ];
        }, $logs);

        return new JsonResponse(['items' => $rows, 'count' => count($rows)]);
    }

    private function parseDate(string $raw): ?\DateTimeImmutable
    {
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
