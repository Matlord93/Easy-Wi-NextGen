<?php

declare(strict_types=1);

namespace App\Module\Billing\UI\Controller\Customer;

use App\Infrastructure\Http\RequestActor;

use App\Module\Core\Application\Billing\InvoiceDocumentService;
use App\Module\Core\Application\Billing\InvoicePresenter;
use App\Module\Core\Attribute\RequiresModule;
use App\Module\Core\Domain\Entity\Invoice;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\InvoiceRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route('/customer/billing')]
#[RequiresModule('billing')]
final readonly class CustomerInvoiceController
{
    public function __construct(private InvoiceRepository $invoices, private InvoicePresenter $presenter, private InvoiceDocumentService $documents, private Environment $twig)
    {
    }

    #[Route('', name: 'customer_billing', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $customer = $this->customer($request);

        return new Response($this->twig->render('customer/billing/index.html.twig', [
            'activeNav' => 'billing',
            'invoices' => array_map($this->presenter->present(...), $this->invoices->findByCustomer($customer)),
        ]));
    }

    #[Route('/invoices/{id<\d+>}', name: 'customer_billing_invoice', methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        return new Response($this->twig->render('customer/billing/invoice.html.twig', [
            'activeNav' => 'billing', 'invoice' => $this->presenter->present($this->invoice($request, $id)),
            'manual_payment' => $request->query->get('payment') === 'manual',
        ]));
    }

    #[Route('/invoices/{id<\d+>}/pdf', name: 'customer_billing_invoice_pdf', methods: ['GET'])]
    public function pdf(Request $request, int $id): Response
    {
        return $this->documents->download($this->invoice($request, $id), $request);
    }

    private function customer(Request $request): User
    {
        $user = RequestActor::user($request);
        if (!$user instanceof User || $user->getType() !== UserType::Customer) {
            throw new AccessDeniedHttpException();
        }

        return $user;
    }

    private function invoice(Request $request, int $id): Invoice
    {
        $customer = $this->customer($request);
        $invoice = $this->invoices->find($id);
        if (!$invoice instanceof Invoice || $invoice->getCustomer()->getId() !== $customer->getId()) {
            throw new NotFoundHttpException();
        }

        return $invoice;
    }
}
