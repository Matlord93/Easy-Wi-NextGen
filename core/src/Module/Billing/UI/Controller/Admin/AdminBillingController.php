<?php

declare(strict_types=1);

namespace App\Module\Billing\UI\Controller\Admin;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InvoiceStatus;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\CreditNoteRepository;
use App\Repository\InvoiceArchiveRepository;
use App\Repository\InvoiceRepository;
use App\Repository\PaymentRepository;
use App\Repository\UserRepository;
use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\Billing\AccountingExportService;
use App\Module\Core\Application\Billing\DunningWorkflow;
use App\Module\Core\Application\Billing\InvoiceArchiveManager;
use App\Module\Core\Application\Billing\InvoiceLayoutRenderer;
use App\Module\Core\Application\Billing\InvoiceStatusUpdater;
use App\Module\Core\Application\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/billing')]
final class AdminBillingController
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly CreditNoteRepository $creditNoteRepository,
        private readonly PaymentRepository $paymentRepository,
        private readonly InvoiceArchiveRepository $invoiceArchiveRepository,
        private readonly InvoiceArchiveManager $invoiceArchiveManager,
        private readonly AccountingExportService $accountingExportService,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly DunningWorkflow $dunningWorkflow,
        private readonly InvoiceStatusUpdater $invoiceStatusUpdater,
        private readonly AppSettingsService $settingsService,
        private readonly InvoiceLayoutRenderer $layoutRenderer,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_billing', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $recentInvoices = $this->invoiceRepository->findRecent();
        $invoiceIds = array_map(static fn (\App\Module\Core\Domain\Entity\Invoice $invoice): int => $invoice->getId() ?? 0, $recentInvoices);
        $archivedInvoiceIds = $this->invoiceArchiveRepository->findArchivedInvoiceIds($invoiceIds);
        $recentCreditNotes = $this->creditNoteRepository->findRecent();
        $summary = [
            'open' => $this->invoiceRepository->countByStatus(InvoiceStatus::Open),
            'past_due' => $this->invoiceRepository->countByStatus(InvoiceStatus::PastDue),
            'paid' => $this->invoiceRepository->countByStatus(InvoiceStatus::Paid),
        ];

        return new Response($this->twig->render('admin/billing/index.html.twig', [
            'activeNav' => 'billing',
            'summary' => $summary,
            'invoices' => $this->normalizeInvoices($recentInvoices, $archivedInvoiceIds),
            'credit_notes' => $this->normalizeCreditNotes($recentCreditNotes),
        ]));
    }

    #[Route(path: '/invoices/new', name: 'admin_billing_invoice_new', methods: ['GET'])]
    public function newInvoice(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $customers = $this->userRepository->findCustomers();

        return new Response($this->twig->render('admin/billing/create.html.twig', [
            'activeNav' => 'billing',
            'customers' => $customers,
            'form' => $this->buildInvoiceFormContext(),
        ]));
    }

    #[Route(path: '/invoices', name: 'admin_billing_invoice_create', methods: ['POST'])]
    public function createInvoice(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $formData = $this->parseInvoicePayload($request);
        $customers = $this->userRepository->findCustomers();

        if ($formData['errors'] !== []) {
            return new Response($this->twig->render('admin/billing/create.html.twig', [
                'activeNav' => 'billing',
                'customers' => $customers,
                'form' => $formData,
            ]), Response::HTTP_BAD_REQUEST);
        }

        $invoice = new \App\Module\Core\Domain\Entity\Invoice(
            $formData['customer'],
            $formData['number'],
            $formData['amount_total_cents'],
            $formData['currency'],
            $formData['due_date'],
        );

        $this->entityManager->persist($invoice);
        $this->auditLogger->log($actor, 'billing.invoice.created', [
            'invoice_id' => $invoice->getId(),
            'number' => $invoice->getNumber(),
            'customer_id' => $invoice->getCustomer()->getId(),
            'amount_total_cents' => $invoice->getAmountTotalCents(),
            'currency' => $invoice->getCurrency(),
        ]);
        $this->entityManager->flush();

        return new RedirectResponse(sprintf('/admin/billing/invoices/%d', $invoice->getId()));
    }

    #[Route(path: '/dunning', name: 'admin_billing_dunning_run', methods: ['POST'])]
    public function runDunning(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $now = new \DateTimeImmutable();
        $invoices = $this->invoiceRepository->findDunnable($now);

        foreach ($invoices as $invoice) {
            $this->invoiceStatusUpdater->syncStatus($invoice, $actor, $now);
            $this->dunningWorkflow->apply($invoice, $actor, $now);
        }

        $this->entityManager->flush();

        return new RedirectResponse('/admin/billing');
    }

    #[Route(path: '/invoices/{id}', name: 'admin_billing_invoice_show', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function showInvoice(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $invoice = $this->invoiceRepository->find($id);
        if (!$invoice instanceof \App\Module\Core\Domain\Entity\Invoice) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $archive = $this->invoiceArchiveRepository->findOneBy(['invoice' => $invoice]);
        $layout = $this->settingsService->getInvoiceLayout();
        $preview = $this->layoutRenderer->render($invoice, $request, $layout);

        return new Response($this->twig->render('admin/billing/invoice.html.twig', [
            'activeNav' => 'billing',
            'invoice' => $this->normalizeInvoiceDetail($invoice),
            'archive' => $this->normalizeArchive($archive),
            'archive_errors' => [],
            'invoice_layout' => $layout,
            'invoice_preview' => $preview,
        ]));
    }

    #[Route(path: '/invoices/{id}/mark-paid', name: 'admin_billing_invoice_mark_paid', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function markInvoicePaid(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $invoice = $this->invoiceRepository->find($id);
        if (!$invoice instanceof \App\Module\Core\Domain\Entity\Invoice) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $now = new \DateTimeImmutable();
        $previousStatus = $invoice->getStatus();
        $invoice->markPaid($now);

        $this->auditLogger->log($actor, 'billing.invoice.manual_paid', [
            'invoice_id' => $invoice->getId(),
            'number' => $invoice->getNumber(),
            'previous_status' => $previousStatus->value,
            'paid_at' => $now->format(DATE_RFC3339),
        ]);

        $this->entityManager->flush();

        return new RedirectResponse(sprintf('/admin/billing/invoices/%d', $invoice->getId()));
    }

    #[Route(path: '/invoices/{id}/mark-unpaid', name: 'admin_billing_invoice_mark_unpaid', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function markInvoiceUnpaid(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $invoice = $this->invoiceRepository->find($id);
        if (!$invoice instanceof \App\Module\Core\Domain\Entity\Invoice) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $now = new \DateTimeImmutable();
        $previousStatus = $invoice->getStatus();
        $nextStatus = $this->resolveUnpaidStatus($invoice, $now);
        $invoice->setStatus($nextStatus);
        $invoice->clearPaidAt();

        $this->auditLogger->log($actor, 'billing.invoice.manual_unpaid', [
            'invoice_id' => $invoice->getId(),
            'number' => $invoice->getNumber(),
            'previous_status' => $previousStatus->value,
            'next_status' => $nextStatus->value,
        ]);

        $this->entityManager->flush();

        return new RedirectResponse(sprintf('/admin/billing/invoices/%d', $invoice->getId()));
    }

    #[Route(path: '/invoices/{id}/archive', name: 'admin_billing_invoice_archive', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function archiveInvoice(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $invoice = $this->invoiceRepository->find($id);
        if (!$invoice instanceof \App\Module\Core\Domain\Entity\Invoice) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $archive = $this->invoiceArchiveRepository->findOneBy(['invoice' => $invoice]);

        $formData = $this->parseArchivePayload($request);
        if ($archive !== null) {
            $formData['errors'][] = 'Invoice is already archived and cannot be replaced.';
        }

        if ($formData['errors'] !== []) {
            return new Response($this->twig->render('admin/billing/invoice.html.twig', [
                'activeNav' => 'billing',
                'invoice' => $this->normalizeInvoiceDetail($invoice),
                'archive' => $this->normalizeArchive($archive),
                'archive_errors' => $formData['errors'],
                'invoice_layout' => $this->settingsService->getInvoiceLayout(),
                'invoice_preview' => $this->layoutRenderer->render(
                    $invoice,
                    $request,
                    $this->settingsService->getInvoiceLayout()
                ),
            ]), Response::HTTP_BAD_REQUEST);
        }

        /** @var UploadedFile $file */
        $file = $formData['file'];
        $archive = $this->invoiceArchiveManager->archiveInvoice($invoice, $file, $actor);

        return new Response($this->twig->render('admin/billing/invoice.html.twig', [
            'activeNav' => 'billing',
            'invoice' => $this->normalizeInvoiceDetail($invoice),
            'archive' => $this->normalizeArchive($archive),
            'archive_errors' => [],
            'invoice_layout' => $this->settingsService->getInvoiceLayout(),
            'invoice_preview' => $this->layoutRenderer->render(
                $invoice,
                $request,
                $this->settingsService->getInvoiceLayout()
            ),
        ]));
    }

    #[Route(path: '/invoices/{id}/archive/generate', name: 'admin_billing_invoice_archive_generate', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function generateArchive(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $invoice = $this->invoiceRepository->find($id);
        if (!$invoice instanceof \App\Module\Core\Domain\Entity\Invoice) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $archive = $this->invoiceArchiveRepository->findOneBy(['invoice' => $invoice]);
        if ($archive !== null) {
            return new Response('Invoice is already archived.', Response::HTTP_BAD_REQUEST);
        }

        $layout = $this->settingsService->getInvoiceLayout();
        $contents = $this->layoutRenderer->render($invoice, $request, $layout);
        $fileName = sprintf('%s.html', $invoice->getNumber());

        $archive = $this->invoiceArchiveManager->archiveInvoiceContent(
            $invoice,
            $contents,
            $fileName,
            'text/html',
            $actor,
        );

        return new Response($this->twig->render('admin/billing/invoice.html.twig', [
            'activeNav' => 'billing',
            'invoice' => $this->normalizeInvoiceDetail($invoice),
            'archive' => $this->normalizeArchive($archive),
            'archive_errors' => [],
            'invoice_layout' => $layout,
            'invoice_preview' => $contents,
        ]));
    }

    #[Route(path: '/layout', name: 'admin_billing_layout_update', methods: ['POST'])]
    public function updateLayout(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $layout = (string) $request->request->get('invoice_layout', '');
        if (trim($layout) === '') {
            return new Response('Invoice layout cannot be empty.', Response::HTTP_BAD_REQUEST);
        }

        $this->settingsService->updateSettings([
            AppSettingsService::KEY_INVOICE_LAYOUT => $layout,
        ]);

        $redirect = $request->headers->get('referer');
        if (!is_string($redirect) || $redirect === '') {
            $redirect = '/admin/billing?layout_saved=1';
        }

        return new RedirectResponse($redirect);
    }

    #[Route(path: '/invoices/{id}/archive/download', name: 'admin_billing_invoice_archive_download', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function downloadArchive(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $invoice = $this->invoiceRepository->find($id);
        if (!$invoice instanceof \App\Module\Core\Domain\Entity\Invoice) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $archive = $this->invoiceArchiveRepository->findOneBy(['invoice' => $invoice]);
        if ($archive === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $response = new Response($archive->getPdfContents());
        $response->headers->set('Content-Type', $archive->getContentType());
        $response->headers->set('Content-Disposition', sprintf('attachment; filename=\"%s\"', $archive->getFileName()));

        return $response;
    }

    #[Route(path: '/export', name: 'admin_billing_export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $type = (string) $request->query->get('type', 'invoices');
        $yearValue = $request->query->get('year');
        $year = is_numeric($yearValue) ? (int) $yearValue : null;

        if ($type === 'payments') {
            $payments = $this->paymentRepository->findForExport($year);
            $csv = $this->accountingExportService->exportPayments($payments);
            $fileName = $year !== null ? sprintf('payments-%d.csv', $year) : 'payments.csv';
        } else {
            $invoices = $this->invoiceRepository->findForExport($year);
            $invoiceIds = array_map(static fn (\App\Module\Core\Domain\Entity\Invoice $invoice): int => $invoice->getId() ?? 0, $invoices);
            $archiveMeta = $this->invoiceArchiveRepository->findArchiveMetadataByInvoiceIds($invoiceIds);
            $csv = $this->accountingExportService->exportInvoices($invoices, $archiveMeta);
            $fileName = $year !== null ? sprintf('invoices-%d.csv', $year) : 'invoices.csv';
        }

        $response = new StreamedResponse(static function () use ($csv): void {
            echo $csv;
        });
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename=\"%s\"', $fileName));

        return $response;
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');
        return $actor instanceof User && $actor->isAdmin();
    }

    /**
     * @param int[] $archivedInvoiceIds
     */
    private function normalizeInvoices(array $invoices, array $archivedInvoiceIds): array
    {
        return array_map(static function (\App\Module\Core\Domain\Entity\Invoice $invoice) use ($archivedInvoiceIds): array {
            $isArchived = in_array($invoice->getId(), $archivedInvoiceIds, true);

            return [
                'id' => $invoice->getId(),
                'number' => $invoice->getNumber(),
                'customer' => $invoice->getCustomer()->getEmail(),
                'status' => $invoice->getStatus()->value,
                'amount_due' => $invoice->getAmountDueCents(),
                'currency' => $invoice->getCurrency(),
                'due_date' => $invoice->getDueDate(),
                'paid_at' => $invoice->getPaidAt(),
                'is_archived' => $isArchived,
            ];
        }, $invoices);
    }

    private function normalizeCreditNotes(array $creditNotes): array
    {
        return array_map(static function (\App\Module\Core\Domain\Entity\CreditNote $creditNote): array {
            return [
                'id' => $creditNote->getId(),
                'number' => $creditNote->getNumber(),
                'invoice_number' => $creditNote->getInvoice()->getNumber(),
                'customer' => $creditNote->getInvoice()->getCustomer()->getEmail(),
                'status' => $creditNote->getStatus()->value,
                'amount' => $creditNote->getAmountCents(),
                'currency' => $creditNote->getCurrency(),
                'issued_at' => $creditNote->getIssuedAt(),
                'reason' => $creditNote->getReason(),
            ];
        }, $creditNotes);
    }

    private function normalizeInvoiceDetail(\App\Module\Core\Domain\Entity\Invoice $invoice): array
    {
        return [
            'id' => $invoice->getId(),
            'number' => $invoice->getNumber(),
            'status' => $invoice->getStatus()->value,
            'customer' => $invoice->getCustomer()->getEmail(),
            'currency' => $invoice->getCurrency(),
            'amount_total' => $invoice->getAmountTotalCents(),
            'amount_due' => $invoice->getAmountDueCents(),
            'due_date' => $invoice->getDueDate(),
            'paid_at' => $invoice->getPaidAt(),
            'created_at' => $invoice->getCreatedAt(),
        ];
    }

    private function resolveUnpaidStatus(\App\Module\Core\Domain\Entity\Invoice $invoice, \DateTimeImmutable $now): InvoiceStatus
    {
        return $invoice->getDueDate() <= $now ? InvoiceStatus::PastDue : InvoiceStatus::Open;
    }

    private function normalizeArchive(?\App\Module\Core\Domain\Entity\InvoiceArchive $archive): array
    {
        if ($archive === null) {
            return [
                'is_archived' => false,
            ];
        }

        return [
            'is_archived' => true,
            'id' => $archive->getId(),
            'file_name' => $archive->getFileName(),
            'content_type' => $archive->getContentType(),
            'file_size' => $archive->getFileSize(),
            'pdf_hash' => $archive->getPdfHash(),
            'archived_year' => $archive->getArchivedYear(),
            'created_at' => $archive->getCreatedAt(),
        ];
    }

    private function buildInvoiceFormContext(
        array $errors = [],
        ?User $customer = null,
        ?string $number = null,
        ?string $amount = null,
        ?string $currency = null,
        ?string $dueDate = null,
    ): array {
        $defaultDueDate = (new \DateTimeImmutable('+14 days'))->format('Y-m-d');

        return [
            'errors' => $errors,
            'customer_id' => $customer?->getId(),
            'number' => $number ?? $this->generateInvoiceNumber(),
            'amount' => $amount ?? '',
            'currency' => $currency ?? 'EUR',
            'due_date' => $dueDate ?? $defaultDueDate,
            'due_date_raw' => $dueDate ?? $defaultDueDate,
        ];
    }

    private function parseInvoicePayload(Request $request): array
    {
        $errors = [];
        $customerId = (int) $request->request->get('customer_id', 0);
        $number = trim((string) $request->request->get('number', ''));
        $amountRaw = trim((string) $request->request->get('amount', ''));
        $currency = strtoupper(trim((string) $request->request->get('currency', 'EUR')));
        $dueDateRaw = trim((string) $request->request->get('due_date', ''));

        $customer = $customerId > 0 ? $this->userRepository->find($customerId) : null;
        if (!$customer instanceof User || $customer->getType() !== UserType::Customer) {
            $errors[] = 'Customer is required.';
        }

        if ($number === '') {
            $errors[] = 'Invoice number is required.';
        }

        $amountTotalCents = $this->parseAmountCents($amountRaw);
        if ($amountTotalCents <= 0) {
            $errors[] = 'Amount must be greater than zero.';
        }

        if ($currency === '' || strlen($currency) !== 3) {
            $errors[] = 'Currency must be a 3-letter code.';
        }

        $dueDate = \DateTimeImmutable::createFromFormat('Y-m-d', $dueDateRaw) ?: null;
        if ($dueDate === null) {
            $errors[] = 'Due date is required.';
        }

        return [
            'errors' => $errors,
            'customer' => $customer instanceof User ? $customer : null,
            'customer_id' => $customerId,
            'number' => $number,
            'amount' => $amountRaw,
            'amount_total_cents' => $amountTotalCents,
            'currency' => $currency,
            'due_date' => $dueDate,
            'due_date_raw' => $dueDateRaw,
        ];
    }

    private function parseAmountCents(string $amount): int
    {
        if ($amount === '') {
            return 0;
        }

        $normalized = str_replace(',', '.', $amount);
        if (!is_numeric($normalized)) {
            return 0;
        }

        return (int) round(((float) $normalized) * 100);
    }

    private function generateInvoiceNumber(): string
    {
        $date = (new \DateTimeImmutable())->format('Ymd');

        return sprintf('INV-%s-%04d', $date, random_int(0, 9999));
    }

    private function parseArchivePayload(Request $request): array
    {
        $errors = [];
        $file = $request->files->get('invoice_pdf');

        if (!$file instanceof UploadedFile) {
            $errors[] = 'Invoice PDF is required.';
            return [
                'errors' => $errors,
                'file' => null,
            ];
        }

        if (!$file->isValid()) {
            $errors[] = 'PDF upload failed.';
        }

        $mimeType = $file->getMimeType();
        if ($mimeType !== null && $mimeType !== 'application/pdf') {
            $errors[] = 'Only PDF files are allowed.';
        }

        $size = $file->getSize() ?? 0;
        if ($size === 0) {
            $errors[] = 'PDF file is empty.';
        }
        if ($size > InvoiceArchiveManager::MAX_FILE_SIZE) {
            $errors[] = 'PDF file exceeds the 10MB limit.';
        }

        return [
            'errors' => $errors,
            'file' => $file,
        ];
    }
}
