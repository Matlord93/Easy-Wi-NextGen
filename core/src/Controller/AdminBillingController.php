<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\InvoiceStatus;
use App\Enum\UserType;
use App\Repository\CreditNoteRepository;
use App\Repository\InvoiceRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/billing')]
final class AdminBillingController
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly CreditNoteRepository $creditNoteRepository,
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
        $recentCreditNotes = $this->creditNoteRepository->findRecent();
        $summary = [
            'open' => $this->invoiceRepository->countByStatus(InvoiceStatus::Open),
            'past_due' => $this->invoiceRepository->countByStatus(InvoiceStatus::PastDue),
            'paid' => $this->invoiceRepository->countByStatus(InvoiceStatus::Paid),
        ];

        return new Response($this->twig->render('admin/billing/index.html.twig', [
            'activeNav' => 'billing',
            'summary' => $summary,
            'invoices' => $this->normalizeInvoices($recentInvoices),
            'credit_notes' => $this->normalizeCreditNotes($recentCreditNotes),
        ]));
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');
        return $actor instanceof User && $actor->getType() === UserType::Admin;
    }

    private function normalizeInvoices(array $invoices): array
    {
        return array_map(static function (\App\Entity\Invoice $invoice): array {
            return [
                'id' => $invoice->getId(),
                'number' => $invoice->getNumber(),
                'customer' => $invoice->getCustomer()->getEmail(),
                'status' => $invoice->getStatus()->value,
                'amount_due' => $invoice->getAmountDueCents(),
                'currency' => $invoice->getCurrency(),
                'due_date' => $invoice->getDueDate(),
                'paid_at' => $invoice->getPaidAt(),
            ];
        }, $invoices);
    }

    private function normalizeCreditNotes(array $creditNotes): array
    {
        return array_map(static function (\App\Entity\CreditNote $creditNote): array {
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
}
