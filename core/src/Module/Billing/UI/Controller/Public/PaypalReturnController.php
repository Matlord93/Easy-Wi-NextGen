<?php

declare(strict_types=1);

namespace App\Module\Billing\UI\Controller\Public;

use App\Module\Core\Application\Billing\PaypalPaymentProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class PaypalReturnController
{
    public function __construct(private PaypalPaymentProvider $paypal, private LoggerInterface $logger, private \Doctrine\ORM\EntityManagerInterface $em)
    {
    }

    #[Route('/billing/paypal/return/{state}', name: 'payment_paypal_return', methods: ['GET'], requirements: ['state' => '[a-f0-9]{48}'])]
    public function complete(Request $request, string $state): RedirectResponse
    {
        try {
            $this->paypal->capture($state, $request->query->getString('token'));
        } catch (\Throwable $error) {
            $this->logger->warning('paypal.return.failed', ['exception_type' => $error::class]);

            return new RedirectResponse('/customer/billing?payment=unconfirmed', 303);
        }

        $topup = $this->em->find(\App\Module\Core\Domain\Entity\PaymentCheckout::class, $state)?->getTopup();

        return new RedirectResponse(null !== $topup ? '/marketplace/wallet/'.$topup->getId() : '/customer/billing?payment=confirmed', 303);
    }
}
