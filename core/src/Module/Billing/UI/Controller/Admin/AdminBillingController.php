<?php

declare(strict_types=1);

namespace App\Module\Billing\UI\Controller\Admin;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\Billing\AccountingExportService;
use App\Module\Core\Application\Billing\BillingAmount;
use App\Module\Core\Application\Billing\BillingSettings;
use App\Module\Core\Application\Billing\DunningWorkflow;
use App\Module\Core\Application\Billing\InvoiceAdjustmentService;
use App\Module\Core\Application\Billing\InvoiceArchiveManager;
use App\Module\Core\Application\Billing\InvoiceDocumentService;
use App\Module\Core\Application\Billing\InvoiceItems;
use App\Module\Core\Application\Billing\InvoiceLayoutRenderer;
use App\Module\Core\Application\Billing\InvoicePresenter;
use App\Module\Core\Application\Billing\InvoiceStatusUpdater;
use App\Module\Core\Application\Billing\PaymentRecorder;
use App\Module\Core\Domain\Entity\Invoice;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InvoiceStatus;
use App\Module\Core\Domain\Enum\PaymentStatus;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\CreditNoteRepository;
use App\Repository\InvoiceArchiveRepository;
use App\Repository\InvoiceRepository;
use App\Repository\PaymentRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

#[Route('/admin/billing')]
final readonly class AdminBillingController
{
    public function __construct(
        private InvoiceRepository $invoiceRepository,
        private CreditNoteRepository $creditNoteRepository,
        private PaymentRepository $paymentRepository,
        private InvoiceArchiveRepository $invoiceArchiveRepository,
        private InvoiceArchiveManager $invoiceArchiveManager,
        private AccountingExportService $accountingExportService,
        private UserRepository $userRepository,
        private EntityManagerInterface $entityManager,
        private AuditLogger $auditLogger,
        private DunningWorkflow $dunningWorkflow,
        private InvoiceStatusUpdater $invoiceStatusUpdater,
        private AppSettingsService $settingsService,
        private BillingSettings $billingSettings,
        private InvoiceLayoutRenderer $layoutRenderer,
        private InvoiceDocumentService $documents,
        private InvoicePresenter $presenter,
        private InvoiceAdjustmentService $adjustments,
        private PaymentRecorder $payments,
        private CsrfTokenManagerInterface $csrf,
        private Environment $twig,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'admin_billing', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->actor($request);
        $invoices = $this->invoiceRepository->findRecent(100);
        $archives = $this->invoiceArchiveRepository->findArchivedInvoiceIds(array_map(static fn (Invoice $i) => $i->getId(), $invoices));

        return new Response($this->twig->render('admin/billing/index.html.twig', [
            'activeNav' => 'billing',
            'summary' => ['open' => $this->invoiceRepository->countByStatus(InvoiceStatus::Open), 'past_due' => $this->invoiceRepository->countByStatus(InvoiceStatus::PastDue), 'paid' => $this->invoiceRepository->countByStatus(InvoiceStatus::Paid)],
            'invoices' => array_map(fn (Invoice $i) => $this->presenter->present($i) + ['is_archived' => in_array($i->getId(), $archives, true)], $invoices),
            'credit_notes' => array_map(static fn ($c) => ['number' => $c->getNumber(), 'invoice_number' => $c->getInvoice()->getNumber(), 'customer' => $c->getInvoice()->getCustomer()->getEmail(), 'status' => $c->getStatus()->value, 'amount' => $c->getAmountCents(), 'currency' => $c->getCurrency(), 'issued_at' => $c->getIssuedAt()], $this->creditNoteRepository->findRecent()),
        ]));
    }

    #[Route('/invoices/new', name: 'admin_billing_invoice_new', methods: ['GET'])]
    public function newInvoice(Request $request): Response
    {
        $this->actor($request);

        return $this->form();
    }

    #[Route('/invoices', name: 'admin_billing_invoice_create', methods: ['POST'])]
    public function createInvoice(Request $request): Response
    {
        $actor = $this->actor($request, true);
        $data = $request->request->all();
        try {
            $customer = $this->userRepository->find((int) ($data['customer_id'] ?? 0));
            if (!$customer instanceof User || UserType::Customer !== $customer->getType()) {
                throw new \InvalidArgumentException('billing.invalid_customer');
            }
            $number = trim((string) ($data['number'] ?? ''));
            if (null !== $this->invoiceRepository->findOneBy(['number' => $number])) {
                throw new \InvalidArgumentException('billing.duplicate_number');
            }
            $rows = array_key_exists('items', $data) ? $data['items'] : [['description' => $data['description'] ?? '', 'quantity' => '1', 'unit_price' => $data['amount'] ?? '', 'tax_rate' => $data['tax_rate'] ?? '19.00']];
            $tax = InvoiceItems::calculate($rows, is_string($data['tax_mode'] ?? null) ? $data['tax_mode'] : 'standard');
            $tax['electronic_invoice'] = \App\Module\Core\Application\Billing\ElectronicInvoiceData::fromInput($data);
            $gross = $tax['gross_cents'];
            $due = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($data['due_date'] ?? ''));
            if (false === $due || $due->format('Y-m-d') !== ($data['due_date'] ?? '')) {
                throw new \InvalidArgumentException('billing.invalid_date');
            }
            $description = mb_substr(implode("\n", array_column($tax['items'], 'description')), 0, 2000);
            $invoice = new Invoice($customer, $number, $gross, strtoupper(trim((string) ($data['currency'] ?? 'EUR'))), $due);
            $this->layoutRenderer->snapshot($invoice, $request, $tax, $description);
            \App\Module\Core\Application\Billing\InvoicePdfRenderer::assertCapabilities($this->settingsService->getInvoiceLayout());
            $this->entityManager->wrapInTransaction(function () use ($invoice, $actor, $request): void {
                $this->entityManager->persist($invoice);
                $this->entityManager->flush();
                $this->auditLogger->log($actor, 'billing.invoice.created', ['invoice_id' => $invoice->getId(), 'number' => $invoice->getNumber(), 'amount_total_cents' => $invoice->getAmountTotalCents(), 'tax' => array_intersect_key($invoice->getBillingDetails(), array_flip(['tax_mode', 'tax_rate_bps', 'net_cents', 'tax_cents']))]);
                $this->invoiceArchiveManager->archiveInvoiceContent($invoice, $this->documents->generate($invoice, $request), preg_replace('/[^a-zA-Z0-9._-]/', '_', $invoice->getNumber()).'.pdf', 'application/pdf', $actor);
            });
        } catch (\InvalidArgumentException $exception) {
            return $this->form($data, [$this->message($exception)], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (UniqueConstraintViolationException) {
            return new Response($this->translator->trans('billing.duplicate_number', [], 'billing'), Response::HTTP_CONFLICT);
        }

        return $this->redirect($invoice);
    }

    #[Route('/dunning', name: 'admin_billing_dunning_run', methods: ['POST'])]
    public function runDunning(Request $request): Response
    {
        $actor = $this->actor($request, true);
        $now = new \DateTimeImmutable();
        foreach ($this->invoiceRepository->findDunnable($now) as $invoice) {
            $this->invoiceStatusUpdater->syncStatus($invoice, $actor, $now);
            $this->entityManager->flush();
            $this->dunningWorkflow->apply($invoice, $actor, $now);
        }
        $this->entityManager->flush();

        return new RedirectResponse('/admin/billing', Response::HTTP_SEE_OTHER);
    }

    #[Route('/invoices/{id<\d+>}', name: 'admin_billing_invoice_show', methods: ['GET'])]
    public function showInvoice(Request $request, int $id): Response
    {
        $this->actor($request);

        return $this->detail($request, $this->invoice($id));
    }

    #[Route('/invoices/{id<\d+>}/mark-paid', name: 'admin_billing_invoice_mark_paid', methods: ['POST'])]
    public function markInvoicePaid(Request $request, int $id): Response
    {
        $actor = $this->actor($request, true);
        $invoice = $this->invoice($id);
        if (InvoiceStatus::Cancelled === $invoice->getStatus()) {
            return $this->detail($request, $invoice, [$this->translator->trans('billing.cancelled_invoice', [], 'billing')], Response::HTTP_CONFLICT);
        }
        $this->entityManager->wrapInTransaction(function () use ($invoice, $actor): void {
            $this->entityManager->refresh($invoice, LockMode::PESSIMISTIC_WRITE);
            if ($invoice->getOutstandingCents() > 0) {
                $this->payments->record($invoice, 'manual', 'MANUAL-'.bin2hex(random_bytes(12)), $invoice->getOutstandingCents(), $invoice->getCurrency(), PaymentStatus::Succeeded, $actor, new \DateTimeImmutable());
            }
        });

        return $this->redirect($invoice);
    }

    #[Route('/invoices/{id<\d+>}/mark-unpaid', name: 'admin_billing_invoice_mark_unpaid', methods: ['POST'])]
    public function markInvoiceUnpaid(Request $request, int $id): Response
    {
        $actor = $this->actor($request, true);
        $invoice = $this->invoice($id);
        try {
            $this->entityManager->wrapInTransaction(function () use ($invoice, $actor): void {
                $this->entityManager->refresh($invoice, LockMode::PESSIMISTIC_WRITE);
                if (InvoiceStatus::Cancelled === $invoice->getStatus()) {
                    throw new \DomainException('billing.cancelled_invoice');
                }
                foreach ($invoice->getPayments() as $payment) {
                    if (PaymentStatus::Succeeded === $payment->getStatus() && 'manual' !== $payment->getProvider()) {
                        throw new \DomainException('billing.external_payment');
                    }
                }
                foreach ($invoice->getPayments() as $payment) {
                    if ('manual' === $payment->getProvider() && PaymentStatus::Succeeded === $payment->getStatus()) {
                        $payment->setStatus(PaymentStatus::Failed);
                    }
                }
                $this->invoiceStatusUpdater->syncStatus($invoice, $actor);
                $this->auditLogger->log($actor, 'billing.payment.manual_reversed', ['invoice_id' => $invoice->getId()]);
            });
        } catch (\DomainException $exception) {
            return $this->detail($request, $invoice, [$this->message($exception)], Response::HTTP_CONFLICT);
        }

        return $this->redirect($invoice);
    }

    #[Route('/invoices/{id<\d+>}/credit', name: 'admin_billing_invoice_credit', methods: ['POST'])]
    public function credit(Request $request, int $id): Response
    {
        $actor = $this->actor($request, true);
        $invoice = $this->invoice($id);
        try {
            $this->adjustments->credit($invoice, BillingAmount::parse((string) $request->request->get('amount')), (string) $request->request->get('reason'), $actor);
        } catch (\InvalidArgumentException|\DomainException $exception) {
            return $this->detail($request, $invoice, [$this->message($exception)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->redirect($invoice);
    }

    #[Route('/invoices/{id<\d+>}/cancel', name: 'admin_billing_invoice_cancel', methods: ['POST'])]
    public function cancel(Request $request, int $id): Response
    {
        $actor = $this->actor($request, true);
        $invoice = $this->invoice($id);
        try {
            $this->adjustments->cancel($invoice, (string) $request->request->get('reason'), $actor);
        } catch (\InvalidArgumentException|\DomainException $exception) {
            return $this->detail($request, $invoice, [$this->message($exception)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->redirect($invoice);
    }

    #[Route('/invoices/{id<\d+>}/pdf', name: 'admin_billing_invoice_pdf', methods: ['GET'])]
    public function pdf(Request $request, int $id): Response
    {
        $this->actor($request);

        return $this->documents->download($this->invoice($id), $request);
    }

    #[Route('/invoices/{id<\d+>}/archive/generate', name: 'admin_billing_invoice_archive_generate', methods: ['POST'])]
    public function generateArchive(Request $request, int $id): Response
    {
        $actor = $this->actor($request, true);
        $invoice = $this->invoice($id);
        if (null !== $this->invoiceArchiveRepository->findOneBy(['invoice' => $invoice])) {
            return $this->detail($request, $invoice, [$this->translator->trans('billing.already_archived', [], 'billing')], Response::HTTP_CONFLICT);
        }
        try {
            $this->invoiceArchiveManager->archiveInvoiceContent($invoice, $this->documents->generate($invoice, $request), preg_replace('/[^a-zA-Z0-9._-]/', '_', $invoice->getNumber()).'.pdf', 'application/pdf', $actor);
        } catch (\InvalidArgumentException $exception) {
            return $this->detail($request, $invoice, [$this->message($exception)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->redirect($invoice);
    }

    #[Route('/invoices/{id<\d+>}/archive', name: 'admin_billing_invoice_archive', methods: ['POST'])]
    public function archiveInvoice(Request $request, int $id): Response
    {
        $actor = $this->actor($request, true);
        $invoice = $this->invoice($id);
        $file = $request->files->get('invoice_pdf');
        if (null !== $this->invoiceArchiveRepository->findOneBy(['invoice' => $invoice]) || !$file instanceof UploadedFile || !$file->isValid() || ($file->getSize() ?? 0) > InvoiceArchiveManager::MAX_FILE_SIZE || 'application/pdf' !== $file->getMimeType() || '%PDF-' !== file_get_contents($file->getPathname(), false, null, 0, 5)) {
            return $this->detail($request, $invoice, [$this->translator->trans('billing.invalid_pdf', [], 'billing')], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->invoiceArchiveManager->archiveInvoice($invoice, $file, $actor);

        return $this->redirect($invoice);
    }

    #[Route('/invoices/{id<\d+>}/archive/download', name: 'admin_billing_invoice_archive_download', methods: ['GET'])]
    public function downloadArchive(Request $request, int $id): Response
    {
        $this->actor($request);
        $archive = $this->invoiceArchiveRepository->findOneBy(['invoice' => $this->invoice($id)]);
        if (null === $archive) {
            throw new NotFoundHttpException();
        }
        $name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $archive->getFileName());

        return new Response($archive->getPdfContents(), Response::HTTP_OK, ['Content-Type' => $archive->getContentType(), 'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $name ?: 'invoice.pdf'), 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    #[Route('/layout', name: 'admin_billing_layout_update', methods: ['POST'])]
    public function updateLayout(Request $request): Response
    {
        $actor = $this->actor($request, true);
        try {
            $layout = (string) $request->request->get('invoice_layout', '');
            $this->layoutRenderer->validate($layout);
            $this->settingsService->updateSettings([AppSettingsService::KEY_INVOICE_LAYOUT => $layout]);
            $this->auditLogger->log($actor, 'billing.layout.updated', []);
            $this->entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return new Response($this->message($exception), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $id = (int) $request->request->get('invoice_id', 0);

        return new RedirectResponse($id > 0 ? '/admin/billing/invoices/'.$id : '/admin/billing', Response::HTTP_SEE_OTHER);
    }

    #[Route('/settings', name: 'admin_billing_settings', methods: ['GET', 'POST'])]
    public function settings(Request $request): Response
    {
        $actor = $this->actor($request, $request->isMethod('POST'));
        $errors = [];
        if ($request->isMethod('POST')) {
            try {
                $this->billingSettings->save($request->request->all('options'));
                $this->auditLogger->log($actor, 'billing.settings.updated', []);
                $this->entityManager->flush();

                return new RedirectResponse('/admin/billing/settings', Response::HTTP_SEE_OTHER);
            } catch (\InvalidArgumentException $exception) {
                $errors[] = $this->message($exception);
            }
        }

        return new Response($this->twig->render('admin/billing/settings.html.twig', ['activeNav' => 'billing', 'options' => $this->billingSettings->get(), 'errors' => $errors]), [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/export', name: 'admin_billing_export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        $this->actor($request);
        $yearRaw = $request->query->get('year');
        $year = null === $yearRaw || '' === $yearRaw ? null : filter_var($yearRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1900, 'max_range' => 9999]]);
        $type = (string) $request->query->get('type', 'invoices');
        if (false === $year || !in_array($type, ['invoices', 'payments'], true)) {
            return new Response($this->translator->trans('billing.invalid_export', [], 'billing'), Response::HTTP_BAD_REQUEST);
        }
        if ('payments' === $type) {
            $csv = $this->accountingExportService->exportPayments($this->paymentRepository->findForExport($year));
        } else {
            $invoices = $this->invoiceRepository->findForExport($year);
            $ids = array_map(static fn (Invoice $i) => $i->getId(), $invoices);
            $csv = $this->accountingExportService->exportInvoices($invoices, $this->invoiceArchiveRepository->findArchiveMetadataByInvoiceIds($ids));
        }

        return new Response($csv, Response::HTTP_OK, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $type.(null === $year ? '' : '-'.$year).'.csv'), 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function actor(Request $request, bool $write = false): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin() || ($write && !$this->csrf->isTokenValid(new CsrfToken('billing', (string) $request->request->get('_token'))))) {
            throw new AccessDeniedHttpException();
        }

        return $actor;
    }

    private function invoice(int $id): Invoice
    {
        $invoice = $this->invoiceRepository->find($id);
        if (!$invoice instanceof Invoice) {
            throw new NotFoundHttpException();
        }

        return $invoice;
    }

    private function redirect(Invoice $invoice): Response
    {
        return new RedirectResponse('/admin/billing/invoices/'.$invoice->getId(), Response::HTTP_SEE_OTHER);
    }

    private function message(\Throwable $exception): string
    {
        return $this->translator->trans($exception->getMessage(), [], 'billing');
    }

    private function form(array $data = [], array $errors = [], int $status = Response::HTTP_OK): Response
    {
        $options = $this->billingSettings->get();
        $data += ['customer_id' => null, 'number' => 'INV-'.date('Ymd').'-'.bin2hex(random_bytes(6)), 'amount' => '', 'currency' => 'EUR', 'due_date' => date('Y-m-d', strtotime('+14 days')), 'tax_mode' => $options['tax_mode'], 'tax_rate' => $options['tax_rate'], 'description' => ''];
        $data['items'] ??= [['description' => $data['description'], 'quantity' => '1', 'unit_price' => $data['amount'], 'tax_rate' => $data['tax_rate']]];
        if (!is_array($data['items'])) {
            $data['items'] = [];
        }
        $data['items'] = array_slice(array_values($data['items']), 0, InvoiceItems::MAX_ITEMS);
        foreach ($data['items'] as &$row) {
            $row = is_array($row) ? $row : [];
            foreach (['description', 'quantity', 'unit_price', 'tax_rate'] as $key) {
                $row[$key] = is_string($row[$key] ?? null) ? $row[$key] : '';
            }
        } unset($row);
        $data['errors'] = $errors;

        return new Response($this->twig->render('admin/billing/create.html.twig', ['activeNav' => 'billing', 'customers' => $this->userRepository->findCustomers(), 'form' => $data]), $status);
    }

    private function detail(Request $request, Invoice $invoice, array $errors = [], int $status = Response::HTTP_OK): Response
    {
        $archive = $this->invoiceArchiveRepository->findOneBy(['invoice' => $invoice]);
        $layout = $this->settingsService->getInvoiceLayout();
        try {
            $preview = InvoiceLayoutRenderer::previewDocument($this->layoutRenderer->render($invoice, $request, $layout));
        } catch (\InvalidArgumentException $exception) {
            $preview = '';
            $errors[] = $this->message($exception);
        }

        return new Response($this->twig->render('admin/billing/invoice.html.twig', [
            'activeNav' => 'billing', 'invoice' => $this->presenter->present($invoice),
            'archive' => null === $archive ? ['is_archived' => false] : ['is_archived' => true, 'file_name' => $archive->getFileName(), 'file_size' => $archive->getFileSize(), 'content_type' => $archive->getContentType(), 'archived_year' => $archive->getArchivedYear(), 'pdf_hash' => $archive->getPdfHash(), 'created_at' => $archive->getCreatedAt()],
            'archive_errors' => $errors, 'invoice_layout' => $layout, 'invoice_preview' => $preview,
        ]), $status);
    }
}
