<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Customer;

use App\Infrastructure\Http\RequestActor;
use App\Module\Core\Application\Billing\BillingAmount;
use App\Module\Core\Application\Billing\BillingSettings;
use App\Module\Core\Application\Billing\PaymentProviderRegistry;
use App\Module\Core\Application\Billing\PaymentSettings;
use App\Module\Core\Application\Billing\WalletPaymentProviderInterface;
use App\Module\Core\Application\Contracts\WalletFunds;
use App\Module\Core\Domain\Entity\PaymentCheckout;
use App\Module\Core\Domain\Entity\RentalAccount;
use App\Module\Core\Domain\Entity\ShopContract;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\WalletEntry;
use App\Module\Core\Domain\Entity\WalletTopup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final readonly class CustomerWalletController
{
    public function __construct(private EntityManagerInterface $em, private WalletFunds $funds, private PaymentProviderRegistry $providers, private PaymentSettings $settings, private BillingSettings $billing, private CsrfTokenManagerInterface $csrf, private Environment $twig, private \Psr\Log\LoggerInterface $logger)
    {
    }

    #[Route('/marketplace/wallet', name: 'customer_wallet', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $customer = RequestActor::user($request);
        if (!$customer instanceof User || !$customer->isCustomer()) {
            return new Response('Forbidden.', 403);
        }
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->csrf->isTokenValid(new CsrfToken('wallet', $request->request->getString('_token')))) {
                return new Response('Invalid CSRF token.', 403);
            }
            try {
                $topup = $this->funds->request($customer, BillingAmount::parse($request->request->getString('amount')), $request->request->getString('nonce'), $request->request->getString('currency'));

                return new RedirectResponse('/marketplace/wallet/'.$topup->getId(), 303);
            } catch (\DomainException|\InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        }
        $page = max(1, min(10000, $request->query->getInt('page', 1)));
        /** @var list<WalletEntry> $entries */
        $entries = $this->em->getRepository(WalletEntry::class)->createQueryBuilder('e')->join('e.account', 'a')->where('a.customer = :customer')->setParameter('customer', $customer->getId(), \Doctrine\DBAL\Types\Types::INTEGER)->orderBy('e.id', 'DESC')->setFirstResult(($page - 1) * 50)->setMaxResults(50)->getQuery()->getResult();
        /** @var list<WalletTopup> $topups */
        $topups = $this->em->getRepository(WalletTopup::class)->createQueryBuilder('t')->join('t.account', 'a')->where('a.customer = :customer')->setParameter('customer', $customer->getId(), \Doctrine\DBAL\Types\Types::INTEGER)->orderBy('t.createdAt', 'DESC')->setMaxResults(25)->getQuery()->getResult();
        /** @var list<ShopContract> $pending */
        $pending = $this->em->getRepository(ShopContract::class)->createQueryBuilder('c')->join('c.account', 'a')->where("a.customer = :customer AND c.billingMode = 'wallet' AND c.pendingUnits > 0")->setParameter('customer', $customer->getId(), \Doctrine\DBAL\Types\Types::INTEGER)->orderBy('c.id')->getQuery()->getResult();

        return new Response($this->twig->render('shop/contracts/wallet.html.twig', ['accounts' => $this->em->getRepository(RentalAccount::class)->findBy(['customer' => $customer]), 'entries' => $entries, 'topups' => $topups, 'pending' => $pending, 'error' => $error, 'nonce' => bin2hex(random_bytes(16)), 'page' => $page, 'currency' => $this->settings->publicSettings()['currency'], 'activeNav' => 'contracts']), null !== $error ? 422 : 200);
    }

    #[Route('/marketplace/wallet/{id}', name: 'customer_wallet_topup', requirements: ['id' => '[a-f0-9]{32}'], methods: ['GET', 'POST'])]
    public function topup(Request $request, string $id): Response
    {
        $actor = RequestActor::user($request);
        $topup = $this->em->find(WalletTopup::class, $id);
        if (null === $actor || !$actor->isCustomer() || null === $topup || $topup->getAccount()->getCustomer()->getId() !== $actor->getId()) {
            return new Response('Not found.', 404);
        }
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->csrf->isTokenValid(new CsrfToken('wallet-pay-'.$id, $request->request->getString('_token')))) {
                return new Response('Invalid CSRF token.', 403);
            }
            try {
                $method = $request->request->getString('provider');
                if (!in_array($method, $this->settings->methods(), true)) {
                    throw new \DomainException('Diese Zahlungsart ist nicht verfügbar.');
                }
                if (null !== $topup->getPaidAt()) {
                    return new RedirectResponse('/marketplace/wallet/'.$id, 303);
                }
                if ('bank_transfer' === $method) {
                    $existing = $this->em->getRepository(PaymentCheckout::class)->findOneBy(['topup' => $topup, 'provider' => 'manual']);
                    if (null === $existing) {
                        $this->em->persist(new PaymentCheckout($topup, 'manual', $topup->getNumber(), $topup->getAmountCents(), $topup->getCurrency()));
                        $this->em->flush();
                    }

                    return new RedirectResponse('/marketplace/wallet/'.$id.'?bank=1', 303);
                }
                $provider = $this->providers->get($method);
                if (!$provider instanceof WalletPaymentProviderInterface) {
                    throw new \DomainException('Diese Zahlungsart unterstützt keine Guthabenaufladung.');
                }
                $instruction = $provider->createWalletPaymentIntent($topup, $method);
                if (null !== $instruction->redirectUrl) {
                    return new RedirectResponse($instruction->redirectUrl, 303);
                }
            } catch (\Throwable $e) {
                $this->logger->error('wallet.checkout.failed', ['topup_id' => $topup->getId(), 'exception_type' => $e::class]);
                $error = 'Die Zahlung konnte nicht gestartet werden. Bitte später erneut versuchen oder Überweisung verwenden.';
            }
        }

        return new Response($this->twig->render('shop/contracts/topup.html.twig', ['topup' => $topup, 'methods' => $this->settings->methods(), 'billing' => $this->billing->get(), 'error' => $error, 'activeNav' => 'contracts']), null !== $error ? 422 : 200);
    }
}
