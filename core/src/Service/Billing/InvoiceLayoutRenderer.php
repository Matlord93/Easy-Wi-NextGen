<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\Invoice;
use App\Repository\CustomerProfileRepository;
use App\Service\SiteResolver;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

final class InvoiceLayoutRenderer
{
    public function __construct(
        private readonly Environment $twig,
        private readonly CustomerProfileRepository $profileRepository,
        private readonly SiteResolver $siteResolver,
    ) {
    }

    public function render(Invoice $invoice, Request $request, string $layout): string
    {
        $customer = $invoice->getCustomer();
        $profile = $this->profileRepository->findOneByCustomer($customer);

        $customerName = $profile?->getCompany()
            ?? trim(sprintf('%s %s', $profile?->getFirstName() ?? '', $profile?->getLastName() ?? ''))
            ?? $customer->getEmail();
        $customerName = trim($customerName) !== '' ? $customerName : $customer->getEmail();

        $site = $this->siteResolver->resolve($request);
        $companyName = $site?->getName() ?? 'Easy-Wi';
        $companyHost = $site?->getHost() ?? $request->getHost();

        $context = [
            'invoice' => [
                'id' => $invoice->getId(),
                'number' => $invoice->getNumber(),
                'status' => $invoice->getStatus()->value,
                'currency' => $invoice->getCurrency(),
                'amount_total' => $invoice->getAmountTotalCents(),
                'amount_due' => $invoice->getAmountDueCents(),
                'due_date' => $invoice->getDueDate(),
                'paid_at' => $invoice->getPaidAt(),
                'created_at' => $invoice->getCreatedAt(),
            ],
            'customer' => [
                'id' => $customer->getId(),
                'email' => $customer->getEmail(),
                'name' => $customerName,
                'company' => $profile?->getCompany(),
                'address' => $profile?->getAddress(),
                'postal' => $profile?->getPostal(),
                'city' => $profile?->getCity(),
                'country' => $profile?->getCountry(),
                'phone' => $profile?->getPhone(),
                'vat_id' => $profile?->getVatId(),
            ],
            'company' => [
                'name' => $companyName,
                'host' => $companyHost,
                'address' => '',
                'email' => '',
                'footer' => '',
            ],
        ];

        try {
            $template = $this->twig->createTemplate($layout);
            return $template->render($context);
        } catch (\Throwable $exception) {
            return sprintf(
                '<pre style="color:#b91c1c; white-space:pre-wrap;">Layout error: %s</pre>',
                htmlspecialchars($exception->getMessage(), ENT_QUOTES)
            );
        }
    }
}
