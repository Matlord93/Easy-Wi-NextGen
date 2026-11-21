<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Contracts;

use App\Module\Core\Application\AuditLoggerInterface;
use App\Module\Core\Application\ModuleRegistry;
use App\Module\Core\Domain\Entity\Domain;
use App\Module\Core\Domain\Entity\RentalAccount;
use App\Module\Core\Domain\Entity\ShopContract;
use App\Module\Core\Domain\Entity\ShopCoupon;
use App\Module\Core\Domain\Entity\ShopDomainTerm;
use App\Module\Core\Domain\Entity\ShopOrder;
use App\Module\Core\Domain\Entity\ShopProduct;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\ShopOrderStatus;
use App\Module\Core\Domain\Enum\ShopOrderType;
use App\Module\Core\Domain\Enum\ShopProductKind;
use App\Module\Core\Domain\ValueObject\BillingPeriod;
use App\Module\Core\Domain\ValueObject\MinuteTariff;
use App\Repository\ShopOrderRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

final readonly class RentalContracts
{
    public function __construct(private EntityManagerInterface $em, private ContractQuote $quotes, private RentalAccounts $accounts, private ContractResources $resources, private ContractInvoices $invoices, private ShopOrderRepository $orders, private AuditLoggerInterface $audit, private ModuleRegistry $modules, private ContractJobs $jobs, private RentalCapacity $capacity, private RentalSettings $runtime, private RentalNotices $notices, private \App\Module\Core\Application\Registrar\DomainRegistrarQueue $registrar, private ProxmoxContractProvider $proxmox)
    {
    }

    /** @param array<string,string> $input */
    public function order(User $customer, ShopProduct $product, array $input, string $nonce, string $digest): ShopContract
    {
        if (!$customer->isCustomer() || !preg_match('/^[a-f0-9]{32}$/D', $nonce)) {
            throw new \InvalidArgumentException('Ungültige Vertragsbestellung.');
        }
        $quote = $this->quotes->build($product, $input, true);
        $module = match ($product->getKind()) {
            ShopProductKind::Gameserver => 'game', ShopProductKind::Teamspeak3 => 'ts', ShopProductKind::Teamspeak6 => 'ts6', ShopProductKind::Webspace => 'web', ShopProductKind::Proxmox => 'proxmox', default => null,
        };
        if (null !== $module && !$this->modules->isEnabled($module)) {
            throw new \DomainException('Dieses Produktmodul ist deaktiviert.');
        }
        $priorKey = hash('sha256', 'rental:'.($customer->getId() ?? 0).':'.($product->getId() ?? 0).':'.$nonce);
        $prior = $this->orders->findOneBy(['requestKey' => $priorKey, 'customer' => $customer])?->getContract();
        if (null !== $prior) {
            return $prior;
        }
        if (ShopProductKind::Proxmox === $product->getKind()) {
            $this->proxmox->validateQuote($quote);
        }
        $this->registrar->validate($customer, $quote);
        $account = $this->accounts->forCustomer($customer, (string) $quote['currency']);

        return $this->em->wrapInTransaction(function () use ($customer, $product, $input, $nonce, $digest, $account): ShopContract {
            $this->em->refresh($account, LockMode::PESSIMISTIC_WRITE);
            $key = hash('sha256', 'rental:'.($customer->getId() ?? 0).':'.($product->getId() ?? 0).':'.$nonce);
            $existing = $this->orders->findOneBy(['requestKey' => $key, 'customer' => $customer]);
            $previous = $existing?->getContract();
            if (null !== $previous) {
                return $previous;
            }
            $this->em->refresh($product, LockMode::PESSIMISTIC_READ);
            $code = strtoupper(trim($input['coupon'] ?? ''));
            $coupon = '' !== $code ? $this->em->getRepository(ShopCoupon::class)->findOneBy(['siteId' => $product->getSiteId(), 'code' => $code]) : null;
            if (null !== $coupon) {
                $this->em->refresh($coupon, LockMode::PESSIMISTIC_WRITE);
            }
            $quote = $this->quotes->build($product, $input, true);
            if (!hash_equals(ContractQuote::digest($quote), $digest)) {
                throw new \DomainException('Der Tarif hat sich geändert. Bitte den Vertrag erneut prüfen.');
            }
            if ($this->em->getRepository(ShopContract::class)->count(['account' => $account, 'status' => ['pending', 'provisioning', 'active', 'stopping']]) >= 20) {
                throw new \DomainException('Maximal 20 laufende oder offene Mietverträge pro Währung. Bitte den Betreiber kontaktieren.');
            }
            $required = ShopProductKind::Domain === $product->getKind() ? MinuteTariff::centsToUnits((int) $quote['period_cents']) : (int) $quote['monthly_cents'];
            if ('wallet' === $quote['billing_mode']) {
                $account->reserve($required);
            } elseif ($this->accounts->availableCreditUnits($account) < $required) {
                throw new \DomainException('Rechnungslimit erreicht. Offene Rechnungen bezahlen, Guthaben verwenden oder den Betreiber kontaktieren.');
            }
            if ('postpaid' === $quote['billing_mode']) {
                $account->reserveCredit($required, $this->accounts->availableCreditUnits($account));
            }
            $order = new ShopOrder($customer, $product, ShopOrderType::New, ShopOrderStatus::Pending, 1, (int) $quote['monthly_cents'], 0);
            $order->checkout(null, array_replace($quote, ['rental_contract' => true]), $key, $coupon);
            $contract = new ShopContract($order, $account, $product->getKind(), (string) $quote['billing_mode'], $quote, (int) $quote['monthly_cents']);
            $contract->reserve($required);
            if (in_array($product->getKind(), [ShopProductKind::Webspace, ShopProductKind::Domain], true)) {
                $reservation = $product->getKind()->value.':'.(string) $quote['hostname'];
                if (null !== $this->em->getRepository(ShopContract::class)->findOneBy(['reservationKey' => $reservation])) {
                    throw new \DomainException('Dieser Domainname ist bereits einem offenen Vertrag zugeordnet.');
                }
                $contract->claimName($reservation);
            }
            if (null !== $coupon) {
                $coupon->redeem(new \DateTimeImmutable());
            }
            $order->setContract($contract);
            $this->em->persist($order);
            $this->em->persist($contract);
            $this->em->flush();
            if (ShopProductKind::Domain === $product->getKind()) {
                $this->registrar->enqueue($contract, (string) $quote['domain_action'], trim($input['auth_code'] ?? ''));
            }
            $this->capacity->reserve($contract, $quote);
            $provider = $this->resources->for($contract);
            if ($provider instanceof ContractResourceReservation) {
                $provider->reserveResource($contract);
            }
            $this->notices->contract($contract, 'ordered', 'Mietvertrag bestellt', 'Dein Vertrag wurde angelegt. Die Nutzungsabrechnung beginnt nach bestätigter Bereitstellung.');
            $this->audit->log($customer, 'rental.contract.ordered', ['contract_id' => $contract->getId(), 'kind' => $product->getKind()->value, 'billing_mode' => $quote['billing_mode']]);

            return $contract;
        });
    }

    public function cancel(ShopContract $contract, User $actor, Request $request, ?\DateTimeImmutable $now = null): void
    {
        if ($contract->getCustomer()->getId() !== $actor->getId() && !$actor->isAdmin()) {
            throw new \DomainException('Dieser Vertrag gehört einem anderen Kunden.');
        }
        $now ??= new \DateTimeImmutable();
        $this->em->wrapInTransaction(function () use ($contract, $actor, $request, $now): void {
            $account = $contract->getAccount();
            $this->em->refresh($account, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($contract, LockMode::PESSIMISTIC_WRITE);
            if (null !== $contract->getCancelledAt() || 'ended' === $contract->getStatus()) {
                return;
            }
            $contract->requestCancellation($now);
            if (ShopProductKind::Domain === $contract->getKind() && null !== $contract->getStartedAt()) {
                $this->registrar->enqueue($contract, 'cancel');
            }
            if (ShopProductKind::Domain === $contract->getKind() && null !== $contract->getStartedAt() && $contract->getTermEndsAt() > $now) {
                $this->notices->contract($contract, 'cancelled', 'Domain gekündigt', 'Deine Domain wird nicht erneut verlängert. Die bestätigte Laufzeit bleibt bestehen.');
                $this->audit->log($actor, 'rental.domain.cancelled_at_term', ['contract_id' => $contract->getId(), 'ends_at' => $contract->getTermEndsAt()?->format(DATE_ATOM)]);

                return;
            }
            $this->meter($contract, $now);
            if (null === $contract->getEndedAt()) {
                $contract->end($now, 'CUSTOMER_CANCELLATION');
            }
            $this->release($contract);
            $coupon = $contract->getOrder()->getCoupon();
            if (null === $contract->getStartedAt() && null !== $coupon) {
                $this->em->refresh($coupon, LockMode::PESSIMISTIC_WRITE);
                $coupon->releaseReservation();
                $contract->getOrder()->releaseCoupon();
            }
            try {
                if ($this->resources->for($contract)->stop($contract)) {
                    $contract->stopped();
                    $contract->getOrder()->setStatus(ShopOrderStatus::Cancelled);
                    if (null === $contract->getResourceId() && null === $contract->getJobId()) {
                        $this->capacity->release($contract, $now);
                    }
                }
            } catch (\DomainException|\InvalidArgumentException $error) {
                $contract->fail($error->getMessage());
            }
            $this->em->flush();
            $this->invoices->issue($account, $contract->getBillingMode(), $now, $request);
            $this->notices->contract($contract, 'cancelled', 'Mietvertrag gekündigt', 'Die Nutzungsabrechnung ist beendet. Dein System wird gestoppt; die Aufbewahrungsfrist findest du im Vertrag.');
            $this->audit->log($actor, 'rental.contract.cancelled', ['contract_id' => $contract->getId(), 'minutes' => $contract->getChargedMinutes(), 'ended_at' => $contract->getEndedAt()?->format(DATE_ATOM)]);
        });
    }

    public function run(\DateTimeImmutable $now, Request $request): int
    {
        $minute = $now->setTime((int) $now->format('H'), (int) $now->format('i'));
        /** @var list<ShopContract> $contracts */
        $contracts = $this->em->getRepository(ShopContract::class)->createQueryBuilder('c')->where('c.status IN (:states) AND (c.checkedAt IS NULL OR c.checkedAt < :minute)')->setParameter('states', ['pending', 'provisioning', 'active', 'stopping'])->setParameter('minute', $minute, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)->orderBy('c.checkedAt')->addOrderBy('c.id')->setMaxResults(500)->getQuery()->getResult();
        foreach ($contracts as $contract) {
            $this->em->wrapInTransaction(function () use ($contract, $now, $request): void {
                $account = $contract->getAccount();
                $this->em->refresh($account, LockMode::PESSIMISTIC_WRITE);
                $this->em->refresh($contract, LockMode::PESSIMISTIC_WRITE);
                $contract->checked($now);
                $provider = $this->resources->for($contract);
                try {
                    $settings = $this->runtime->get();
                    if ('pending' === $contract->getStatus() && (null === $contract->getNextAttemptAt() || $contract->getNextAttemptAt() <= $now)) {
                        if ($contract->getProvisionAttempts() >= (int) $settings['max_attempts']) {
                            $contract->end($now, 'PROVISION_RETRY_LIMIT');
                        } else {
                            $contract->attempted($now, (int) $settings['provisioning_minutes'], (int) $settings['retry_minutes']);
                            $provider->provision($contract);
                        }
                    }

                    if ('provisioning' === $contract->getStatus() && ShopProductKind::Domain !== $contract->getKind()) {
                        $at = $provider->readyAt($contract);
                        if (null !== $contract->getProvisioningDeadlineAt() && $contract->getProvisioningDeadlineAt() <= $now && (null === $at || $at > $contract->getProvisioningDeadlineAt())) {
                            $contract->end($now, 'PROVISION_TIMEOUT');
                            $at = null;
                        }
                        if (null === $at && 'PROVISION_FAILED' === $contract->getError()) {
                            $contract->end($now, 'PROVISION_FAILED');
                        }
                        if (null !== $at) {
                            $contract->activate(min($at, $now));
                            $this->release($contract);
                            $contract->getOrder()->setStatus(ShopOrderStatus::Provisioned);
                            $this->notices->contract($contract, 'active', 'Dein System ist bereit', 'Die Bereitstellung ist bestätigt. Ab jetzt gilt die vereinbarte Nutzungsabrechnung.');
                        }
                    }
                    if ('active' === $contract->getStatus()) {
                        $this->meter($contract, $now);
                    }
                    if ('stopping' === $contract->getStatus()) {
                        if (null === $contract->getStartedAt()) {
                            $this->notices->contract($contract, 'provision-failed', 'Bereitstellung konnte nicht abgeschlossen werden', 'Der Vertrag wurde ohne Nutzungsgebühren beendet. Der Betreiber prüft und bereinigt die Bereitstellung.');
                        }
                        $contract->scheduleDeletion(($contract->getEndedAt() ?? $now)->modify('+'.$this->runtime->dataRetentionDays($contract->getKind()).' days'));
                        $this->jobs->retryStopIfFailed($contract, $now);
                        $this->release($contract);
                        $this->em->flush();
                        $this->invoices->issue($account, $contract->getBillingMode(), $now, $request);
                        if ($provider->stop($contract)) {
                            $contract->stopped();
                            $contract->getOrder()->setStatus(ShopOrderStatus::Cancelled);
                        }
                    }
                } catch (\DomainException|\InvalidArgumentException $error) {
                    $contract->fail($error->getMessage());
                }
            });
        }
        /** @var list<RentalAccount> $due */
        $due = $this->em->getRepository(RentalAccount::class)->createQueryBuilder('a')->where('a.nextInvoiceAt <= :now')->setParameter('now', $now, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)->orderBy('a.nextInvoiceAt')->setMaxResults(100)->getQuery()->getResult();
        foreach ($due as $account) {
            $this->em->wrapInTransaction(function () use ($account, $now, $request): void {
                $this->em->refresh($account, LockMode::PESSIMISTIC_WRITE);
                if ($account->getNextInvoiceAt() > $now) {
                    return;
                }
                $minute = $now->setTime((int) $now->format('H'), (int) $now->format('i'));
                $unmetered = (int) $this->em->getRepository(ShopContract::class)->createQueryBuilder('c')->select('COUNT(c.id)')->where('c.account = :account AND c.status = :active AND (c.checkedAt IS NULL OR c.checkedAt < :minute)')->setParameter('account', $account->getId(), \Doctrine\DBAL\Types\Types::INTEGER)->setParameter('active', 'active')->setParameter('minute', $minute, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)->getQuery()->getSingleScalarResult();
                if ($unmetered > 0) {
                    return;
                }
                $this->invoices->issue($account, 'postpaid', $now, $request);
                $this->invoices->issue($account, 'wallet', $now, $request);
                $account->invoiced($now);
            });
        }

        return count($contracts);
    }

    private function meter(ShopContract $contract, \DateTimeImmutable $now): void
    {
        $start = $contract->getStartedAt();
        if (null === $start || null !== $contract->getEndedAt()) {
            return;
        }
        if (ShopProductKind::Domain === $contract->getKind()) {
            if ($contract->getTermEndsAt() <= $now) {
                if (null !== $contract->getCancelledAt()) {
                    $contract->end($contract->getTermEndsAt() ?? $now, 'DOMAIN_TERM_ENDED');
                } else {
                    $contract->fail('DOMAIN_RENEWAL_CONFIRMATION_REQUIRED');
                }
            }

            return;
        }
        $minutes = max(1, MinuteTariff::minutes($start, $now));
        $difference = $minutes - $contract->getChargedMinutes();
        if ($difference <= 0) {
            return;
        }
        $rate = $contract->getMonthlyCents();
        $account = $contract->getAccount();
        $available = 'wallet' === $contract->getBillingMode() ? $account->getAvailableUnits() : $this->accounts->availableCreditUnits($account);
        $allowed = $rate > 0 ? min($difference, intdiv($available, $rate)) : $difference;
        $units = $allowed * $rate;
        if ('wallet' === $contract->getBillingMode()) {
            $account->debit($units);
        }
        $contract->charge($contract->getChargedMinutes() + $allowed, $units);
        if ($allowed < $difference) {
            $contract->end($start->modify('+'.$contract->getChargedMinutes().' minutes'), 'wallet' === $contract->getBillingMode() ? 'WALLET_EMPTY' : 'CREDIT_LIMIT_REACHED');
        }
    }

    public function confirmDomain(ShopContract $contract, User $actor, string $reference, Request $request, ?\DateTimeImmutable $now = null): void
    {
        if (!$actor->isAdmin() || ShopProductKind::Domain !== $contract->getKind() || strlen(trim($reference)) < 3 || strlen($reference) > 120) {
            throw new \DomainException('Domainbestätigung benötigt Adminrechte und eine Registrierungsreferenz.');
        }
        $this->applyDomainTerm($contract, $actor, $reference, $request, $now);
    }

    public function confirmRegistrar(\App\Module\Core\Domain\Entity\RegistrarOperation $operation, Request $request, \DateTimeImmutable $now): void
    {
        $contract = $operation->getContract();
        if (null !== $this->em->getRepository(ShopDomainTerm::class)->findOneBy(['contract' => $contract, 'reference' => 'AUTODNS-'.$operation->getId()])) {
            return;
        }
        $start = $contract->getTermEndsAt() ?? $operation->getSubmittedAt();
        $expiry = $operation->getObservedExpiry();
        if ('succeeded' !== $operation->getStatus() || !in_array($operation->getAction(), ['register', 'transfer', 'renew'], true) || null === $start || null === $expiry || $expiry <= $now || $expiry->format('Y-m-d') < BillingPeriod::addMonths($start, (int) $contract->getConfiguration()['term_months'])->format('Y-m-d')) {
            throw new \DomainException('REGISTRAR_PERIOD_NOT_CONFIRMED');
        }
        $this->applyDomainTerm($contract, null, 'AUTODNS-'.$operation->getId(), $request, $now, $expiry, $start);
    }

    public function reserveDomainRenewal(ShopContract $contract): bool
    {
        return $this->em->wrapInTransaction(function () use ($contract): bool {
            $account = $contract->getAccount();
            $this->em->refresh($account, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($contract, LockMode::PESSIMISTIC_WRITE);
            if (ShopProductKind::Domain !== $contract->getKind() || 'active' !== $contract->getStatus() || null !== $contract->getCancelledAt() || null !== $contract->getEndedAt()) {
                return false;
            }
            if ($contract->getReservedUnits() > 0) {
                return true;
            }
            $units = MinuteTariff::centsToUnits((int) $contract->getConfiguration()['period_cents']);
            $available = 'wallet' === $contract->getBillingMode() ? $account->getAvailableUnits() : $this->accounts->availableCreditUnits($account);
            if ($available < $units) {
                return false;
            }
            if ('wallet' === $contract->getBillingMode()) {
                $account->reserve($units);
            } else {
                $account->reserveCredit($units, $available);
            }
            $contract->reserve($units);

            return true;
        });
    }

    public function releaseFailedDomain(\App\Module\Core\Domain\Entity\RegistrarOperation $operation): void
    {
        if ('failed' !== $operation->getStatus() && !('succeeded' === $operation->getStatus() && null !== $operation->getContract()->getCancelledAt())) {
            return;
        }
        $contract = $operation->getContract();
        $this->em->wrapInTransaction(function () use ($contract): void {
            $this->em->refresh($contract->getAccount(), LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($contract, LockMode::PESSIMISTIC_WRITE);
            $this->release($contract);
        });
    }

    public function expireUnrenewedDomain(ShopContract $contract, \DateTimeImmutable $now): bool
    {
        return $this->em->wrapInTransaction(function () use ($contract, $now): bool {
            $this->em->refresh($contract->getAccount(), LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($contract, LockMode::PESSIMISTIC_WRITE);
            $end = $contract->getTermEndsAt();
            if (ShopProductKind::Domain !== $contract->getKind() || null === $end || $end > $now || null !== $contract->getEndedAt()) {
                return false;
            }
            $renewal = $this->em->getRepository(\App\Module\Core\Domain\Entity\RegistrarOperation::class)->findOneBy(['requestKey' => ($contract->getId() ?? 0).':renew:'.$end->format('Y-m-d')]);
            if (null !== $renewal && 'failed' !== $renewal->getStatus()) {
                return false;
            }
            $this->release($contract);
            $contract->end($end, 'DOMAIN_RENEWAL_FAILED');
            $this->notices->contract($contract, 'expired.'.$end->format('Ymd'), 'Domainlaufzeit beendet', 'Die bestätigte Laufzeit ist beendet. Eine weitere kostenpflichtige Verlängerung wurde nicht bestätigt. Bitte kontaktiere den Betreiber bei Rückfragen.');
            $this->audit->log(null, 'registrar.domain.expired', ['contract_id' => $contract->getId()]);

            return true;
        });
    }

    public function failRegistrar(\App\Module\Core\Domain\Entity\RegistrarOperation $operation, \DateTimeImmutable $now): void
    {
        if ('failed' !== $operation->getStatus() || !in_array($operation->getAction(), ['register', 'transfer'], true)) {
            return;
        }
        $contract = $operation->getContract();
        $this->em->wrapInTransaction(function () use ($contract, $now): void {
            $this->em->refresh($contract->getAccount(), LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($contract, LockMode::PESSIMISTIC_WRITE);
            if (null !== $contract->getEndedAt() || null !== $contract->getStartedAt()) {
                return;
            }
            $this->release($contract);
            $contract->end($now, 'DOMAIN_REGISTRATION_FAILED');
            $contract->stopped();
            $contract->getOrder()->setStatus(ShopOrderStatus::Cancelled);
            $coupon = $contract->getOrder()->getCoupon();
            if (null !== $coupon) {
                $this->em->refresh($coupon, LockMode::PESSIMISTIC_WRITE);
                $coupon->releaseReservation();
                $contract->getOrder()->releaseCoupon();
            }
            $this->notices->contract($contract, 'registrar-failed', 'Domainauftrag nicht erfolgreich', 'Der Registrar konnte den Auftrag nicht abschließen. Es wurden keine Domaingebühren berechnet. Bitte kontaktiere bei Rückfragen den Betreiber.');
            $this->audit->log(null, 'registrar.contract.failed', ['contract_id' => $contract->getId()]);
        });
    }

    private function applyDomainTerm(ShopContract $contract, ?User $actor, string $reference, Request $request, ?\DateTimeImmutable $now, ?\DateTimeImmutable $confirmedEnd = null, ?\DateTimeImmutable $confirmedStart = null): void
    {
        $now ??= new \DateTimeImmutable();
        $this->em->wrapInTransaction(function () use ($contract, $actor, $reference, $request, $now, $confirmedEnd, $confirmedStart): void {
            $account = $contract->getAccount();
            $this->em->refresh($account, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($contract, LockMode::PESSIMISTIC_WRITE);
            if (null !== $this->em->getRepository(ShopDomainTerm::class)->findOneBy(['contract' => $contract, 'reference' => $reference])) {
                return;
            }
            if (null !== $contract->getCancelledAt() || !in_array($contract->getStatus(), ['provisioning', 'active'], true)) {
                throw new \DomainException('Dieser Domainvertrag kann nicht bestätigt oder verlängert werden.');
            }
            $start = $contract->getTermEndsAt() ?? $confirmedStart ?? $now;
            if (null !== $contract->getTermEndsAt() && $start > $now->modify('+30 days')) {
                throw new \DomainException('Eine Domainverlängerung ist erst 30 Tage vor Laufzeitende möglich.');
            }
            $end = $confirmedEnd ?? BillingPeriod::addMonths($start, (int) $contract->getConfiguration()['term_months']);
            if (null !== $confirmedEnd && ($end <= $now || $end->format('Y-m-d') < BillingPeriod::addMonths($start, (int) $contract->getConfiguration()['term_months'])->format('Y-m-d'))) {
                throw new \DomainException('REGISTRAR_PERIOD_NOT_CONFIRMED');
            }
            $price = (int) $contract->getConfiguration()['period_cents'];
            $units = MinuteTariff::centsToUnits($price);
            $this->release($contract);
            if ('wallet' === $contract->getBillingMode()) {
                $account->debit($units);
            } elseif ($this->accounts->availableCreditUnits($account) < $units) {
                throw new \DomainException('Rechnungslimit für die Domainlaufzeit nicht ausreichend.');
            }
            $contract->activate($confirmedStart ?? $now);
            $contract->domainTermEnds($end);
            $contract->charge($contract->getChargedMinutes(), $units);
            $domain = $this->em->find(Domain::class, (int) $contract->getResourceId());
            if (null !== $domain) {
                $domain->setStatus('active');
            }
            $this->em->persist(new ShopDomainTerm($contract, $actor, $reference, $start, $end, $price));
            $contract->getOrder()->setStatus(ShopOrderStatus::Provisioned);
            $this->em->flush();
            $this->invoices->issue($account, $contract->getBillingMode(), $now, $request);
            $this->audit->log($actor, 'rental.domain.registration_confirmed', ['contract_id' => $contract->getId(), 'reference' => $reference, 'ends_at' => $end->format(DATE_ATOM)]);
        });
    }

    private function release(ShopContract $contract): void
    {
        $units = $contract->releaseReservation();
        if ($units > 0) {
            if ('wallet' === $contract->getBillingMode()) {
                $contract->getAccount()->release($units);
            } else {
                $contract->getAccount()->releaseCredit($units);
            }
        }
    }
}
