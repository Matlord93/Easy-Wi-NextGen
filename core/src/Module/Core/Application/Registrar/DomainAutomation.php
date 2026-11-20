<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Registrar;

use App\Infrastructure\Config\JsonMap;
use App\Infrastructure\Config\ScalarValue;
use App\Module\Core\Application\AuditLoggerInterface;
use App\Module\Core\Application\Contracts\RentalContracts;
use App\Module\Core\Application\Contracts\RentalNotices;
use App\Module\Core\Application\SecretsCryptoInterface;
use App\Module\Core\Domain\Entity\RegistrarContact;
use App\Module\Core\Domain\Entity\RegistrarOperation;
use App\Module\Core\Domain\Entity\ShopContract;
use App\Module\Core\Domain\Enum\ShopProductKind;
use App\Module\Core\Domain\ValueObject\BillingPeriod;
use App\Repository\CustomerProfileRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Symfony\Component\HttpFoundation\Request;

final readonly class DomainAutomation
{
    public function __construct(private EntityManagerInterface $em, private RegistrarSettings $settings, private AutodnsClient $client, private DomainRegistrarQueue $queue, private RentalContracts $contracts, private RentalNotices $notices, private SecretsCryptoInterface $crypto, private CustomerProfileRepository $profiles, private AuditLoggerInterface $audit)
    {
    }

    public function run(\DateTimeImmutable $now, Request $request): void
    {
        if (true !== $this->settings->publicSettings()['enabled']) {
            return;
        }
        $this->renewals($now);
        /** @var list<RegistrarOperation> $operations */
        $operations = $this->em->getRepository(RegistrarOperation::class)->createQueryBuilder('o')->where("o.status IN ('queued','pending','submitting') OR (o.status = 'succeeded' AND o.accountedAt IS NULL)")->andWhere('o.checkedAt IS NULL OR o.checkedAt < :minute')->setParameter('minute', $now->modify('-1 minute'), \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)->orderBy('o.checkedAt')->addOrderBy('o.createdAt')->setMaxResults(10)->getQuery()->getResult();
        $started = microtime(true);
        foreach ($operations as $operation) {
            if (microtime(true) - $started > 25) {
                break;
            }
            $claimed = $this->em->wrapInTransaction(function () use ($operation, $now): bool {
                $this->em->refresh($operation, LockMode::PESSIMISTIC_WRITE);
                $operation->checked($now);
                if ($operation->getIdentity() !== $this->settings->identity()) {
                    $operation->review('REGISTRAR_ACCOUNT_CHANGED');

                    return false;
                }
                if ('submitting' === $operation->getStatus()) {
                    if (null !== $operation->getSubmittedAt() && $operation->getSubmittedAt() < $now->modify('-5 minutes')) {
                        $operation->review('REGISTRAR_SUBMISSION_UNCONFIRMED');
                    }

                    return false;
                }
                if ('queued' === $operation->getStatus()) {
                    $contract = $operation->getContract();
                    if ('cancel' !== $operation->getAction() && null !== $contract->getCancelledAt()) {
                        $operation->failed('CANCELLED_BEFORE_SUBMISSION');

                        return false;
                    }
                    if ('cancel' === $operation->getAction() && $this->hasUnsettledPaidOperation($contract)) {
                        return false;
                    }
                    if (in_array($operation->getAction(), ['register', 'transfer'], true) && 'provisioning' !== $contract->getStatus()) {
                        if (in_array($contract->getStatus(), ['stopping', 'ended'], true)) {
                            $operation->failed('CONTRACT_NO_LONGER_PENDING');
                        }

                        return false;
                    }
                    if ('renew' === $operation->getAction()) {
                        $end = $contract->getTermEndsAt();
                        if ('active' !== $contract->getStatus() || null !== $contract->getEndedAt() || null === $end || $end <= $now) {
                            $operation->failed('DOMAIN_RENEWAL_WINDOW_EXPIRED');

                            return false;
                        }
                        if (!$this->contracts->reserveDomainRenewal($contract)) {
                            if (null !== $contract->getCancelledAt() || null !== $contract->getEndedAt()) {
                                $operation->failed('CANCELLED_BEFORE_SUBMISSION');

                                return false;
                            }
                            $contract->fail('DOMAIN_RENEWAL_FUNDS_REQUIRED');
                            $this->notices->contract($contract, 'renewal-funds.'.$end->format('Ymd'), 'Domainverlängerung benötigt Guthaben oder Rechnungsspielraum', 'Bitte rechtzeitig Guthaben aufladen oder offene Rechnungen bezahlen. Die neue Domainlaufzeit wird erst nach erfolgreicher Verlängerung berechnet.');

                            return false;
                        }
                    }
                    $operation->submitting($now);
                }

                return true;
            });
            if (!$claimed) {
                if ('failed' === $operation->getStatus()) {
                    $this->contracts->releaseFailedDomain($operation);
                    $this->contracts->failRegistrar($operation, $now);
                } continue;
            }
            try {
                if ('submitting' === $operation->getStatus()) {
                    $this->submit($operation, $now);
                }
                if ('pending' === $operation->getStatus()) {
                    $this->poll($operation, $now);
                }
                if ('failed' === $operation->getStatus()) {
                    $this->contracts->releaseFailedDomain($operation);
                    $this->contracts->failRegistrar($operation, $now);
                }
                if ('succeeded' === $operation->getStatus() && null === $operation->getAccountedAt()) {
                    $contract = $operation->getContract();
                    if ('cancel' !== $operation->getAction()) {
                        if (null !== $contract->getCancelledAt()) {
                            $this->contracts->releaseFailedDomain($operation);
                            $this->contracts->failRegistrar($operation, $now);
                            $contract->fail('REGISTRAR_COMPLETED_AFTER_CANCELLATION');
                            $this->queue->enqueue($contract, 'cancel');
                        } else {
                            $this->contracts->confirmRegistrar($operation, $request, $now);
                            $this->notices->contract($contract, 'registrar.'.$operation->getId(), 'Domainauftrag abgeschlossen', 'Registrierung oder Verlängerung wurde vom Registrar bestätigt. Den festen Nutzungszeitraum findest du im Vertrag.');
                        }
                    }
                    $operation->accounted($now);
                }
                $this->em->flush();
            } catch (\Throwable $error) {
                // A timeout can follow a successful paid request. Do not register or renew twice.
                if (!$this->em->isOpen()) {
                    throw $error;
                }
                if ('submitting' === $operation->getStatus()) {
                    $operation->review('REGISTRAR_RESPONSE_UNCONFIRMED');
                }
                if ('pending' === $operation->getStatus() && in_array($error->getMessage(), ['REGISTRAR_OWNERSHIP_NOT_CONFIRMED', 'REGISTRAR_PERIOD_NOT_CONFIRMED', 'REGISTRAR_AUTORENEW_REQUIRES_REVIEW'], true)) {
                    $operation->review($error->getMessage());
                }
                $operation->getContract()->fail('REGISTRAR_REVIEW_REQUIRED');
                $this->audit->log(null, 'registrar.operation.needs_review', ['operation_id' => $operation->getId(), 'exception_type' => $error::class]);
                $this->em->flush();
            }
        }
    }

    /** A renewal can remove a previously stored cancellation; settle it before sending expiry deletion. */
    private function hasUnsettledPaidOperation(ShopContract $contract): bool
    {
        return (int) $this->em->getRepository(RegistrarOperation::class)->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.contract = :contract AND p.action IN (:actions)')
            ->andWhere("p.status IN (:states) OR (p.status = 'succeeded' AND p.accountedAt IS NULL)")
            ->setParameter('contract', $contract->getId(), \Doctrine\DBAL\Types\Types::INTEGER)
            ->setParameter('actions', ['register', 'transfer', 'renew'])
            ->setParameter('states', ['queued', 'submitting', 'pending', 'review'])
            ->getQuery()->getSingleScalarResult() > 0;
    }

    private function renewals(\DateTimeImmutable $now): void
    {
        // Filter before LIMIT: manual domains and existing pending/reviewed renewals must not
        // occupy every slot and prevent later customers from reaching their renewal window.
        $periodKey = "CONCAT(CONCAT(c.id, ':renew:'), SUBSTRING(CONCAT('', c.termEndsAt), 1, 10))";
        /** @var list<ShopContract> $due */
        $due = $this->em->getRepository(ShopContract::class)->createQueryBuilder('c')
            ->leftJoin(RegistrarOperation::class, 'renewal', Join::WITH, "renewal.contract = c AND renewal.action = 'renew' AND renewal.requestKey = ".$periodKey)
            ->where("c.kind = :kind AND c.status = 'active' AND c.cancelledAt IS NULL AND c.termEndsAt <= :horizon")
            ->andWhere('EXISTS (SELECT registration.id FROM '.RegistrarOperation::class." registration WHERE registration.contract = c AND registration.action IN ('register', 'transfer'))")
            ->andWhere("renewal.id IS NULL OR (renewal.status = 'failed' AND c.termEndsAt <= :now)")
            ->setParameter('kind', ShopProductKind::Domain->value)
            ->setParameter('horizon', $now->modify('+7 days'), \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->setParameter('now', $now, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->orderBy('c.termEndsAt')->addOrderBy('c.id')->setMaxResults(100)->getQuery()->getResult();
        foreach ($due as $contract) {
            if (($contract->getConfiguration()['registrar'] ?? '') !== 'autodns') {
                continue;
            }
            if ($this->contracts->expireUnrenewedDomain($contract, $now)) {
                $this->queue->enqueue($contract, 'cancel');
                continue;
            }
            $key = ($contract->getId() ?? 0).':renew:'.($contract->getTermEndsAt()?->format('Y-m-d') ?? '');
            if (null !== $this->em->getRepository(RegistrarOperation::class)->findOneBy(['requestKey' => $key])) {
                continue;
            }
            // Keep an unfunded request in the fair, bounded worker queue. Its provider
            // submission is still gated by a locked account reservation above.
            $this->queue->enqueue($contract, 'renew');
        }
        $this->em->flush();
    }

    private function submit(RegistrarOperation $operation, \DateTimeImmutable $now): void
    {
        $contract = $operation->getContract();
        $config = $contract->getConfiguration();
        $name = (string) $config['hostname'];
        $action = $operation->getAction();
        if ('cancel' === $action) {
            $response = $this->client->call('POST', '/domain/'.$name.'/cancelation', ['domain' => $name, 'type' => 'DELETE', 'execution' => 'EXPIRE']);
            $proof = AutodnsClient::first($response);
            if (($proof['domain'] ?? '') !== $name || !in_array($proof['execution'] ?? '', ['EXPIRE', 'DATE'], true)) {
                throw new \DomainException('REGISTRAR_CANCELLATION_NOT_CONFIRMED');
            }
            $operation->succeeded($now);
            $this->em->flush();

            return;
        }
        $body = ['name' => $name, 'period' => ['unit' => 'YEAR', 'period' => intdiv((int) $config['term_months'], 12)], 'autoRenewStatus' => 'FALSE'];
        if ('renew' !== $action) {
            $owner = $this->contact($contract);
            $settings = $this->settings->publicSettings();
            $body += ['ownerc' => ['id' => $owner], 'adminc' => ['id' => (int) $settings['admin_contact']], 'techc' => ['id' => (int) $settings['tech_contact']], 'zonec' => ['id' => (int) $settings['zone_contact']], 'nameServers' => array_map(static fn (string $ns): array => ['name' => $ns], explode(',', (string) $settings['nameservers']))];
            if ('register' === $action && !$this->client->available($name)) {
                $operation->failed('DOMAIN_NOT_AVAILABLE');
                $this->em->flush();

                return;
            }
            if ('transfer' === $action) {
                $encrypted = $operation->getAuthinfoEncrypted();
                if (null === $encrypted) {
                    throw new \DomainException('TRANSFER_AUTHINFO_REQUIRED');
                } $body['authinfo'] = $this->crypto->decrypt($encrypted);
            }
        } else {
            $body['removeCancelation'] = true;
        }
        [$method, $path] = match ($action) {
            'register' => ['POST', '/domain'], 'transfer' => ['POST', '/domain/_transfer'], 'renew' => ['PUT', '/domain/'.$name.'/_renew'], default => throw new \DomainException('Unknown registrar action.'),
        };
        $response = $this->client->call($method, $path, $body);
        $job = AutodnsClient::first($response);
        $id = ScalarValue::text($job['id'] ?? null);
        if ('' === $id && is_int($job['id'] ?? null)) {
            $id = (string) $job['id'];
        }
        if (!preg_match('/^[0-9]{1,18}$/D', $id)) {
            throw new \DomainException('REGISTRAR_JOB_REFERENCE_MISSING');
        }
        $operation->accepted($id);
        $this->audit->log(null, 'registrar.operation.accepted', ['operation_id' => $operation->getId(), 'job_id' => $id, 'action' => $action]);
        $this->em->flush();
    }

    private function poll(RegistrarOperation $operation, \DateTimeImmutable $now): void
    {
        $id = $operation->getJobId();
        if (null === $id) {
            throw new \DomainException('Registrar job missing.');
        }
        try {
            $response = $this->client->call('GET', '/job/'.$id);
        } catch (\DomainException $error) {
            $response = $this->client->call('GET', '/job/history/'.$id);
        }
        $row = AutodnsClient::first($response);
        $job = JsonMap::fromValue($row['job'] ?? null) ?? $row;
        $status = $job['status'] ?? '';
        if (in_array($status, ['FAILED', 'CANCELED'], true)) {
            $operation->failed('REGISTRAR_JOB_FAILED');

            return;
        }
        if ('SUCCESS' !== $status) {
            return;
        }
        $contract = $operation->getContract();
        $name = (string) $contract->getConfiguration()['hostname'];
        $domain = AutodnsClient::first($this->client->call('GET', '/domain/'.$name));
        $contact = $this->em->getRepository(RegistrarContact::class)->findOneBy(['customer' => $contract->getCustomer(), 'identity' => $operation->getIdentity()]);
        if (($domain['name'] ?? '') !== $name || null === $contact || (int) (JsonMap::fromValue($domain['ownerc'] ?? null)['id'] ?? 0) !== $contact->getContactId() || !in_array($domain['registryStatus'] ?? '', ['ACTIVE', 'LOCK'], true)) {
            throw new \DomainException('REGISTRAR_OWNERSHIP_NOT_CONFIRMED');
        }
        $raw = ScalarValue::text($domain['payable'] ?? $domain['expire'] ?? null);
        $expiry = '' !== $raw ? new \DateTimeImmutable($raw) : null;
        $start = $contract->getTermEndsAt() ?? $operation->getSubmittedAt();
        if (null === $start || null === $expiry || $expiry <= $now || $expiry->format('Y-m-d') < BillingPeriod::addMonths($start, (int) $contract->getConfiguration()['term_months'])->format('Y-m-d')) {
            throw new \DomainException('REGISTRAR_PERIOD_NOT_CONFIRMED');
        }
        if (($domain['autoRenewStatus'] ?? '') !== 'FALSE') {
            throw new \DomainException('REGISTRAR_AUTORENEW_REQUIRES_REVIEW');
        }
        $operation->succeeded($now, $expiry);
        $this->em->flush();
    }

    private function contact(ShopContract $contract): int
    {
        return $this->em->wrapInTransaction(function () use ($contract): int {
            $this->em->refresh($contract->getAccount(), LockMode::PESSIMISTIC_WRITE);
            $customer = $contract->getCustomer();
            $identity = $this->settings->identity();
            $existing = $this->em->getRepository(RegistrarContact::class)->findOneBy(['customer' => $customer, 'identity' => $identity]);
            if (null !== $existing) {
                return $existing->getContactId();
            }
            $profile = $this->profiles->findOneByCustomer($customer);
            if (null === $profile) {
                throw new \DomainException('Registrar contact data missing.');
            }
            $body = ['alias' => 'easywi.'.\App\Infrastructure\Doctrine\EntityIdentifier::require($customer->getId()), 'type' => null !== $profile->getCompany() && '' !== $profile->getCompany() ? 'ORG' : 'PERSON', 'fname' => $profile->getFirstName(), 'lname' => $profile->getLastName(), 'organization' => $profile->getCompany(), 'address' => preg_split('/\R/', $profile->getAddress()) ?: [], 'pcode' => $profile->getPostal(), 'city' => $profile->getCity(), 'country' => strtoupper($profile->getCountry()), 'phone' => $profile->getPhone(), 'email' => $customer->getEmail()];
            $result = AutodnsClient::first($this->client->call('POST', '/contact', $body));
            $id = (int) ($result['id'] ?? 0);
            if ($id < 1) {
                throw new \DomainException('Registrar contact not confirmed.');
            }
            $this->em->persist(new RegistrarContact($customer, $identity, $id));

            return $id;
        });
    }
}
