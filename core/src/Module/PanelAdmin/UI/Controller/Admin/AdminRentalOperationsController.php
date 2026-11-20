<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Infrastructure\Http\RequestActor;
use App\Module\Core\Application\AuditLoggerInterface;
use App\Module\Core\Application\Billing\BillingAmount;
use App\Module\Core\Application\Contracts\RentalAccounts;
use App\Module\Core\Application\Contracts\RentalCapacity;
use App\Module\Core\Application\Contracts\RentalOverview;
use App\Module\Core\Application\Contracts\RentalSettings;
use App\Module\Core\Application\Contracts\WalletFunds;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\AppSetting;
use App\Module\Core\Domain\Entity\PaymentCheckout;
use App\Module\Core\Domain\Entity\RentalAccount;
use App\Module\Core\Domain\Entity\RentalCapacityPool;
use App\Module\Core\Domain\Entity\ShopContract;
use App\Module\Core\Domain\Entity\Ts3Node;
use App\Module\Core\Domain\Entity\Ts6Node;
use App\Module\Core\Domain\Entity\WalletTopup;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final readonly class AdminRentalOperationsController
{
    public function __construct(private EntityManagerInterface $em, private RentalSettings $settings, private RentalCapacity $capacity, private RentalOverview $overview, private RentalAccounts $accounts, private WalletFunds $funds, private AuditLoggerInterface $audit, private CsrfTokenManagerInterface $csrf, private Environment $twig)
    {
    }

    #[Route('/admin/shop/operations', name: 'admin_rental_operations', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $actor = RequestActor::user($request);
        if (null === $actor || !$actor->isAdmin()) {
            return new Response('Forbidden.', 403);
        }
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->csrf->isTokenValid(new CsrfToken('rental-operations', $request->request->getString('_token')))) {
                return new Response('Invalid CSRF token.', 403);
            }
            try {
                $action = $request->request->getString('action');
                if ('settings' === $action) {
                    $input = [];
                    foreach (array_keys($this->settings->get()) as $key) {
                        $input[$key] = 'cleanup_enabled' === $key ? $request->request->getBoolean($key) : $request->request->getInt($key);
                    }
                    $this->settings->save($input);
                    $this->audit->log($actor, 'rental.settings.changed', $input);
                } elseif ('capacity' === $action) {
                    $key = $request->request->getString('pool');
                    $allowed = [];
                    foreach ($this->em->getRepository(Agent::class)->findAll() as $node) {
                        $allowed[] = 'agent:'.$node->getId();
                    }
                    foreach ($this->em->getRepository(Ts3Node::class)->findAll() as $node) {
                        $allowed[] = 'teamspeak3:'.($node->getId() ?? 0);
                    }
                    foreach ($this->em->getRepository(Ts6Node::class)->findAll() as $node) {
                        $allowed[] = 'teamspeak6:'.($node->getId() ?? 0);
                    }
                    if (!in_array($key, $allowed, true)) {
                        throw new \InvalidArgumentException('Unbekanntes Kapazitätsziel.');
                    }
                    $limits = [];
                    foreach (['cpu', 'ram_mib', 'disk_mib', 'ports', 'ipv4', 'slots', 'systems'] as $resource) {
                        $limits[$resource] = $request->request->getInt($resource);
                    }
                    $this->em->wrapInTransaction(function () use ($key, $limits, $request): void {
                        $pool = $this->em->find(RentalCapacityPool::class, $key, LockMode::PESSIMISTIC_WRITE) ?? new RentalCapacityPool($key, $limits);
                        $pool->configure($limits, $request->request->getBoolean('enabled'));
                        $this->em->persist($pool);
                        $this->em->flush();
                        $this->capacity->adoptExisting($pool);
                    });
                    $this->audit->log($actor, 'rental.capacity.changed', ['pool' => $key, 'limits' => $limits]);
                } elseif ('bank-confirm' === $action) {
                    $topup = $this->em->find(WalletTopup::class, $request->request->getString('topup_id'));
                    if (null === $topup || BillingAmount::parse($request->request->getString('amount')) !== $topup->getAmountCents()) {
                        throw new \InvalidArgumentException('Aufladung und tatsächlich eingegangenen Betrag prüfen.');
                    }
                    $checkout = $this->em->getRepository(PaymentCheckout::class)->findOneBy(['topup' => $topup, 'provider' => 'manual']);
                    if (null === $checkout) {
                        $checkout = new PaymentCheckout($topup, 'manual', $topup->getNumber(), $topup->getAmountCents(), $topup->getCurrency());
                        $this->em->persist($checkout);
                        $this->em->flush();
                    }
                    $this->funds->confirm($checkout, trim($request->request->getString('reference')), $actor);
                } elseif ('adjust' === $action) {
                    $account = $this->em->find(RentalAccount::class, $request->request->getInt('account_id'));
                    if (null === $account) {
                        throw new \InvalidArgumentException('Konto nicht gefunden.');
                    }
                    $amount = BillingAmount::parse($request->request->getString('amount'));
                    $debit = $request->request->getBoolean('debit');
                    $this->accounts->adjust($account, $actor, $debit ? -$amount : $amount, trim($request->request->getString('reference')), trim($request->request->getString('reason')), $request->request->getBoolean('refund'));
                } elseif (in_array($action, ['retry', 'hold', 'unhold'], true)) {
                    $contract = $this->em->find(ShopContract::class, $request->request->getInt('contract_id'));
                    if (null === $contract) {
                        throw new \InvalidArgumentException('Vertrag nicht gefunden.');
                    }
                    $this->em->wrapInTransaction(function () use ($contract, $action): void {
                        $this->em->refresh($contract->getAccount(), LockMode::PESSIMISTIC_WRITE);
                        $this->em->refresh($contract, LockMode::PESSIMISTIC_WRITE);
                        if ('retry' === $action) {
                            $contract->retryOperations();
                        } else {
                            $contract->retain('hold' === $action);
                        }
                    });
                    $this->audit->log($actor, 'rental.operation.'.$action, ['contract_id' => $contract->getId()]);
                } else {
                    throw new \InvalidArgumentException('Unbekannte Aktion.');
                }
                $this->em->flush();

                return new RedirectResponse('/admin/shop/operations?saved=1', 303);
            } catch (\DomainException|\InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        }
        $pools = [];
        foreach ($this->em->getRepository(RentalCapacityPool::class)->findAll() as $pool) {
            $pools[] = ['pool' => $pool, 'available' => $this->capacity->available($pool)];
        }
        $targets = [];
        foreach ($this->em->getRepository(Agent::class)->findAll() as $node) {
            $targets['agent:'.$node->getId()] = 'Agent '.($node->getName() ?? $node->getId());
        }
        foreach ($this->em->getRepository(Ts3Node::class)->findAll() as $node) {
            $targets['teamspeak3:'.($node->getId() ?? 0)] = 'TS3 '.$node->getName();
        }
        foreach ($this->em->getRepository(Ts6Node::class)->findAll() as $node) {
            $targets['teamspeak6:'.($node->getId() ?? 0)] = 'TS6 '.$node->getName();
        }
        /** @var list<ShopContract> $attention */
        $attention = $this->em->getRepository(ShopContract::class)->createQueryBuilder('c')->where('c.error IS NOT NULL OR c.retentionHold = true')->orderBy('c.updatedAt', 'DESC')->setMaxResults(100)->getQuery()->getResult();

        return new Response($this->twig->render('admin/shop/operations.html.twig', ['activeNav' => 'shop-contracts', 'settings' => $this->settings->get(), 'health' => $this->overview->health(), 'heartbeat' => $this->em->find(AppSetting::class, 'rental_last_success')?->getValue(), 'pools' => $pools, 'targets' => $targets, 'attention' => $attention, 'accounts' => $this->em->getRepository(RentalAccount::class)->findBy([], ['id' => 'DESC'], 100), 'topups' => $this->em->getRepository(WalletTopup::class)->findBy([], ['createdAt' => 'DESC'], 50), 'error' => $error]), null !== $error ? 422 : 200);
    }
}
