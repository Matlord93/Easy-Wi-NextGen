<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Customer;

use App\Module\Core\Domain\Entity\GdprExport;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\GdprExportStatus;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\GdprExportRepository;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Application\GdprExportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/gdpr/exports')]
final class CustomerGdprExportController
{
    public function __construct(
        private readonly GdprExportRepository $exportRepository,
        private readonly GdprExportService $exportService,
        private readonly EncryptionService $encryptionService,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'customer_gdpr_exports', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $exports = $this->exportRepository->findByCustomer($customer);

        return new Response($this->twig->render('customer/gdpr/exports/index.html.twig', [
            'exports' => $this->normalizeExports($exports),
            'statusStyles' => $this->statusStyles(),
            'activeNav' => 'gdpr-export',
        ]));
    }

    #[Route(path: '/table', name: 'customer_gdpr_exports_table', methods: ['GET'])]
    public function table(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $exports = $this->exportRepository->findByCustomer($customer);

        return new Response($this->twig->render('customer/gdpr/exports/_table.html.twig', [
            'exports' => $this->normalizeExports($exports),
            'statusStyles' => $this->statusStyles(),
        ]));
    }

    #[Route(path: '', name: 'customer_gdpr_exports_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $customer = $this->requireCustomer($request);

        $export = $this->exportService->buildExport($customer);
        $this->entityManager->persist($export);

        $this->entityManager->flush();

        $this->auditLogger->log($customer, 'gdpr.export_requested', [
            'user_id' => $customer->getId(),
            'export_id' => $export->getId(),
            'expires_at' => $export->getExpiresAt()->format(DATE_RFC3339),
            'file_name' => $export->getFileName(),
        ]);

        $this->entityManager->flush();

        $response = new Response($this->twig->render('customer/gdpr/exports/_table.html.twig', [
            'exports' => $this->normalizeExports($this->exportRepository->findByCustomer($customer)),
            'statusStyles' => $this->statusStyles(),
        ]));
        $response->headers->set('HX-Trigger', 'gdpr-export-updated');

        return $response;
    }

    #[Route(path: '/{id}/download', name: 'customer_gdpr_exports_download', methods: ['GET'])]
    public function download(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $export = $this->exportRepository->find($id);

        if (!$export instanceof GdprExport || $export->getCustomer()->getId() !== $customer->getId()) {
            throw new NotFoundHttpException('Export not found.');
        }

        $now = new \DateTimeImmutable();
        if ($export->isExpired($now)) {
            if ($export->getStatus() !== GdprExportStatus::Expired) {
                $export->setStatus(GdprExportStatus::Expired);
                $this->entityManager->persist($export);
                $this->auditLogger->log($customer, 'gdpr.export_expired', [
                    'export_id' => $export->getId(),
                    'user_id' => $customer->getId(),
                ]);
                $this->entityManager->flush();
            }

            return new Response('Export expired.', Response::HTTP_GONE);
        }

        $bytes = $this->encryptionService->decrypt($export->getEncryptedPayload());

        $response = new Response($bytes);
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $export->getFileName()));

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
     * @param GdprExport[] $exports
     * @return array<int, array<string, mixed>>
     */
    private function normalizeExports(array $exports): array
    {
        $now = new \DateTimeImmutable();
        return array_map(static function (GdprExport $export) use ($now): array {
            return [
                'id' => $export->getId(),
                'status' => $export->getStatus(),
                'fileName' => $export->getFileName(),
                'fileSize' => $export->getFileSize(),
                'requestedAt' => $export->getRequestedAt(),
                'readyAt' => $export->getReadyAt(),
                'expiresAt' => $export->getExpiresAt(),
                'expired' => $export->isExpired($now),
            ];
        }, $exports);
    }

    /**
     * @return array<string, array{label: string, badge: string}>
     */
    private function statusStyles(): array
    {
        return [
            GdprExportStatus::Pending->value => [
                'label' => 'Pending',
                'badge' => 'bg-slate-50 text-slate-700 border border-slate-200',
            ],
            GdprExportStatus::Ready->value => [
                'label' => 'Ready',
                'badge' => 'bg-emerald-50 text-emerald-700 border border-emerald-200',
            ],
            GdprExportStatus::Expired->value => [
                'label' => 'Expired',
                'badge' => 'bg-rose-50 text-rose-700 border border-rose-200',
            ],
        ];
    }
}
