<?php

declare(strict_types=1);

namespace App\Module\Billing\UI\Controller;

use App\Module\Core\Application\Billing\ElectronicInvoiceData;
use App\Module\Core\Application\Billing\ElectronicInvoiceService;
use App\Module\Core\Application\Billing\ElectronicInvoiceValidator;
use App\Module\Core\Attribute\RequiresModule;
use App\Module\Core\Domain\Entity\Invoice;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\InvoiceRepository;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

#[RequiresModule('billing')]
final readonly class ElectronicInvoiceController
{
    public function __construct(private InvoiceRepository $invoices, private ElectronicInvoiceService $service, private ElectronicInvoiceValidator $validator, private CsrfTokenManagerInterface $csrf, private Environment $twig)
    {
    }

    #[Route('/admin/billing/invoices/{id<\d+>}/electronic', name: 'admin_billing_electronic', methods: ['GET'])]
    #[Route('/customer/billing/invoices/{id<\d+>}/electronic', name: 'customer_billing_electronic', methods: ['GET'])]
    public function overview(Request $request, int $id): Response
    {
        return $this->page($request, $this->invoice($request, $id));
    }

    #[Route('/admin/billing/invoices/{id<\d+>}/electronic/{format<xrechnung|zugferd>}', name: 'admin_billing_electronic_generate', methods: ['POST'])]
    public function generate(Request $request, int $id, string $format): Response
    {
        $invoice = $this->invoice($request, $id);
        if (!$this->csrf->isTokenValid(new CsrfToken('einvoice-'.$id.'-'.$format, (string) $request->request->get('_token')))) {
            throw new AccessDeniedHttpException();
        }
        try {
            $this->service->generate($invoice, $format, $request->attributes->get('current_user'));
        } catch (\InvalidArgumentException $exception) {
            return $this->page($request, $invoice, $this->safeError($exception), 422);
        } catch (\RuntimeException $exception) {
            return $this->page($request, $invoice, $this->safeError($exception), 503);
        }

        return new RedirectResponse('/admin/billing/invoices/'.$id.'/electronic', Response::HTTP_SEE_OTHER);
    }

    #[Route('/admin/billing/invoices/{id<\d+>}/electronic/{format<xrechnung|zugferd>}', name: 'admin_billing_electronic_download', methods: ['GET'])]
    #[Route('/customer/billing/invoices/{id<\d+>}/electronic/{format<xrechnung|zugferd>}', name: 'customer_billing_electronic_download', methods: ['GET'])]
    public function download(Request $request, int $id, string $format): Response
    {
        $invoice = $this->invoice($request, $id);
        foreach ($this->service->archives($invoice) as $archive) {
            if ($format !== $archive->getFormat()) {
                continue;
            }
            $name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $invoice->getNumber()).'-'.$format.('zugferd' === $format ? '.pdf' : '.xml');

            return new Response($archive->getContents(), 200, ['Content-Type' => 'zugferd' === $format ? 'application/pdf' : 'application/xml; charset=UTF-8', 'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $name), 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Content-SHA256' => $archive->getSha256()]);
        }
        throw new NotFoundHttpException();
    }

    private function invoice(Request $request, int $id): Invoice
    {
        $user = $request->attributes->get('current_user');
        $admin = str_starts_with($request->getPathInfo(), '/admin/');
        if (!$user instanceof User || ($admin ? !$user->isAdmin() : UserType::Customer !== $user->getType())) {
            throw new AccessDeniedHttpException();
        }
        $invoice = $this->invoices->find($id);
        if (!$invoice instanceof Invoice || (!$admin && $invoice->getCustomer()->getId() !== $user->getId())) {
            throw new NotFoundHttpException();
        }

        return $invoice;
    }

    private function safeError(\Throwable $exception): string
    {
        return preg_match('/^billing\.einvoice_[a-z_]+$/D', $exception->getMessage()) ? $exception->getMessage() : 'billing.einvoice_validation_unavailable';
    }

    private function page(Request $request, Invoice $invoice, ?string $error = null, int $status = 200): Response
    {
        $admin = str_starts_with($request->getPathInfo(), '/admin/');

        return new Response($this->twig->render('billing/electronic.html.twig', ['activeNav' => 'billing', 'invoice' => $invoice, 'admin' => $admin, 'archives' => $this->service->archives($invoice), 'issues' => ['xrechnung' => $admin ? ElectronicInvoiceData::issues($invoice->getBillingDetails()) : [], 'zugferd' => $admin ? ElectronicInvoiceData::issues($invoice->getBillingDetails(), 'zugferd') : []], 'ready' => ['xrechnung' => $this->validator->ready('xrechnung'), 'zugferd' => $this->validator->ready('zugferd')], 'error' => $error]), $status, ['Cache-Control' => 'private, no-store']);
    }
}
