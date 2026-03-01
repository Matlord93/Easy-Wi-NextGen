<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Domain\Entity\User;
use App\Repository\DomainRepository;
use App\Repository\MailRateLimitRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/api/v1/admin/mail/rate-limits')]
final class AdminMailRateLimitController
{
    public function __construct(
        private readonly MailRateLimitRepository $mailRateLimitRepository,
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

        $domainName = strtolower(trim((string) $request->query->get('domain', '')));
        $domain = $domainName !== '' ? $this->domainRepository->findOneBy(['name' => $domainName]) : null;
        $limit = (int) $request->query->get('limit', 200);

        $rows = array_map(static function ($entry): array {
            $mailbox = $entry->getMailbox();
            return [
                'mailbox_id' => $mailbox->getId(),
                'address' => $mailbox->getAddress(),
                'domain_id' => $mailbox->getDomain()->getId(),
                'domain' => $mailbox->getDomain()->getName(),
                'max_hourly_emails' => $entry->getMaxMailsPerHour(),
                'max_recipients' => $entry->getMaxRecipientsPerMail(),
                'counter_window_start' => $entry->getCounterWindowStart()->format(DATE_ATOM),
                'current_count' => $entry->getCurrentCount(),
                'blocked_until' => $entry->getBlockedUntil()?->format(DATE_ATOM),
            ];
        }, $this->mailRateLimitRepository->findByDomain($domain, $limit));

        return new JsonResponse(['items' => $rows, 'count' => count($rows)]);
    }
}
