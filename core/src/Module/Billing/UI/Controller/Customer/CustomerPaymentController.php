<?php

declare(strict_types=1);

namespace App\Module\Billing\UI\Controller\Customer;

use App\Module\Core\Application\Billing\PaymentProviderRegistry;
use App\Module\Core\Domain\Entity\Invoice;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InvoiceStatus;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\InvoicePreferencesRepository;
use App\Repository\InvoiceRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Module\Core\Attribute\RequiresModule;

#[Route(path: '/customer/billing')]
#[RequiresModule('billing')]
final class CustomerPaymentController
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly InvoicePreferencesRepository $invoicePreferencesRepository,
        private readonly PaymentProviderRegistry $providerRegistry,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Initiate a payment for an open invoice.
     * The provider is taken from the customer's saved payment method preference.
     * On success: redirect to the provider's checkout URL.
     * On failure: redirect back to billing overview with an error flash.
     */
    #[Route(path: '/invoices/{id}/pay', name: 'customer_invoice_pay', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function pay(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        if (!$customer instanceof User) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $invoice = $this->invoiceRepository->find($id);
        if (!$invoice instanceof Invoice || $invoice->getCustomer()->getId() !== $customer->getId()) {
            return new Response('Invoice not found.', Response::HTTP_NOT_FOUND);
        }

        if ($invoice->getStatus() !== InvoiceStatus::Open && $invoice->getStatus() !== InvoiceStatus::PastDue) {
            return new RedirectResponse('/customer/profile#billing');
        }

        $preferences = $this->invoicePreferencesRepository->findOneByCustomer($customer);
        $providerName = $preferences?->getDefaultPaymentMethod() ?? 'manual';

        $provider = $this->providerRegistry->get($providerName);
        if ($provider === null) {
            $provider = $this->providerRegistry->get('manual');
        }

        if ($provider === null) {
            return new Response('No payment provider configured.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            $instruction = $provider->createPaymentIntent($invoice, $invoice->getAmountDueCents());
        } catch (\Throwable $e) {
            $this->logger->error('customer.payment.intent_failed', [
                'invoice_id'    => $invoice->getId(),
                'provider'      => $provider->getName(),
                'customer_id'   => $customer->getId(),
                'error'         => $e->getMessage(),
            ]);
            return new RedirectResponse('/customer/profile#billing');
        }

        if ($instruction->redirectUrl !== null) {
            return new RedirectResponse($instruction->redirectUrl);
        }

        return new RedirectResponse('/customer/profile#billing');
    }

    private function requireCustomer(Request $request): ?User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            return null;
        }

        return $actor;
    }
}
