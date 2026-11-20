<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Infrastructure\Http\RequestActor;
use App\Module\Core\Application\Billing\BillingAmount;
use App\Module\Core\Application\Contracts\RentalAccounts;
use App\Module\Core\Application\Contracts\RentalContracts;
use App\Module\Core\Domain\Entity\RentalAccount;
use App\Module\Core\Domain\Entity\RentalCredit;
use App\Module\Core\Domain\Entity\ShopContract;
use App\Repository\UserRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final readonly class AdminRentalController
{
    public function __construct(private EntityManagerInterface $em, private RentalAccounts $accounts, private RentalContracts $contracts, private UserRepositoryInterface $users, private CsrfTokenManagerInterface $csrf, private Environment $twig)
    {
    }

    #[Route('/admin/shop/contracts', name: 'admin_rental_contracts', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $actor = RequestActor::user($request);
        if (null === $actor || !$actor->isAdmin()) {
            return new Response('Forbidden.', 403);
        }
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->csrf->isTokenValid(new CsrfToken('rental-admin', $request->request->getString('_token')))) {
                return new Response('Invalid CSRF token.', 403);
            }
            try {
                $action = $request->request->getString('action');
                if ('credit' === $action || 'limit' === $action) {
                    $customer = $this->users->find($request->request->getInt('customer_id'));
                    $currency = $request->request->getString('currency', 'EUR');
                    if (null === $customer || !$customer->isCustomer() || !in_array($currency, ['EUR', 'USD', 'GBP', 'CHF', 'SEK', 'NOK', 'DKK', 'PLN'], true)) {
                        throw new \InvalidArgumentException('Kunde oder Währung ungültig.');
                    }
                    $account = $this->accounts->forCustomer($customer, $currency);
                    $amount = BillingAmount::parse($request->request->getString('amount'));
                    if ('credit' === $action) {
                        $this->accounts->credit($account, $actor, $amount, trim($request->request->getString('reference')), trim($request->request->getString('reason')));
                    } else {
                        $this->accounts->setLimit($account, $actor, $amount);
                    }
                } elseif ('domain-confirm' === $action || 'cancel' === $action) {
                    $contract = $this->em->find(ShopContract::class, $request->request->getInt('contract_id'));
                    if (null === $contract) {
                        return new Response('Contract not found.', 404);
                    }
                    if ('domain-confirm' === $action) {
                        if (($contract->getConfiguration()['registrar'] ?? '') === 'autodns') {
                            throw new \DomainException('AutoDNS-Aufträge über den Registrar-Abgleich bestätigen.');
                        }
                        $this->contracts->confirmDomain($contract, $actor, trim($request->request->getString('reference')), $request);
                    } else {
                        $this->contracts->cancel($contract, $actor, $request);
                    }
                } else {
                    throw new \InvalidArgumentException('Ungültige Aktion.');
                }

                return new RedirectResponse('/admin/shop/contracts?saved=1', 303);
            } catch (\DomainException|\InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            }
        }
        $page = max(1, min(4000, $request->query->getInt('page', 1)));

        return new Response($this->twig->render('admin/shop/contracts.html.twig', ['activeNav' => 'shop-contracts', 'contracts' => $this->em->getRepository(ShopContract::class)->findBy([], ['id' => 'DESC'], 25, ($page - 1) * 25), 'accounts' => $this->em->getRepository(RentalAccount::class)->findBy([], ['id' => 'DESC'], 100), 'credits' => $this->em->getRepository(RentalCredit::class)->findBy([], ['id' => 'DESC'], 50), 'customers' => $this->users->findCustomers(), 'error' => $error, 'page' => $page]), null !== $error ? 422 : 200);
    }
}
