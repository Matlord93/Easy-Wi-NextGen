<?php

declare(strict_types=1);

namespace App\Controller\Customer;

use App\Entity\ConsentLog;
use App\Entity\User;
use App\Enum\ConsentType;
use App\Enum\UserType;
use App\Repository\ConsentLogRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/gdpr/consents')]
final class CustomerConsentLogController
{
    public function __construct(
        private readonly ConsentLogRepository $consentLogRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'customer_consent_logs', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $logs = $this->consentLogRepository->findByUser($customer);

        return new Response($this->twig->render('customer/gdpr/consents/index.html.twig', [
            'logs' => $this->normalizeLogs($logs),
            'types' => ConsentType::cases(),
            'typeStyles' => $this->typeStyles(),
            'activeNav' => 'gdpr-consent',
        ]));
    }

    #[Route(path: '/table', name: 'customer_consent_logs_table', methods: ['GET'])]
    public function table(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $logs = $this->consentLogRepository->findByUser($customer);

        return new Response($this->twig->render('customer/gdpr/consents/_table.html.twig', [
            'logs' => $this->normalizeLogs($logs),
            'typeStyles' => $this->typeStyles(),
        ]));
    }

    #[Route(path: '', name: 'customer_consent_logs_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $customer = $this->requireCustomer($request);

        $type = ConsentType::tryFrom((string) $request->request->get('type', ''));
        $version = trim((string) $request->request->get('version', ''));

        if ($type === null || $version === '') {
            return new Response('Invalid consent payload.', Response::HTTP_BAD_REQUEST);
        }

        $ip = (string) ($request->getClientIp() ?? '');
        if ($ip === '') {
            $ip = 'unknown';
        }

        $userAgent = (string) $request->headers->get('User-Agent', '');
        if ($userAgent === '') {
            $userAgent = 'unknown';
        }

        $log = new ConsentLog($customer, $type, $ip, $userAgent, $version);
        $this->entityManager->persist($log);

        $this->auditLogger->log($customer, 'consent.recorded', [
            'user_id' => $customer->getId(),
            'type' => $type->value,
            'version' => $version,
            'ip' => $ip,
        ]);

        $this->entityManager->flush();

        $response = new Response($this->twig->render('customer/gdpr/consents/_table.html.twig', [
            'logs' => $this->normalizeLogs($this->consentLogRepository->findByUser($customer)),
            'typeStyles' => $this->typeStyles(),
        ]));
        $response->headers->set('HX-Trigger', 'consent-log-updated');

        return $response;
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            throw new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('session', 'Unauthorized.');
        }

        return $actor;
    }

    /**
     * @param ConsentLog[] $logs
     * @return array<int, array<string, mixed>>
     */
    private function normalizeLogs(array $logs): array
    {
        return array_map(static function (ConsentLog $log): array {
            return [
                'id' => $log->getId(),
                'type' => $log->getType(),
                'acceptedAt' => $log->getAcceptedAt(),
                'ip' => $log->getIp(),
                'userAgent' => $log->getUserAgent(),
                'version' => $log->getVersion(),
            ];
        }, $logs);
    }

    /**
     * @return array<string, array{label: string, badge: string}>
     */
    private function typeStyles(): array
    {
        return [
            ConsentType::Terms->value => [
                'label' => 'Terms',
                'badge' => 'bg-indigo-50 text-indigo-700 border border-indigo-200',
            ],
            ConsentType::Privacy->value => [
                'label' => 'Privacy',
                'badge' => 'bg-emerald-50 text-emerald-700 border border-emerald-200',
            ],
            ConsentType::Marketing->value => [
                'label' => 'Marketing',
                'badge' => 'bg-amber-50 text-amber-700 border border-amber-200',
            ],
        ];
    }
}
