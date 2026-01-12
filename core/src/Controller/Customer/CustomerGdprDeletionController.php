<?php

declare(strict_types=1);

namespace App\Controller\Customer;

use App\Entity\GdprDeletionRequest;
use App\Entity\Job;
use App\Entity\User;
use App\Enum\GdprDeletionStatus;
use App\Enum\UserType;
use App\Repository\GdprDeletionRequestRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/gdpr/delete')]
final class CustomerGdprDeletionController
{
    public function __construct(
        private readonly GdprDeletionRequestRepository $deletionRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'customer_gdpr_delete', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $latest = $this->deletionRepository->findLatestByCustomer($customer);

        return new Response($this->twig->render('customer/gdpr/delete/index.html.twig', [
            'request' => $this->normalizeRequest($latest),
            'statusStyles' => $this->statusStyles(),
            'activeNav' => 'gdpr-delete',
        ]));
    }

    #[Route(path: '/status', name: 'customer_gdpr_delete_status', methods: ['GET'])]
    public function status(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $latest = $this->deletionRepository->findLatestByCustomer($customer);

        return new Response($this->twig->render('customer/gdpr/delete/_status.html.twig', [
            'request' => $this->normalizeRequest($latest),
            'statusStyles' => $this->statusStyles(),
        ]));
    }

    #[Route(path: '', name: 'customer_gdpr_delete_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $latest = $this->deletionRepository->findLatestByCustomer($customer);

        if ($latest !== null && $latest->getStatus() !== GdprDeletionStatus::Completed) {
            return new Response('Deletion already requested.', Response::HTTP_CONFLICT);
        }

        $deletionRequest = new GdprDeletionRequest($customer);
        $job = new Job('gdpr.anonymize_user', [
            'user_id' => $customer->getId(),
        ]);

        $deletionRequest->markProcessing($job->getId());

        $this->entityManager->persist($deletionRequest);
        $this->entityManager->persist($job);

        $this->entityManager->flush();

        $this->auditLogger->log($customer, 'gdpr.deletion_requested', [
            'user_id' => $customer->getId(),
            'request_id' => $deletionRequest->getId(),
            'job_id' => $job->getId(),
        ]);

        $this->entityManager->flush();

        $response = new Response($this->twig->render('customer/gdpr/delete/_status.html.twig', [
            'request' => $this->normalizeRequest($deletionRequest),
            'statusStyles' => $this->statusStyles(),
        ]));
        $response->headers->set('HX-Trigger', 'gdpr-delete-updated');

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

    private function normalizeRequest(?GdprDeletionRequest $request): ?array
    {
        if ($request === null) {
            return null;
        }

        return [
            'id' => $request->getId(),
            'status' => $request->getStatus(),
            'jobId' => $request->getJobId(),
            'requestedAt' => $request->getRequestedAt(),
            'processedAt' => $request->getProcessedAt(),
        ];
    }

    /**
     * @return array<string, array{label: string, badge: string}>
     */
    private function statusStyles(): array
    {
        return [
            GdprDeletionStatus::Requested->value => [
                'label' => 'Requested',
                'badge' => 'bg-slate-50 text-slate-700 border border-slate-200',
            ],
            GdprDeletionStatus::Processing->value => [
                'label' => 'Processing',
                'badge' => 'bg-amber-50 text-amber-700 border border-amber-200',
            ],
            GdprDeletionStatus::Completed->value => [
                'label' => 'Completed',
                'badge' => 'bg-emerald-50 text-emerald-700 border border-emerald-200',
            ],
        ];
    }
}
