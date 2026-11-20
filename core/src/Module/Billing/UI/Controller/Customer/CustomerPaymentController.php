<?php

declare(strict_types=1);

namespace App\Module\Billing\UI\Controller\Customer;

use App\Infrastructure\Http\RequestActor;
use App\Module\Core\Application\Billing\PaymentProviderRegistry;
use App\Module\Core\Application\Billing\PaymentSettings;
use App\Module\Core\Application\Billing\StripePaymentProvider;
use App\Module\Core\Attribute\RequiresModule;
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
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route(path: '/customer/billing')]
#[RequiresModule('billing')]
final class CustomerPaymentController
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly InvoicePreferencesRepository $invoicePreferencesRepository,
        private readonly PaymentProviderRegistry $providerRegistry,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly ?PaymentSettings $paymentSettings = null,
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
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }
        if (!$this->csrf->isTokenValid(new CsrfToken('billing', (string) $request->request->get('_token')))) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        $invoice = $this->invoiceRepository->find($id);
        if (!$invoice instanceof Invoice || $invoice->getCustomer()->getId() !== $customer->getId()) {
            return new Response('Invoice not found.', Response::HTTP_NOT_FOUND);
        }

        if (InvoiceStatus::Open !== $invoice->getStatus() && InvoiceStatus::PastDue !== $invoice->getStatus()) {
            return new RedirectResponse('/customer/billing');
        }

        $preferences = $this->invoicePreferencesRepository->findOneByCustomer($customer);
        $providerName = $request->request->getString('provider', $preferences?->getDefaultPaymentMethod() ?? 'manual');
        if (null !== $this->paymentSettings && $this->paymentSettings->isConfigured() && !in_array('manual' === $providerName ? 'bank_transfer' : $providerName, $this->paymentSettings->methods(), true)) {
            return new Response('Payment method unavailable.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $provider = $this->providerRegistry->get($providerName);
        if (null === $provider) {
            $provider = $this->providerRegistry->get('manual');
        }

        if (null === $provider) {
            return new Response('No payment provider configured.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            $instruction = $provider instanceof StripePaymentProvider
                ? $provider->createPaymentIntent($invoice, $invoice->getOutstandingCents(), in_array($providerName, $provider->supportedMethods(), true) ? $providerName : null)
                : $provider->createPaymentIntent($invoice, $invoice->getOutstandingCents());
        } catch (\Throwable $e) {
            $this->logger->error('customer.payment.intent_failed', [
                'invoice_id' => $invoice->getId(),
                'provider' => $provider->getName(),
                'customer_id' => $customer->getId(),
                'exception_type' => $e::class,
            ]);

            return new RedirectResponse('/customer/billing');
        }

        $this->logger->info('customer.payment.intent_created', [
            'invoice_id' => $invoice->getId(),
            'provider' => $instruction->provider,
            'reference' => $instruction->reference,
            // Provider-specific values can contain secrets; log only their names.
            'metadata_keys' => array_keys($instruction->metadata),
        ]);
        if (null !== $instruction->redirectUrl) {
            return new RedirectResponse($instruction->redirectUrl);
        }

        return new RedirectResponse('/customer/billing/invoices/'.$id.('manual' === $provider->getName() ? '?payment=manual' : ''), Response::HTTP_SEE_OTHER);
    }

    private function requireCustomer(Request $request): ?User
    {
        $actor = RequestActor::user($request);
        if (!$actor instanceof User || UserType::Customer !== $actor->getType()) {
            return null;
        }

        return $actor;
    }
}
