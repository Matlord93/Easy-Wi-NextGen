<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application\Shop;

use App\Module\Core\Application\Contracts\ContractQuote;
use App\Module\Core\Application\Contracts\RentalContracts;
use App\Module\Core\Application\Registrar\AutodnsClient;
use App\Module\Core\Application\Registrar\DomainAutomation;
use App\Module\Core\Application\Registrar\RegistrarSettings;
use App\Module\Core\Domain\Entity\CustomerProfile;
use App\Module\Core\Domain\Entity\Invoice;
use App\Module\Core\Domain\Entity\RegistrarOperation;
use App\Module\Core\Domain\Entity\ShopCategory;
use App\Module\Core\Domain\Entity\ShopContract;
use App\Module\Core\Domain\Entity\ShopProduct;
use App\Module\Core\Domain\Entity\Site;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\ShopProductKind;
use App\Module\Core\Domain\Enum\UserType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

final class AutodnsRentalWorkflowTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private User $customer;
    private ShopProduct $product;
    private RentalContracts $contracts;
    private DomainAutomation $automation;
    private MockHttpClient $http;
    private array $environment = [];
    private bool $loseRegistrationResponse = false;
    private bool $loseRenewalResponse = false;
    private int $cancellations = 0;
    private bool $wrongOwner = false;
    private bool $jobFailure = false;
    private bool $jobPending = false;
    private string $expiry = '2027-12-01T12:00:00Z';
    private int $remoteJob = 456;
    private array $submitted = [];

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        foreach (['DATABASE_URL' => 'sqlite:///:memory:', 'EASYWI_DB_CONFIG_PATH' => '/tmp/easywi-autodns-no-config.json'] as $key => $value) {
            $this->environment[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null];
            $_SERVER[$key] = $_ENV[$key] = $value;
        }
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->customer = new User('autodns@example.test', UserType::Customer);
        $this->customer->setPasswordHash('unused');
        $site = new Site('Domains', 'localhost');
        foreach ([$this->customer, $site] as $entity) {
            $this->em->persist($entity);
        } $this->em->flush();
        $profile = new CustomerProfile($this->customer, 'Test', 'Customer', 'Example Street 1', '12345', 'Example', 'DE');
        $profile->setPhone('+49-123-1234567');
        $this->em->persist($profile);
        $category = new ShopCategory($site->getId(), 'Domains', 'domains');
        $this->em->persist($category);
        $this->product = new ShopProduct($site->getId(), $category, 'Domain .test', '', 100, null, null, 1, 1, 1);
        $this->product->configureKind(ShopProductKind::Domain, ['registrar' => 'autodns', 'tld' => 'test', 'term_months' => 12]);
        $this->em->persist($this->product);
        $this->em->flush();
        $settings = self::getContainer()->get(RegistrarSettings::class);
        $settings->save(['enabled' => true, 'sandbox' => true, 'user' => 'fixture', 'password' => 'fixture-password', 'context' => 4, 'tlds' => 'test', 'nameservers' => 'ns1.example.test,ns2.example.test', 'admin_contact' => 11, 'tech_contact' => 12, 'zone_contact' => 13]);
        $this->http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertStringStartsWith('https://api.demo.autodns.com/v1/', $url);
            $path = substr($url, strlen('https://api.demo.autodns.com/v1'));
            $body = isset($options['body']) ? json_decode($options['body'], true) : [];
            if ('/domainstudio' === $path) {
                return $this->reply([['domain' => $body['searchToken'], 'services' => ['whois' => ['status' => 'SUCCESS', 'data' => ['status' => 'FREE']]]]]);
            }
            if ('/contact' === $path) {
                self::assertSame($this->customer->getEmail(), $body['email']);
                self::assertSame('Test', $body['fname']);

                return $this->reply([['id' => 123]]);
            }
            if ('POST' === $method && in_array($path, ['/domain', '/domain/_transfer'], true)) {
                $this->submitted[] = $body;
                self::assertSame(['unit' => 'YEAR', 'period' => 1], $body['period']);
                self::assertSame(['id' => 123], $body['ownerc']);
                self::assertSame('FALSE', $body['autoRenewStatus']);
                if ($this->loseRegistrationResponse) {
                    throw new TransportException('Synthetic lost response after submission');
                }

                return $this->reply([['id' => $this->remoteJob]]);
            }
            if (str_ends_with($path, '/_renew')) {
                ++$this->remoteJob;
                $this->expiry = '2028-12-01T12:00:00Z';
                if ($this->loseRenewalResponse) {
                    throw new TransportException('Synthetic lost renewal response');
                }

                return $this->reply([['id' => $this->remoteJob]]);
            }
            if (str_contains($path, '/cancelation')) {
                ++$this->cancellations;

                return $this->reply([['domain' => $body['domain'], 'execution' => 'EXPIRE', 'type' => 'DELETE', 'status' => 'DELETE_EXPIRE']]);
            }
            if (str_starts_with($path, '/job/')) {
                return $this->reply([['job' => ['id' => $this->remoteJob, 'status' => $this->jobFailure ? 'FAILED' : ($this->jobPending ? 'RUNNING' : 'SUCCESS')]]]);
            }
            if (str_starts_with($path, '/domain/')) {
                return $this->reply([['name' => $this->hostname(), 'ownerc' => ['id' => $this->wrongOwner ? 999 : 123], 'registryStatus' => 'ACTIVE', 'autoRenewStatus' => 'FALSE', 'payable' => $this->expiry]]);
            }
            self::fail('Unexpected AutoDNS request: '.$method.' '.$path);
        });
        self::getContainer()->set(AutodnsClient::class, new AutodnsClient($this->http, $settings));
        $this->contracts = self::getContainer()->get(RentalContracts::class);
        $this->automation = self::getContainer()->get(DomainAutomation::class);
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        foreach ($this->environment as $key => [$server,$env]) {
            if (null === $server) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            } if (null === $env) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $env;
            }
        } parent::tearDown();
    }

    private function hostname(): string
    {
        return 'customer-'.substr(hash('sha256', $this->name()), 0, 20).'.test';
    }

    private function reply(array $data): MockResponse
    {
        return new MockResponse(json_encode(['status' => ['type' => 'SUCCESS', 'code' => 'S0000'], 'data' => $data], JSON_THROW_ON_ERROR));
    }

    private function order(array $extra = []): ShopContract
    {
        $input = array_replace(['hostname' => $this->hostname(), 'billing_mode' => 'postpaid'], $extra);
        $quote = self::getContainer()->get(ContractQuote::class)->build($this->product, $input, true);

        return $this->contracts->order($this->customer, $this->product, $input, bin2hex(random_bytes(16)), ContractQuote::digest($quote));
    }

    private function activate(ShopContract $contract): void
    {
        $start = new \DateTimeImmutable('2026-12-01 12:00:00Z');
        $this->contracts->run($start, Request::create('/shop'));
        $this->automation->run($start->modify('+1 minute'), Request::create('/shop'));
        self::assertSame('active', $contract->getStatus());
    }

    public static function quoteChanges(): iterable
    {
        yield 'equivalent reordered JSON' => [false];
        yield 'changed domain term' => [true];
    }

    #[DataProvider('quoteChanges')]
    public function testCheckoutRecognizesEquivalentJsonAndStillRejectsChangedTerms(bool $changed): void
    {
        $input = ['hostname' => $this->hostname(), 'billing_mode' => 'postpaid'];
        $quotes = self::getContainer()->get(ContractQuote::class);
        $digest = ContractQuote::digest($quotes->build($this->product, $input, true));
        // MySQL JSON normalizes object-key order when the locked product is refreshed.
        $stored = ['tld' => 'test', 'term_months' => $changed ? 24 : 12, 'registrar' => 'autodns'];
        $this->em->getConnection()->update('shop_products', ['provisioning_options' => json_encode($stored, JSON_THROW_ON_ERROR)], ['id' => $this->product->getId()]);
        if ($changed) {
            $this->expectException(\DomainException::class);
            $this->expectExceptionMessage('Der Tarif hat sich geändert.');
        }
        $contract = $this->contracts->order($this->customer, $this->product, $input, bin2hex(random_bytes(16)), $digest);
        self::assertSame('pending', $contract->getStatus());
        self::assertSame(1200, $contract->getConfiguration()['period_cents']);
        self::assertSame(0, $this->em->getRepository(Invoice::class)->count([]));
    }

    public function testConfirmedRegistrationIsBilledOnceAndRenewedOnlyAfterTheNextRegistrarProof(): void
    {
        $contract = $this->order();
        self::assertSame(0, $this->em->getRepository(Invoice::class)->count([]));
        $this->activate($contract);
        self::assertSame(1200, $this->em->getRepository(Invoice::class)->findOneBy([])->getAmountTotalCents());
        self::assertSame(0, $contract->getAccount()->getPostpaidReservedUnits());
        $this->automation->run(new \DateTimeImmutable('2026-12-01 12:02:00Z'), Request::create('/shop'));
        self::assertSame(1, $this->em->getRepository(Invoice::class)->count([]));
        $this->automation->run(new \DateTimeImmutable('2027-11-25 12:00:00Z'), Request::create('/shop'));
        self::assertSame(2, $this->em->getRepository(Invoice::class)->count([]));
        self::assertSame('2028-12-01', $contract->getTermEndsAt()->format('Y-m-d'));
    }

    public function testRegistrationConfirmedTheNextDayKeepsTheOriginalRegistrarPeriod(): void
    {
        $this->jobPending = true;
        $contract = $this->order();
        $start = new \DateTimeImmutable('2026-12-01 12:00:00Z');
        $this->contracts->run($start, Request::create('/shop'));
        $submitted = $start->modify('+1 minute');
        $this->automation->run($submitted, Request::create('/shop'));
        self::assertSame('provisioning', $contract->getStatus());
        self::assertSame(0, $this->em->getRepository(Invoice::class)->count([]));
        $this->jobPending = false;
        $confirmed = $start->modify('+1 day');
        $this->automation->run($confirmed, Request::create('/shop'));
        self::assertSame('active', $contract->getStatus());
        self::assertSame('2027-12-01', $contract->getTermEndsAt()->format('Y-m-d'));
        self::assertSame($submitted->getTimestamp(), $contract->getStartedAt()->getTimestamp());
        self::assertSame(1, $this->em->getRepository(Invoice::class)->count([]));
        $this->automation->run($confirmed->modify('+2 minutes'), Request::create('/shop'));
        self::assertSame(1, $this->em->getRepository(Invoice::class)->count([]));
        self::assertCount(1, $this->submitted);
    }

    public static function renewalObstacles(): iterable
    {
        yield 'renewals in review' => ['review'];
        yield 'manual domains' => ['manual'];
        yield 'insufficient funds' => ['unfunded'];
    }

    #[DataProvider('renewalObstacles')]
    public function testExistingRenewalsAndManualDomainsDoNotBlockNextBatch(string $obstacle): void
    {
        $original = $this->customer;
        $manual = 'manual' === $obstacle;
        $blockedAccounts = [];
        $start = new \DateTimeImmutable('2026-12-01 12:00:00Z');
        $end = new \DateTimeImmutable($this->expiry);
        $identity = self::getContainer()->get(RegistrarSettings::class)->identity();
        $accounts = self::getContainer()->get(\App\Module\Core\Application\Contracts\RentalAccounts::class);
        if ($manual) {
            $this->product->configureKind(ShopProductKind::Domain, ['registrar' => 'manual', 'tld' => 'test', 'term_months' => 12]);
        }
        for ($i = 0; $i < 100; ++$i) {
            if (0 === $i % 20) {
                $this->customer = new User('batch-'.$i.'@example.test', UserType::Customer);
                $this->customer->setPasswordHash('unused');
                $this->em->persist($this->customer);
                $profile = new CustomerProfile($this->customer, 'Test', 'Customer', 'Example Street 1', '12345', 'Example', 'DE');
                $profile->setPhone('+49-123-1234567');
                $this->em->persist($profile);
                $this->em->flush();
                $account = $accounts->forCustomer($this->customer, 'EUR');
                $account->setCreditLimitCents(1_000_000);
                $blockedAccounts[] = $account;
                $this->em->flush();
            }
            $blocked = $this->order(['hostname' => 'batch-'.$i.'.test']);
            $blocked->activate($start);
            $blocked->domainTermEnds($end);
            $blocked->getAccount()->releaseCredit($blocked->releaseReservation());
            if (!$manual) {
                $initial = $this->em->getRepository(RegistrarOperation::class)->findOneBy(['contract' => $blocked, 'action' => 'register']);
                $initial->submitting($start);
                $initial->succeeded($start, $end);
                $initial->accounted($start);
                if ('review' === $obstacle) {
                    $operation = new RegistrarOperation($blocked, 'renew', $blocked->getId().':renew:'.$end->format('Y-m-d'), $identity);
                    $operation->review('Operator review required');
                    $this->em->persist($operation);
                }
            }
        }
        if ('unfunded' === $obstacle) {
            foreach ($blockedAccounts as $account) {
                $account->setCreditLimitCents(0);
            }
        }
        $this->customer = $original;
        $this->product->configureKind(ShopProductKind::Domain, ['registrar' => 'autodns', 'tld' => 'test', 'term_months' => 12]);
        $this->em->persist(new \App\Module\Core\Domain\Entity\RegistrarContact($original, $identity, 123));
        $waiting = $this->order();
        $waiting->activate($start);
        $waiting->domainTermEnds($end);
        $initial = $this->em->getRepository(RegistrarOperation::class)->findOneBy(['contract' => $waiting, 'action' => 'register']);
        $initial->submitting($start);
        $initial->succeeded($start, $end);
        $initial->accounted($start);
        $this->em->flush();
        $now = $end->modify('-6 days');
        $this->automation->run($now, Request::create('/shop'));
        if ('unfunded' === $obstacle) {
            for ($i = 1; $i <= 12; ++$i) {
                $this->automation->run($now->modify('+'.($i * 2).' minutes'), Request::create('/shop'));
            }
        }
        self::assertSame(1, $this->em->getRepository(RegistrarOperation::class)->count(['contract' => $waiting, 'action' => 'renew']));
        self::assertSame($manual ? 1 : 101, $this->em->getRepository(RegistrarOperation::class)->count(['action' => 'renew']));
        self::assertSame('2028-12-01', $waiting->getTermEndsAt()->format('Y-m-d'));
    }

    public function testRenewalWaitsForFundsWithoutCallingTheRegistrarAndContinuesWhenFunded(): void
    {
        $contract = $this->order();
        $this->activate($contract);
        $account = $contract->getAccount();
        $account->setCreditLimitCents(1200);
        $this->em->flush();
        $before = $this->http->getRequestsCount();
        $now = new \DateTimeImmutable('2027-11-25 12:00:00Z');
        $this->automation->run($now, Request::create('/shop'));
        $renewal = $this->em->getRepository(RegistrarOperation::class)->findOneBy(['contract' => $contract, 'action' => 'renew']);
        self::assertNotNull($renewal);
        self::assertSame('queued', $renewal->getStatus());
        self::assertSame($before, $this->http->getRequestsCount());
        self::assertSame('DOMAIN_RENEWAL_FUNDS_REQUIRED', $contract->getError());
        self::assertSame(1, $this->em->getRepository(Invoice::class)->count([]));
        $account->setCreditLimitCents(2400);
        $this->em->flush();
        $this->automation->run($now->modify('+2 minutes'), Request::create('/shop'));
        self::assertSame('succeeded', $renewal->getStatus());
        self::assertSame('2028-12-01', $contract->getTermEndsAt()->format('Y-m-d'));
        self::assertNull($contract->getError());
        self::assertSame(2, $this->em->getRepository(Invoice::class)->count([]));
    }

    public function testUnfundedQueuedRenewalIsNeverSubmittedAfterItsPaidTermExpires(): void
    {
        $contract = $this->order();
        $this->activate($contract);
        $contract->getAccount()->setCreditLimitCents(1200);
        $this->em->flush();
        $this->automation->run(new \DateTimeImmutable('2027-11-25 12:00:00Z'), Request::create('/shop'));
        $renewal = $this->em->getRepository(RegistrarOperation::class)->findOneBy(['contract' => $contract, 'action' => 'renew']);
        self::assertNotNull($renewal);
        $before = $this->http->getRequestsCount();
        $expired = new \DateTimeImmutable('2027-12-01 12:01:00Z');
        $this->automation->run($expired, Request::create('/shop'));
        self::assertSame('failed', $renewal->getStatus());
        self::assertSame($before, $this->http->getRequestsCount());
        $this->automation->run($expired->modify('+2 minutes'), Request::create('/shop'));
        self::assertSame('stopping', $contract->getStatus());
        self::assertSame('2027-12-01', $contract->getEndedAt()->format('Y-m-d'));
        self::assertSame(1, $this->em->getRepository(Invoice::class)->count([]));
    }

    public function testExpiredRegistrarProofIsHeldForReviewWithoutBillingAnExpiredInitialTerm(): void
    {
        $this->jobPending = true;
        $contract = $this->order();
        $start = new \DateTimeImmutable('2026-12-01 12:00:00Z');
        $this->contracts->run($start, Request::create('/shop'));
        $this->automation->run($start->modify('+1 minute'), Request::create('/shop'));
        $this->jobPending = false;
        $this->automation->run($start->modify('+2 years'), Request::create('/shop'));
        self::assertNull($contract->getStartedAt());
        self::assertSame(0, $this->em->getRepository(Invoice::class)->count([]));
        self::assertSame('review', $this->em->getRepository(RegistrarOperation::class)->findOneBy(['contract' => $contract])->getStatus());
    }

    public function testLostSubmissionResponseIsNotReplayedAsAnotherPaidRegistration(): void
    {
        $this->loseRegistrationResponse = true;
        $contract = $this->order();
        $start = new \DateTimeImmutable('2026-12-01 12:00:00Z');
        $this->contracts->run($start, Request::create('/shop'));
        $this->automation->run($start->modify('+1 minute'), Request::create('/shop'));
        $count = $this->http->getRequestsCount();
        $this->automation->run($start->modify('+3 minutes'), Request::create('/shop'));
        self::assertSame($count, $this->http->getRequestsCount());
        self::assertCount(1, $this->submitted);
        self::assertSame('review', $this->em->getRepository(RegistrarOperation::class)->findOneBy([])->getStatus());
        self::assertNull($contract->getStartedAt());
        self::assertSame(0, $this->em->getRepository(Invoice::class)->count([]));
    }

    public function testLostRegistrationResponseCanBeReconciledTheNextDayWithoutAnotherPaidSubmission(): void
    {
        $this->loseRegistrationResponse = true;
        $contract = $this->order();
        $start = new \DateTimeImmutable('2026-12-01 12:00:00Z');
        $this->contracts->run($start, Request::create('/shop'));
        $this->automation->run($start->modify('+1 minute'), Request::create('/shop'));
        $operation = $this->em->getRepository(RegistrarOperation::class)->findOneBy(['contract' => $contract]);
        self::assertSame('review', $operation->getStatus());
        $operation->reconcile((string) $this->remoteJob);
        $this->em->flush();
        $this->automation->run($start->modify('+1 day'), Request::create('/shop'));
        self::assertSame('active', $contract->getStatus());
        self::assertSame('2026-12-01', $contract->getStartedAt()->format('Y-m-d'));
        self::assertSame('2027-12-01', $contract->getTermEndsAt()->format('Y-m-d'));
        self::assertCount(1, $this->submitted);
        self::assertSame(1, $this->em->getRepository(Invoice::class)->count([]));
    }

    public function testWrongRegistrarOwnerNeverActivatesOrBillsAnotherCustomersDomain(): void
    {
        $this->wrongOwner = true;
        $contract = $this->order();
        $start = new \DateTimeImmutable('2026-12-01 12:00:00Z');
        $this->contracts->run($start, Request::create('/shop'));
        $this->automation->run($start->modify('+1 minute'), Request::create('/shop'));
        self::assertNull($contract->getStartedAt());
        self::assertSame(0, $this->em->getRepository(Invoice::class)->count([]));
        self::assertSame('REGISTRAR_REVIEW_REQUIRED', $contract->getError());
    }

    public function testCancellationSchedulesExpiryDeletionWithoutBillingAnAdditionalTerm(): void
    {
        $contract = $this->order();
        $this->activate($contract);
        $now = new \DateTimeImmutable('2026-12-01 12:02:00Z');
        $this->contracts->cancel($contract, $this->customer, Request::create('/shop'), $now);
        $this->automation->run($now->modify('+1 minute'), Request::create('/shop'));
        self::assertNotNull($contract->getCancelledAt());
        self::assertSame('active', $contract->getStatus());
        self::assertSame(1, $this->em->getRepository(Invoice::class)->count([]));
        self::assertSame('succeeded', $this->em->getRepository(RegistrarOperation::class)->findOneBy(['action' => 'cancel'])->getStatus());
    }

    public static function pendingRenewalResponses(): iterable
    {
        yield 'pending registrar job' => [false];
        yield 'lost paid response' => [true];
        yield 'failed renewal' => [false, true];
    }

    #[DataProvider('pendingRenewalResponses')]
    public function testCancellationWaitsForAnInFlightRenewalBeforeSchedulingRegistrarExpiry(bool $lost, bool $failed = false): void
    {
        $contract = $this->order();
        $this->activate($contract);
        $this->jobPending = true;
        $this->loseRenewalResponse = $lost;
        $now = new \DateTimeImmutable('2027-11-25 12:00:00Z');
        $this->automation->run($now, Request::create('/shop'));
        $renewal = $this->em->getRepository(RegistrarOperation::class)->findOneBy(['contract' => $contract, 'action' => 'renew']);
        self::assertSame($lost ? 'review' : 'pending', $renewal->getStatus());
        $this->contracts->cancel($contract, $this->customer, Request::create('/shop'), $now->modify('+1 minute'));
        $this->automation->run($now->modify('+3 minutes'), Request::create('/shop'));
        self::assertSame(0, $this->cancellations, 'A still-running renewal may remove an earlier registrar cancellation.');
        self::assertSame(1, $this->em->getRepository(Invoice::class)->count([]));
        if ($lost) {
            $renewal->reconcile((string) $this->remoteJob);
            $this->em->flush();
        }
        $this->jobPending = false;
        $this->jobFailure = $failed;
        $this->automation->run($now->modify('+5 minutes'), Request::create('/shop'));
        $this->automation->run($now->modify('+7 minutes'), Request::create('/shop'));
        $this->automation->run($now->modify('+9 minutes'), Request::create('/shop'));
        self::assertSame(1, $this->cancellations);
        self::assertSame($failed ? 'failed' : 'succeeded', $renewal->getStatus());
        if (!$failed) {
            self::assertNotNull($renewal->getAccountedAt());
        }
        self::assertSame(1, $this->em->getRepository(RegistrarOperation::class)->count(['contract' => $contract, 'action' => 'cancel']));
        self::assertSame(1, $this->em->getRepository(Invoice::class)->count([]));
        self::assertSame('2027-12-01', $contract->getTermEndsAt()->format('Y-m-d'));
    }

    public function testCancellationBeforeRegistrarSubmissionDoesNotCreateARegistrationOrRegistrarCancellation(): void
    {
        $contract = $this->order();
        $this->contracts->cancel($contract, $this->customer, Request::create('/shop'));
        $this->automation->run(new \DateTimeImmutable('2026-12-01 12:00:00Z'), Request::create('/shop'));
        self::assertCount(0, $this->submitted);
        self::assertSame(0, $this->cancellations);
        self::assertSame('ended', $contract->getStatus());
        self::assertSame(0, $contract->getAccount()->getPostpaidReservedUnits());
        self::assertSame(0, $this->em->getRepository(Invoice::class)->count([]));
    }

    public function testIncomingTransferAuthcodeIsEncryptedAndClearedAfterSubmission(): void
    {
        $contract = $this->order(['domain_action' => 'transfer', 'auth_code' => 'SYNTHETIC-TRANSFER-CODE']);
        $operation = $this->em->getRepository(RegistrarOperation::class)->findOneBy(['contract' => $contract]);
        self::assertNotSame('SYNTHETIC-TRANSFER-CODE', $operation->getAuthinfoEncrypted());
        self::assertStringNotContainsString('SYNTHETIC-TRANSFER-CODE', json_encode($contract->getConfiguration(), JSON_THROW_ON_ERROR));
        $this->activate($contract);
        self::assertSame('SYNTHETIC-TRANSFER-CODE', $this->submitted[0]['authinfo']);
        self::assertNull($operation->getAuthinfoEncrypted());
    }

    public function testFailedRegistrarJobReleasesTheReservationAndEndsTheUnprovisionedContract(): void
    {
        $this->jobFailure = true;
        $contract = $this->order();
        $start = new \DateTimeImmutable('2026-12-01 12:00:00Z');
        $this->contracts->run($start, Request::create('/shop'));
        $this->automation->run($start->modify('+1 minute'), Request::create('/shop'));
        self::assertSame('ended', $contract->getStatus());
        self::assertSame(0, $contract->getAccount()->getPostpaidReservedUnits());
        self::assertNull($contract->getStartedAt());
        self::assertSame(0, $this->em->getRepository(Invoice::class)->count([]));
    }

    public function testDomainIsNotRenewedBeyondItsPaidTermWhenTheInvoiceLimitIsExhausted(): void
    {
        $contract = $this->order();
        $this->activate($contract);
        $contract->getAccount()->setCreditLimitCents(1200);
        $this->em->flush();
        $now = new \DateTimeImmutable('2027-12-01 12:02:00Z');
        $this->automation->run($now, Request::create('/shop'));
        self::assertSame('stopping', $contract->getStatus());
        self::assertSame('2027-12-01', $contract->getEndedAt()->format('Y-m-d'));
        self::assertSame(1, $this->em->getRepository(Invoice::class)->count([]));
        self::assertSame(0, $this->em->getRepository(RegistrarOperation::class)->count(['action' => 'renew']));
    }
}
