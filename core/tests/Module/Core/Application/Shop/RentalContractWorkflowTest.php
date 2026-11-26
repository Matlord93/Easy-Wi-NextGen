<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application\Shop;

use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\AgentOrchestrator\Domain\Enum\AgentJobStatus;
use App\Module\Core\Application\Contracts\ContractQuote;
use App\Module\Core\Application\Contracts\RentalAccounts;
use App\Module\Core\Application\Contracts\RentalCapacity;
use App\Module\Core\Application\Contracts\RentalContracts;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Invoice;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\RentalCredit;
use App\Module\Core\Domain\Entity\ShopCategory;
use App\Module\Core\Domain\Entity\ShopContract;
use App\Module\Core\Domain\Entity\ShopProduct;
use App\Module\Core\Domain\Entity\Site;
use App\Module\Core\Domain\Entity\Template;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InvoiceStatus;
use App\Module\Core\Domain\Enum\JobStatus;
use App\Module\Core\Domain\Enum\ShopProductKind;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Core\Domain\ValueObject\MinuteTariff;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class RentalContractWorkflowTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private User $customer;
    private User $admin;
    private ShopProduct $product;
    private RentalContracts $service;
    private ContractQuote $quotes;
    private RentalAccounts $accounts;
    private \App\Tests\Support\ProxmoxFixture $proxmoxFixture;
    private array $environment = [];

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        foreach (['DATABASE_URL' => 'sqlite:///:memory:', 'EASYWI_DB_CONFIG_PATH' => '/tmp/easywi-rental-no-config.json'] as $key => $value) {
            $this->environment[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null];
            $_SERVER[$key] = $_ENV[$key] = $value;
        }
        self::bootKernel();
        $this->proxmoxFixture = new \App\Tests\Support\ProxmoxFixture();
        self::getContainer()->set(\App\Module\Nodes\Application\Proxmox\ProxmoxApiClient::class, $this->proxmoxFixture->client());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->customer = new User('rental@example.test', UserType::Customer);
        $this->customer->setPasswordHash('unused');
        $this->admin = new User('rental-admin@example.test', UserType::Admin);
        $this->admin->setPasswordHash('unused');
        $site = new Site('Rental', 'localhost');
        $node = new Agent('rental-node', ['key_id' => 'fixture', 'nonce' => 'fixture', 'ciphertext' => 'fixture']);
        $node->recordHeartbeat(['os' => 'linux'], 'fixture', null);
        $template = new Template('rental-fixture', 'Fixture', '', null, null, [], './server', [], [], [], [], 'true', 'true', [], [], [], [], ['linux']);
        foreach ([$this->customer, $this->admin, $site, $node, $template] as $e) {
            $this->em->persist($e);
        } $this->em->flush();
        $this->em->persist(new \App\Module\Core\Domain\Entity\RentalCapacityPool('agent:'.$node->getId(), ['cpu' => 1000, 'ram_mib' => 10000000, 'disk_mib' => 10000000, 'ports' => 10000, 'ipv4' => 1000, 'slots' => 10000, 'systems' => 1000]));
        $category = new ShopCategory($site->getId(), 'Rental', 'rental');
        $this->em->persist($category);
        $this->product = new ShopProduct($site->getId(), $category, 'Rental server', '', 43200, $template, $node, 1, 2, 20);
        $this->em->persist($this->product);
        $this->em->flush();
        $this->service = self::getContainer()->get(RentalContracts::class);
        $this->quotes = self::getContainer()->get(ContractQuote::class);
        $this->accounts = self::getContainer()->get(RentalAccounts::class);
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        foreach ($this->environment as $key => [$server,$env]) {
            if (null === $server) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }if (null === $env) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $env;
            }
        }parent::tearDown();
    }

    private function order(string $mode = 'postpaid', array $extra = [], ?string $nonce = null): ShopContract
    {
        $input = array_replace(['billing_mode' => $mode], $extra);
        $quote = $this->quotes->build($this->product, $input, true);

        return $this->service->order($this->customer, $this->product, $input, $nonce ?? bin2hex(random_bytes(16)), ContractQuote::digest($quote));
    }

    private function activate(ShopContract $contract, \DateTimeImmutable $at): void
    {
        $this->service->run($at, Request::create('https://localhost/shop'));
        $job = $this->em->find(Job::class, $contract->getJobId());
        self::assertNotNull($job);
        $job->transitionTo(JobStatus::Running);
        $job->transitionTo(JobStatus::Succeeded);
        (new \ReflectionProperty($job, 'updatedAt'))->setValue($job, $at);
        $this->em->flush();
        $this->service->run($at->modify('+1 minute'), Request::create('https://localhost/shop'));
        self::assertSame('active', $contract->getStatus());
    }

    public function testShopGameserverUsesTheConfiguredNodeDirectoryAndKeepsExistingInstallPath(): void
    {
        $node = $this->product->getProvisioningNode();
        $node->setMetadata(['os' => 'linux', 'instance_base_dirs' => ['/mnt/game-disk', '/srv/other-games']]);
        $this->em->persist(new \App\Module\Core\Domain\Entity\AppSetting('instance_base_dir', 'var/easywi'));
        $this->em->flush();
        $contract = $this->order();
        $instance = $contract->getOrder()->getInstance();
        self::assertNotNull($instance);
        self::assertSame('/mnt/game-disk', $instance->getInstanceBaseDir());
        self::assertStringStartsWith('/mnt/game-disk/gs', $instance->getInstallPath());
        $path = $instance->getInstallPath();
        $node->setMetadata(['os' => 'linux', 'instance_base_dirs' => ['/srv/changed-config']]);
        $this->em->flush();
        $this->service->run(new \DateTimeImmutable(), Request::create('/shop'));
        self::assertSame($path, $instance->getInstallPath());
        self::assertSame('/mnt/game-disk', $instance->getInstanceBaseDir());
    }

    public function testProductCanSelectAnotherInstallDirectoryOfTheSameNode(): void
    {
        $this->product->getProvisioningNode()->setMetadata(['os' => 'linux', 'instance_base_dirs' => ['/mnt/game-disk', '/srv/other-games']]);
        $this->product->configureKind(ShopProductKind::Gameserver, ['instance_base_dir' => '/srv/other-games']);
        $this->em->flush();
        $instance = $this->order()->getOrder()->getInstance();
        self::assertSame('/srv/other-games', $instance->getInstanceBaseDir());
        self::assertStringStartsWith('/srv/other-games/gs', $instance->getInstallPath());
    }

    public static function gameserverSlotTariffs(): iterable
    {
        yield 'legacy product' => [null, null, false, 16];
        yield 'standard tariff' => [48, null, false, 48];
        yield 'variant inherits standard tariff' => [48, null, true, 48];
        yield 'variant overrides standard tariff' => [48, 64, true, 64];
    }

    #[DataProvider('gameserverSlotTariffs')]
    public function testGameserverBookingSetsTheSlotLimitAndInitialInstallValues(?int $productSlots, ?int $variantSlots, bool $useVariant, int $expected): void
    {
        if (null !== $productSlots) {
            $this->product->configureKind(ShopProductKind::Gameserver, ['game_slots' => $productSlots]);
        }
        $this->product->getTemplate()->setStartParams('./server +maxplayers {{MAXPLAYERS}}');
        $input = ['slots' => '1024']; // The customer cannot override the tariff.
        if ($useVariant) {
            $variant = new \App\Module\Core\Domain\Entity\ShopProductVariant($this->product, 'GAME-SLOTS', 'Extended', 43200, 1, 2, 20, true);
            $variant->setSlots($variantSlots);
            $this->em->persist($variant);
            $this->em->flush();
            $input['variant_id'] = (string) $variant->getId();
        }
        $contract = $this->order(extra: $input);
        $instance = $contract->getOrder()->getInstance();
        self::assertSame($expected, $contract->getConfiguration()['game_slots']);
        self::assertSame($expected, $instance->getMaxSlots());
        self::assertSame($expected, $instance->getCurrentSlots());
        self::assertFalse($instance->isLockSlots());

        $this->product->configureKind(ShopProductKind::Gameserver, ['game_slots' => 96]);
        $this->em->flush();
        $this->service->run(new \DateTimeImmutable(), Request::create('/shop'));
        $job = $this->em->find(Job::class, $contract->getJobId());
        self::assertNotNull($job);
        self::assertSame((string) $expected, array_column($job->getPayload()['env_vars'], 'value', 'key')['MAXPLAYERS']);
        self::assertSame($expected, $instance->getMaxSlots());
    }

    public function testOrderAndProvisioningDoNotRequireMerchantCredentialsOrUpfrontInvoice(): void
    {
        $nonce = bin2hex(random_bytes(16));
        $contract = $this->order(nonce: $nonce);
        self::assertSame($contract, $this->order(nonce: $nonce));
        self::assertSame(0, $this->em->getRepository(Invoice::class)->count([]));
        self::assertNull($contract->getStartedAt());
        $now = new \DateTimeImmutable('2026-12-01 12:00:00');
        $this->service->run($now, Request::create('/shop'));
        self::assertSame('provisioning', $contract->getStatus());
        self::assertSame(0, $contract->getChargedMinutes());
        self::assertSame(0, $contract->getPendingUnits());
    }

    public function testCancellationBillsOnlyStartedMinutesAndIsIdempotent(): void
    {
        $contract = $this->order();
        $start = new \DateTimeImmutable('2026-12-01 12:00:00');
        $this->activate($contract, $start);
        $this->service->cancel($contract, $this->customer, Request::create('/shop'), $start->modify('+61 seconds'));
        self::assertSame(2, $contract->getChargedMinutes());
        self::assertSame($start->getTimestamp() + 61, $contract->getEndedAt()->getTimestamp());
        $invoice = $this->em->getRepository(Invoice::class)->findOneBy(['customer' => $this->customer]);
        self::assertSame(2, $invoice->getAmountTotalCents());
        self::assertSame(InvoiceStatus::Open, $invoice->getStatus());
        $this->service->cancel($contract, $this->customer, Request::create('/shop'), $start->modify('+1 day'));
        self::assertSame(1, $this->em->getRepository(Invoice::class)->count([]));
        self::assertSame(2, $contract->getChargedMinutes());
    }

    public function testWalletUsesActualMinutesAndCreatesPaidUsageInvoiceOnce(): void
    {
        $account = $this->accounts->forCustomer($this->customer, 'EUR');
        $this->accounts->credit($account, $this->admin, 100, 'credit-test-001', 'Bank transfer');
        $contract = $this->order('wallet');
        $start = new \DateTimeImmutable('2026-12-01 12:00:00');
        $this->activate($contract, $start);
        $this->service->cancel($contract, $this->customer, Request::create('/shop'), $start->modify('+61 seconds'));
        self::assertSame(98, $account->getBalanceCents());
        $invoice = $this->em->getRepository(Invoice::class)->findOneBy(['customer' => $this->customer]);
        self::assertSame(InvoiceStatus::Paid, $invoice->getStatus());
        self::assertSame(2, $invoice->getAmountTotalCents());
        self::assertSame(1, $this->em->getRepository(RentalCredit::class)->count([]));
        self::assertCount(1, $invoice->getPayments());
    }

    public function testWalletStopsAtLastCoveredMinuteWithoutNegativeBalance(): void
    {
        $account = $this->accounts->forCustomer($this->customer, 'EUR');
        $this->accounts->credit($account, $this->admin, 1, 'credit-one-cent', 'Payment');
        $contract = $this->order('wallet');
        $start = new \DateTimeImmutable('2026-12-01 12:00:00');
        $this->activate($contract, $start);
        $this->service->run($start->modify('+10 minutes'), Request::create('/shop'));
        self::assertSame(1, $contract->getChargedMinutes());
        self::assertSame(0, $account->getBalanceUnits());
        self::assertSame($start->getTimestamp() + 60, $contract->getEndedAt()->getTimestamp());
        self::assertSame('stopping', $contract->getStatus());
    }

    public function testPendingCancellationReleasesWalletWithoutChargingForSetup(): void
    {
        $account = $this->accounts->forCustomer($this->customer, 'EUR');
        $this->accounts->credit($account, $this->admin, 100, 'credit-pending', 'Transfer');
        $contract = $this->order('wallet');
        self::assertLessThan($account->getBalanceUnits(), $account->getAvailableUnits());
        $this->service->cancel($contract, $this->customer, Request::create('/shop'));
        self::assertSame(100, $account->getBalanceCents());
        self::assertSame($account->getBalanceUnits(), $account->getAvailableUnits());
        self::assertSame(0, $contract->getChargedMinutes());
        self::assertSame(0, $this->em->getRepository(Invoice::class)->count([]));
    }

    public function testCreditLimitIncludesUnbilledUsageAndStopsFurtherCharging(): void
    {
        $account = $this->accounts->forCustomer($this->customer, 'EUR');
        $account->setCreditLimitCents(2);
        $this->em->flush();
        $contract = $this->order();
        $start = new \DateTimeImmutable('2026-12-01 12:00:00');
        $this->activate($contract, $start);
        $this->service->run($start->modify('+10 minutes'), Request::create('/shop'));
        self::assertSame(2, $contract->getChargedMinutes());
        self::assertSame($start->getTimestamp() + 120, $contract->getEndedAt()->getTimestamp());
    }

    public function testFractionalCentsAreCarriedAcrossContractsInsteadOfRoundingEachMinute(): void
    {
        $this->product->setPriceMonthlyCents(1000);
        $this->em->flush();
        $start = new \DateTimeImmutable('2026-12-01 12:00:00');
        $first = $this->order();
        $this->activate($first, $start);
        $this->service->cancel($first, $this->customer, Request::create('/shop'), $start->modify('+1 minute'));
        self::assertSame(0, $this->em->getRepository(Invoice::class)->count([]));
        $second = $this->order();
        $start = $start->modify('+1 hour');
        $this->activate($second, $start);
        $this->service->cancel($second, $this->customer, Request::create('/shop'), $start->modify('+43 minutes'));
        $invoice = $this->em->getRepository(Invoice::class)->findOneBy(['customer' => $this->customer]);
        self::assertSame(1, $invoice->getAmountTotalCents());
        self::assertSame(800, $second->getAccount()->getCarry('postpaid'));
    }

    public function testCustomerCannotCreditWalletAndDuplicateAdminCreditsAreIdempotent(): void
    {
        $account = $this->accounts->forCustomer($this->customer, 'EUR');
        $this->accounts->credit($account, $this->admin, 100, 'same-reference', 'Transfer');
        $this->accounts->credit($account, $this->admin, 100, 'same-reference', 'Transfer');
        self::assertSame(100, $account->getBalanceCents());
        $this->expectException(\InvalidArgumentException::class);
        $this->accounts->credit($account, $this->customer, 500, 'forged-reference', 'Forged');
    }

    public function testFixedDomainTermRequiresConfirmedRegistrationAndCancelsAtItsEnd(): void
    {
        $this->product->configureKind(ShopProductKind::Domain, ['term_months' => 12]);
        $this->product->setPriceMonthlyCents(100);
        $this->em->flush();
        $contract = $this->order(extra: ['hostname' => 'example-customer.test']);
        $start = new \DateTimeImmutable('2026-12-01 12:00:00');
        $this->service->run($start, Request::create('/shop'));
        self::assertSame(0, $this->em->getRepository(Invoice::class)->count([]));
        self::assertNull($contract->getStartedAt());
        $this->service->confirmDomain($contract, $this->admin, 'REG-123', Request::create('/shop'), $start);
        $this->service->confirmDomain($contract, $this->admin, 'REG-123', Request::create('/shop'), $start);
        self::assertSame(1200, $this->em->getRepository(Invoice::class)->findOneBy(['customer' => $this->customer])->getAmountTotalCents());
        self::assertSame(1, $this->em->getRepository(Invoice::class)->count([]));
        $this->service->cancel($contract, $this->customer, Request::create('/shop'), $start->modify('+1 day'));
        self::assertNull($contract->getEndedAt());
        $end = $contract->getTermEndsAt();
        $this->service->run($end->modify('+1 minute'), Request::create('/shop'));
        self::assertSame($end->getTimestamp(), $contract->getEndedAt()->getTimestamp());
        self::assertSame(1, $this->em->getRepository(Invoice::class)->count([]));
    }

    public function testUnknownCustomerCannotCancelSomeoneElsesContract(): void
    {
        $contract = $this->order();
        $other = new User('other-rental@example.test', UserType::Customer);
        $other->setPasswordHash('unused');
        $this->em->persist($other);
        $this->em->flush();
        $this->expectException(\DomainException::class);
        $this->service->cancel($contract, $other, Request::create('/shop'));
    }

    public function testPanelHostnameCannotBePurchasedAsAWebspace(): void
    {
        $site = $this->em->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        $site->setHost('panel.example.test');
        $this->product->configureKind(ShopProductKind::Webspace, ['php_version' => '8.4']);
        $this->em->flush();
        $this->expectException(\InvalidArgumentException::class);
        $this->quotes->build($this->product, ['hostname' => 'panel.example.test'], true);
    }

    public static function voiceKinds(): iterable
    {
        yield [ShopProductKind::Teamspeak3];
        yield [ShopProductKind::Teamspeak6];
    }

    #[DataProvider('voiceKinds')]
    public function testVoiceContractUsesExistingAgentProtocolAndRevokesAccessOnCancellation(ShopProductKind $kind): void
    {
        $node = $this->product->getNode();
        $crypto = self::getContainer()->get(\App\Module\Core\Application\SecretsCryptoInterface::class);
        $voice = ShopProductKind::Teamspeak3 === $kind ? new \App\Module\Core\Domain\Entity\Ts3Node('Rental voice', $node, 'https://agent.example.test', $crypto->encrypt('token'), 'https://vendor.example.test/ts.tar.gz', '/opt/teamspeak', 'voice', 'voice.service') : new \App\Module\Core\Domain\Entity\Ts6Node('Rental voice', $node, 'https://agent.example.test', $crypto->encrypt('token'), 'https://vendor.example.test/ts.tar.gz', '/opt/teamspeak6', 'voice6', 'voice6.service');
        $voice->setAdminPassword('fixture-password', $crypto);
        $this->em->persist($voice);
        $this->em->persist(new \App\Module\Core\Domain\Entity\ModuleSetting(ShopProductKind::Teamspeak3 === $kind ? 'ts' : 'ts6', '1.0', true));
        $this->em->flush();
        $this->em->persist(new \App\Module\Core\Domain\Entity\RentalCapacityPool($kind->value.':'.$voice->getId(), ['slots' => 10000, 'systems' => 1000]));
        $this->product->configureKind($kind, ['voice_node_id' => $voice->getId(), 'slots' => 32]);
        $variant = new \App\Module\Core\Domain\Entity\ShopProductVariant($this->product, 'VOICE-64', '64 Slots', 43200, 1, 2, 20);
        $variant->setSlots(64);
        $this->em->persist($variant);
        $this->em->flush();
        $contract = $this->order(extra: ['variant_id' => (string) $variant->getId()]);
        $start = new \DateTimeImmutable('2026-12-01 12:00:00');
        $this->service->run($start, Request::create('/shop'));
        $job = $this->em->find(\App\Module\AgentOrchestrator\Domain\Entity\AgentJob::class, $contract->getJobId());
        self::assertSame(64, $job->getPayload()['params']['slots']);
        self::assertSame(ShopProductKind::Teamspeak3 === $kind ? 'ts3.virtual.create' : 'ts6.virtual.create', $job->getType());
        $server = ShopProductKind::Teamspeak3 === $kind ? $this->em->find(\App\Module\Core\Domain\Entity\Ts3VirtualServer::class, (int) $contract->getResourceId()) : $this->em->find(\App\Module\Core\Domain\Entity\Ts6VirtualServer::class, (int) $contract->getResourceId());
        $server->setSid(123);
        $job->markFinished(\App\Module\AgentOrchestrator\Domain\Enum\AgentJobStatus::Success, $start);
        $this->em->flush();
        $this->service->run($start->modify('+1 minute'), Request::create('/shop'));
        self::assertSame('active', $contract->getStatus());
        $this->service->cancel($contract, $this->customer, Request::create('/shop'), $start->modify('+61 seconds'));
        self::assertNotNull($server->getArchivedAt());
        self::assertNotNull($contract->getStopJobId());
        self::assertSame(2, $contract->getChargedMinutes());
    }

    public static function kvmAddressModes(): iterable
    {
        yield 'legacy DHCP' => [false];
        yield 'static IP pool' => [true];
    }

    #[DataProvider('kvmAddressModes')]
    public function testKvmContractBindsTheNativeVmUuidAndRemovesCustomerAssignmentWhenCancelled(bool $static): void
    {
        $this->configureKvmProduct($static);
        $orderInput = ['hostname' => 'customer-vm', 'ssh_keys' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFixtureKey'];
        $contract = $this->order(extra: $orderInput + ['address_mode' => $static ? 'dhcp' : 'pool']);
        $this->product->configureKind(ShopProductKind::Kvm, array_replace($this->product->getProvisioningOptions(), ['address_mode' => $static ? 'dhcp' : 'pool']));
        $this->em->flush();
        $start = new \DateTimeImmutable('2026-12-01 12:00:00');
        $this->service->run($start, Request::create('/shop'));
        $job = $this->em->find(AgentJob::class, $contract->getJobId());
        self::assertNotNull($job);
        self::assertSame($contract->getResourceId(), $job->getPayload()['operation_id']);
        self::assertSame(2048, $job->getPayload()['memory_mib']);
        self::assertSame($static ? 'pool' : null, $job->getPayload()['address_mode'] ?? null);
        $job->markFinished(AgentJobStatus::Success, $start);
        $this->em->flush();
        $this->service->run($start->modify('+1 minute'), Request::create('/shop'));
        self::assertSame('active', $contract->getStatus());
        $this->service->cancel($contract, $this->customer, Request::create('/shop'), $start->modify('+61 seconds'));
        self::assertSame(0, $this->em->getRepository(\App\Module\Nodes\Domain\Entity\KvmGuestAssignment::class)->count(['customer' => $this->customer]));
        self::assertNotNull($contract->getStopJobId());
    }

    public function testKvmShopRejectsCheckoutWithoutAnAddressPoolForTheSelectedNetwork(): void
    {
        $this->configureKvmProduct(true, 'other-network');
        $availability = self::getContainer()->get(RentalCapacity::class)->inspect($this->product);
        self::assertFalse($availability['orderable']);
        self::assertSame('address_pool_missing', $availability['state']);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('keinen IP-Pool gemeldet');
        $this->order(extra: ['hostname' => 'customer-vm', 'ssh_keys' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFixtureKey']);
    }

    public function testKvmPoolRejectionBeforeCreationReleasesReservedCapacityWithoutBillingOrDeletion(): void
    {
        $this->configureKvmProduct(true);
        $contract = $this->order(extra: ['hostname' => 'customer-vm', 'ssh_keys' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFixtureKey']);
        $start = new \DateTimeImmutable('2026-12-01 12:00:00');
        $this->service->run($start, Request::create('/shop'));
        $job = $this->em->find(AgentJob::class, $contract->getJobId());
        self::assertNotNull($job);
        $job->setResultPayload(['address_pool_rejected' => true]);
        $job->markFinished(AgentJobStatus::Failed, $start);
        $this->em->flush();
        $this->service->run($start->modify('+1 minute'), Request::create('/shop'));
        self::assertSame('ended', $contract->getStatus());
        self::assertSame(0, $contract->getChargedMinutes());
        self::assertNull($contract->getStopJobId());
        self::assertNotNull($contract->getPurgedAt());
        self::assertNotNull($contract->getBackupsPurgedAt());
        self::assertSame(0, $this->em->getRepository(\App\Module\Nodes\Domain\Entity\KvmGuestAssignment::class)->count(['customer' => $this->customer]));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM rental_capacity_leases WHERE released_at IS NULL'));
    }

    private function configureKvmProduct(bool $static, string $poolNetwork = 'default'): void
    {
        $node = $this->product->getNode();
        $node->setMetadata(['kvm_managed' => ['version' => 1, 'supported' => true, 'address_pool_version' => 1]]);
        $options = ['kvm_template' => 'ubuntu--26.04-v1', 'network' => 'default', 'network_type' => 'network'];
        if ($static) {
            $options['address_mode'] = 'pool';
            $job = new AgentJob(Uuid::v4()->toRfc4122(), $node, 'kvm.managed', ['action' => 'inspect']);
            $job->setResultPayload(['inventory' => ['address_pools' => [['network_type' => 'network', 'network' => $poolNetwork, 'family' => 4]]]]);
            $job->markFinished(AgentJobStatus::Success);
            $this->em->persist($job);
        }
        $this->product->configureKind(ShopProductKind::Kvm, $options);
        $this->em->flush();
    }

    public static function proxmoxRentalOutcomes(): iterable
    {
        yield 'successful rental and retirement' => ['retire'];
        yield 'cancel before dispatch' => ['cancel'];
        yield 'mismatched template resources' => ['mismatch'];
        yield 'VM identity changed before retirement' => ['foreign'];
        yield 'provider loses deletion response' => ['lost-delete'];
        yield 'larger plan with synchronous disk growth' => ['resize-sync'];
        yield 'larger plan waits for asynchronous disk growth' => ['resize-async'];
        yield 'larger plan recovers a lost resize response' => ['resize-lost'];
        yield 'profile change must be confirmed before power-on' => ['unconfirmed-profile'];
        yield 'old SSH key must not survive deployment' => ['unconfirmed-key'];
        yield 'network settings must be confirmed before power-on' => ['unconfirmed-network'];
        yield 'SSH configuration changed during first boot' => ['changed-key'];
        yield 'smaller disk plan is rejected during checkout' => ['undersized'];
        yield 'accepted clone with lost response' => ['clone-lost'];
        yield 'VM ID taken by another guest during lost clone' => ['clone-foreign'];
        yield 'cancel while recovering a lost clone response' => ['clone-cancel'];
        yield 'cancel while password confirmation is pending' => ['password-cancel'];
    }

    #[DataProvider('proxmoxRentalOutcomes')]
    public function testProxmoxContractUsesTheCommittedInstallationAndConfirmsRetirement(string $outcome): void
    {
        $container = self::getContainer();
        $fixture = $this->proxmoxFixture;
        $cluster = new \App\Module\Nodes\Domain\Entity\ProxmoxCluster('Rental PVE', new \App\Module\Nodes\Domain\Proxmox\ProxmoxConnection('https://pve.example.test:8006', 'panel@pve!test', 'synthetic'), $container->get(\App\Module\Core\Application\SecretsCrypto::class));
        $this->em->persist($cluster);
        $this->em->flush();
        $pools = $container->get(\App\Module\Nodes\Application\Proxmox\ProxmoxIpPools::class);
        $pools->save($cluster, 'pve-a', new \App\Module\Nodes\Domain\Proxmox\ProxmoxIpRange(4, '192.0.2.10', '192.0.2.11', 24, '192.0.2.1'), 'vmbr0', null, true);
        $this->em->persist(new \App\Module\Core\Domain\Entity\RentalCapacityPool('proxmox:'.$cluster->getId().':pve-a', ['cpu' => 8, 'ram_mib' => 8192, 'disk_mib' => 40960, 'systems' => 2]));
        $this->product->configureKind(ShopProductKind::Proxmox, ['proxmox_cluster_id' => $cluster->getId(), 'proxmox_node' => 'pve-a', 'proxmox_template_id' => 900, 'address_mode' => 'pool']);
        $this->em->flush();

        $fixture->configs[900] = ['template' => 1, 'cores' => 1, 'memory' => 2048, 'scsi0' => 'local-lvm:vm-900-disk-0,size=20G',
            'ide2' => 'local-lvm:vm-900-cloudinit,media=cdrom', 'net0' => 'virtio=52:54:00:12:34:56,bridge=vmbr0'];
        $key = \phpseclib3\Crypt\EC::createKey('Ed25519')->getPublicKey()->toString('OpenSSH');
        $request = Request::create('/shop');
        $now = new \DateTimeImmutable();
        if ('undersized' === $outcome) {
            $this->product->setDiskLimit(19);
            $this->em->flush();
            $this->expectException(\DomainException::class);
            $this->expectExceptionMessage('Vorlage passt nicht zum Tarif');
            $this->order(extra: ['hostname' => 'customer-pve', 'ssh_keys' => $key]);

            return;
        }
        $variantId = 0;
        $largerPlan = str_starts_with($outcome, 'resize-');
        if ($largerPlan) {
            $variant = new \App\Module\Core\Domain\Entity\ShopProductVariant($this->product, 'PVE-PLUS', 'Plus', 43200, 4, 4, 30, true);
            $this->em->persist($variant);
            $this->em->flush();
            $variantId = $variant->getId();
            $fixture->configs[900]['sockets'] = 2;
            $fixture->configs[900]['vcpus'] = 1;
            $fixture->configs[900]['cpulimit'] = 1;
            $fixture->configs[900]['balloon'] = 1024;
        }
        $contract = $this->order(extra: ['hostname' => 'customer-pve', 'ssh_keys' => $key, 'proxmox_template_id' => '101', 'variant_id' => (string) $variantId]);
        $this->service->run($now, $request);
        $installation = $this->em->find(\App\Module\Nodes\Domain\Entity\ProxmoxTask::class, (int) $contract->getJobId());
        self::assertSame('queued', $installation?->getState());
        self::assertSame([], $fixture->writes());
        self::assertCount(1, $pools->leases($installation));
        self::assertSame(900, $installation->getVmId());
        $vmId = $installation->getTargetVmId();
        $sync = $container->get(\App\Module\Nodes\Application\Proxmox\ProxmoxTaskSynchronizer::class);
        $this->em->getConnection()->transactional(static fn (): array => $sync->run(25));
        self::assertSame([], $fixture->writes());
        if ('cancel' === $outcome) {
            $this->service->cancel($contract, $this->customer, $request, $now);
            $sync->run(25);
            self::assertSame([], $fixture->writes());
            self::assertSame('ended', $contract->getStatus());
            self::assertSame([], $pools->leases($installation));
            self::assertSame(0, $contract->getChargedMinutes());

            return;
        }
        if ('mismatch' === $outcome) {
            $fixture->configs[900]['scsi0'] = 'local-lvm:vm-900-disk-0,size=21G';
            $sync->run(25);
            $this->service->run($now->modify('+1 minute'), $request);
            self::assertSame([], $fixture->writes());
            self::assertSame('ended', $contract->getStatus());
            self::assertSame([], $pools->leases($installation));

            return;
        }
        $lostClone = str_starts_with($outcome, 'clone-');
        if ($lostClone) {
            $fixture->lostWritePath = '/nodes/pve-a/qemu/900/clone';
        }
        $sync->run(25);
        self::assertSame('/nodes/pve-a/qemu/900/clone', $fixture->writes()[0]['path']);
        if ($lostClone) {
            self::assertSame('submitting', $installation->getState());
            self::assertNull($installation->getUpid());
            $sync->run(25);
            self::assertSame('submitting', $installation->getState());
            self::assertCount(1, $fixture->writes());
        }
        $fixture->complete = true;
        $fixture->guests[] = ['vmid' => $vmId, 'type' => 'qemu', 'node' => 'pve-a', 'status' => 'stopped'];
        $fixture->configs[$vmId] = ['description' => $fixture->writes()[0]['body']['description'], 'template' => 0] + $fixture->configs[900];
        if ($lostClone) {
            $description = $fixture->configs[$vmId]['description'];
            $fixture->configs[$vmId]['description'] = 'Another VM';
            $fixture->configs[$vmId]['lock'] = 'clone';
            $sync->run(25);
            self::assertSame('submitting', $installation->getState());
            self::assertCount(1, $fixture->writes());
            unset($fixture->configs[$vmId]['lock']);
            if ('clone-foreign' === $outcome) {
                $sync->run(25);
                $this->service->run($now->modify('+1 minute'), $request);
                self::assertSame('identity_mismatch', $installation->getResult());
                self::assertSame('uncertain', $installation->getState());
                self::assertNull($contract->getStartedAt());
                self::assertCount(1, $pools->leases($installation));
                self::assertCount(1, $fixture->writes());

                return;
            }
            $fixture->configs[$vmId]['description'] = $description;
            $fixture->auditPermission = false;
            $sync->run(25);
            self::assertCount(1, $fixture->writes());
            $fixture->auditPermission = true;
            $fixture->propagatePermissions = false;
            $fixture->activeJobs = [['id' => '900', 'type' => 'qmclone']];
            if ('clone-cancel' === $outcome) {
                $this->service->cancel($contract, $this->customer, $request, $now->modify('+1 minute'));
            }
            $sync->run(25);
            self::assertSame('submitting', $installation->getState());
            self::assertCount(1, $fixture->writes(), 'The source worker can remain active after the target unlocks.');
            $fixture->activeJobs = [];
            if ('clone-cancel' === $outcome) {
                $sync->run(25);
                self::assertSame('cancelled', $installation->getResult());
                self::assertNull($contract->getStartedAt());
                self::assertSame(0, $contract->getChargedMinutes());
                self::assertCount(1, $pools->leases($installation));
                self::assertCount(1, $fixture->writes());

                return;
            }
        }
        if (str_starts_with($outcome, 'unconfirmed-')) {
            // The provider acknowledged the update, but its current config is still different.
            [$field, $value] = match ($outcome) {
                'unconfirmed-key' => ['sshkeys', rawurlencode(\phpseclib3\Crypt\EC::createKey('Ed25519')->getPublicKey()->toString('OpenSSH'))],
                'unconfirmed-network' => ['ipconfig0', 'ip=dhcp'],
                default => ['memory', 1024],
            };
            $fixture->configs[$vmId][$field] = $value;
            $fixture->ignoredConfigFields = [$field];
            $sync->run(25);
            $this->service->run($now->modify('+1 minute'), $request);
            self::assertCount(2, $fixture->writes());
            self::assertSame('/nodes/pve-a/qemu/'.$vmId.'/config', $fixture->writes()[1]['path']);
            self::assertSame('uncertain', $installation->getState());
            self::assertSame('unconfirmed-profile' === $outcome ? 'rental_resources_unconfirmed' : 'rental_cloud_init_unconfirmed', $installation->getResult());
            self::assertNull($contract->getStartedAt());
            self::assertSame(0, $contract->getChargedMinutes());
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('pve.error.review_rental_installation');
            $container->get(\App\Module\Nodes\Application\Proxmox\ProxmoxManager::class)->reviewTask($installation, $this->admin, true, 'VM und Auftragsliste geprüft.');

            return;
        }
        $configWrite = 1;
        if ($largerPlan) {
            $fixture->asynchronousResize = 'resize-async' === $outcome;
            if ('resize-lost' === $outcome) {
                $fixture->lostWritePath = '/nodes/pve-a/qemu/'.$vmId.'/resize';
            }
            $sync->run(25);
            self::assertCount(2, $fixture->writes());
            self::assertSame('/nodes/pve-a/qemu/'.$vmId.'/resize', $fixture->writes()[1]['path']);
            self::assertSame(['disk' => 'scsi0', 'size' => '30G', 'digest' => 'fixture-digest'], $fixture->writes()[1]['body']);
            if ('resize-sync' !== $outcome) {
                $sync->run(25);
                self::assertCount(2, $fixture->writes(), 'Polling before the size is visible must not resize again or start the VM.');
                $fixture->configs[$vmId]['scsi0'] = 'local-lvm:vm-'.$vmId.'-disk-0,size=30G';
            }
            if ('resize-lost' === $outcome) {
                $fixture->activeJobs = [['id' => (string) $vmId, 'type' => 'qmresize']];
                $sync->run(25);
                self::assertCount(2, $fixture->writes(), 'A lost request may still have an active storage job.');
                $fixture->activeJobs = [];
            }
            $configWrite = 2;
        }
        $sync->run(25);
        self::assertSame('ip=192.0.2.10/24,gw=192.0.2.1', $fixture->writes()[$configWrite]['body']['ipconfig0']);
        self::assertSame($largerPlan ? '4096' : '2048', $fixture->writes()[$configWrite]['body']['memory']);
        self::assertSame($largerPlan ? '4' : '1', $fixture->writes()[$configWrite]['body']['cores']);
        foreach (['sockets' => '1', 'balloon' => '0', 'cpulimit' => '0'] as $key => $expected) {
            self::assertSame($expected, $fixture->writes()[$configWrite]['body'][$key]);
        }
        self::assertSame('/nodes/pve-a/qemu/'.$vmId.'/status/start', $fixture->writes()[$configWrite + 1]['path']);
        $fixture->guests[3]['status'] = 'running';
        $sync->run(25);
        $this->service->run($now->modify('+1 minute'), $request);
        self::assertSame('provisioning', $contract->getStatus());
        self::assertNull($contract->getStartedAt());
        $fixture->cloudInitFinished = true;
        // The provider may return the same network properties in a different order.
        $fixture->configs[$vmId]['ipconfig0'] = 'gw=192.0.2.1,ip=192.0.2.10/24';
        if ('changed-key' === $outcome) {
            $fixture->configs[$vmId]['sshkeys'] = '';
        }
        if ('password-cancel' === $outcome) {
            $fixture->rootPasswordPermission = true;
            $fixture->loseResponseAfterWrite = '/nodes/pve-a/qemu/'.$vmId.'/agent/set-user-password';
        }
        $sync->run(25);
        $this->service->run($now->modify('+2 minutes'), $request);
        if ('password-cancel' === $outcome) {
            $assignments = $this->em->getRepository(\App\Module\Nodes\Domain\Entity\ProxmoxGuestAssignment::class);
            self::assertSame('submitting', $installation->getState());
            self::assertSame('setting_password', $installation->getInstallation()['phase']);
            self::assertSame('provisioning', $contract->getStatus());
            self::assertNull($contract->getStartedAt());
            self::assertSame(0, $assignments->count(['customer' => $this->customer]));
            $writes = count($fixture->writes());
            $this->service->cancel($contract, $this->customer, $request, $now->modify('+3 minutes'));
            $sync->run(25);
            self::assertSame('cancelled', $installation->getResult());
            self::assertArrayNotHasKey('root_password_setup', $installation->getInstallation());
            self::assertCount($writes, $fixture->writes());
            self::assertSame(0, $contract->getChargedMinutes());

            return;
        }
        if ('changed-key' === $outcome) {
            self::assertSame('rental_cloud_init_unconfirmed', $installation->getResult());
            self::assertSame('uncertain', $installation->getState());
            self::assertNull($contract->getStartedAt());
            self::assertSame(0, $contract->getChargedMinutes());

            return;
        }
        self::assertSame('active', $contract->getStatus());
        $assignments = $this->em->getRepository(\App\Module\Nodes\Domain\Entity\ProxmoxGuestAssignment::class);
        self::assertSame(1, $assignments->count(['customer' => $this->customer]));

        $this->service->cancel($contract, $this->customer, $request, $now->modify('+3 minutes'));
        self::assertSame(0, $assignments->count(['customer' => $this->customer]));
        $sync->run(25);
        self::assertSame('/nodes/pve-a/qemu/'.$vmId.'/status/stop', array_slice($fixture->writes(), -1)[0]['path']);
        $fixture->guests[3]['status'] = 'stopped';
        $sync->run(25);
        $this->service->run($now->modify('+4 minutes'), $request);
        self::assertSame('ended', $contract->getStatus());
        self::assertCount(1, $pools->leases($installation));

        $container->get(\App\Module\Core\Application\Contracts\RentalSettings::class)->save(['cleanup_enabled' => true]);
        $cleanup = $container->get(\App\Module\Core\Application\Contracts\RentalCleanup::class);
        $cleanup->run($now->modify('+100 days'));
        self::assertSame([], $contract->getCleanupJobs());
        $cleanup->run($now->modify('+108 days'));
        self::assertCount(1, $contract->getCleanupJobs());
        if ('foreign' === $outcome) {
            $fixture->configs[$vmId]['description'] = 'Different VM';
        }
        if ('lost-delete' === $outcome) {
            $fixture->lostWritePath = '/nodes/pve-a/qemu/'.$vmId;
        }
        $writes = count($fixture->writes());
        $sync->run(25);
        if ('foreign' === $outcome) {
            self::assertCount($writes, $fixture->writes());
            self::assertCount(1, $pools->leases($installation));

            return;
        }
        self::assertSame('DELETE', array_slice($fixture->writes(), -1)[0]['method']);
        self::assertCount(1, $pools->leases($installation));
        unset($fixture->guests[3]);
        $fixture->auditPermission = false;
        $sync->run(25);
        self::assertCount(1, $pools->leases($installation));
        $fixture->auditPermission = true;
        $fixture->propagatePermissions = false;
        $sync->run(25);
        self::assertCount($writes + 1, $fixture->writes());
        $cleanup->run($now->modify('+109 days'));
        self::assertNotNull($contract->getPurgedAt());
        self::assertSame([], $pools->leases($installation));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM rental_capacity_leases WHERE released_at IS NULL'));
    }

    public function testHostingWaitsForBothPoolAndVhostBeforeBillingAndRetainsFilesOnCancellation(): void
    {
        $this->product->configureKind(ShopProductKind::Webspace, ['php_version' => '8.4']);
        $this->em->flush();
        $contract = $this->order(extra: ['hostname' => 'hosting-customer.test']);
        $start = new \DateTimeImmutable('2026-12-01 12:00:00');
        $this->service->run($start, Request::create('/shop'));
        $job = $this->em->find(Job::class, $contract->getJobId());
        $job->transitionTo(JobStatus::Running);
        $job->transitionTo(JobStatus::Succeeded);
        (new \ReflectionProperty($job, 'updatedAt'))->setValue($job, $start);
        $this->em->flush();
        $this->service->run($start->modify('+1 minute'), Request::create('/shop'));
        self::assertNull($contract->getStartedAt());
        self::assertCount(1, $contract->getAuxiliaryJobs());
        $vhost = $this->em->find(Job::class, $contract->getAuxiliaryJobs()[0]);
        $vhost->transitionTo(JobStatus::Running);
        $vhost->transitionTo(JobStatus::Succeeded);
        (new \ReflectionProperty($vhost, 'updatedAt'))->setValue($vhost, $start->modify('+75 seconds'));
        $this->em->flush();
        $this->service->run($start->modify('+2 minutes'), Request::create('/shop'));
        self::assertSame($start->getTimestamp() + 75, $contract->getStartedAt()->getTimestamp());
        $this->service->cancel($contract, $this->customer, Request::create('/shop'), $start->modify('+3 minutes'));
        $web = $this->em->find(\App\Module\Core\Domain\Entity\Webspace::class, (int) $contract->getResourceId());
        self::assertSame('suspended', $web->getStatus());
        self::assertNull($web->getDeletedAt());
        foreach ($contract->getStopJobs() as $id) {
            $stop = $this->em->find(Job::class, $id);
            self::assertSame('remove', $stop->getPayload()['action']);
            self::assertSame('webspace.domain.apply', $stop->getType());
        }
    }

    public function testCancellationDuringInstallationDoesNotGetUndoneByLateProvisioningSuccess(): void
    {
        $contract = $this->order();
        $start = new \DateTimeImmutable('2026-12-01 12:00:00');
        $this->service->run($start, Request::create('/shop'));
        $this->service->cancel($contract, $this->customer, Request::create('/shop'), $start->modify('+10 seconds'));
        self::assertNull($contract->getStopJobId());
        $job = $this->em->find(Job::class, $contract->getJobId());
        $job->transitionTo(JobStatus::Running);
        $job->transitionTo(JobStatus::Succeeded);
        $this->em->flush();
        $this->service->run($start->modify('+1 minute'), Request::create('/shop'));
        self::assertNotNull($contract->getStopJobId());
        self::assertNull($contract->getStartedAt());
        self::assertSame(0, $contract->getChargedMinutes());
        self::assertSame(0, $this->em->getRepository(Invoice::class)->count([]));
    }

    public function testExistingMutationEndpointsAndSchedulesCannotReactivateEndedRentalResources(): void
    {
        $contract = $this->order();
        $start = new \DateTimeImmutable('2026-12-01 12:00:00');
        $this->activate($contract, $start);
        $schedule = new \App\Module\Core\Domain\Entity\InstanceSchedule($contract->getOrder()->getInstance(), $this->customer, \App\Module\Core\Domain\Enum\InstanceScheduleAction::Start, '* * * * *', 'UTC', true);
        $definition = new \App\Module\Core\Domain\Entity\BackupDefinition($this->customer, \App\Module\Core\Domain\Enum\BackupTargetType::Game, (string) $contract->getOrder()->getInstance()->getId(), 'Rental backup');
        $backupSchedule = new \App\Module\Core\Domain\Entity\BackupSchedule($definition, '* * * * *', 7, 7, true);
        $definition->setSchedule($backupSchedule);
        foreach ([$schedule, $definition, $backupSchedule] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->service->cancel($contract, $this->customer, Request::create('/shop'), $start->modify('+61 seconds'));
        self::assertFalse($schedule->isEnabled());
        self::assertFalse($backupSchedule->isEnabled());
        $controller = (new \ReflectionClass(\App\Module\Gameserver\UI\Controller\Customer\CustomerInstanceController::class))->newInstanceWithoutConstructor();
        $request = Request::create('/instances/'.$contract->getOrder()->getInstance()->getId().'/install', 'POST');
        $request->attributes->set('current_user', $this->customer);
        $event = new \Symfony\Component\HttpKernel\Event\ControllerEvent(self::$kernel, [$controller, 'install'], $request, \Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST);
        self::getContainer()->get(\App\Module\Core\EventSubscriber\RentalResourceAccessSubscriber::class)->check($event);
        $response = ($event->getController())();
        self::assertSame(403, $response->getStatusCode());
    }

    public function testMonthlyInvoiceDoesNotBillTheSameStartedMinuteTwice(): void
    {
        $contract = $this->order();
        $start = new \DateTimeImmutable('2026-12-31 23:58:30');
        $this->activate($contract, $start);
        $midnight = new \DateTimeImmutable('2027-01-01 00:00:00');
        $this->service->run($midnight, Request::create('/shop'));
        self::assertSame(2, $this->em->getRepository(Invoice::class)->findOneBy(['customer' => $this->customer])->getAmountTotalCents());
        $this->service->run($midnight->modify('+1 second'), Request::create('/shop'));
        self::assertSame(1, $this->em->getRepository(Invoice::class)->count([]));
        $this->service->cancel($contract, $this->customer, Request::create('/shop'), $midnight->modify('+61 seconds'));
        $invoices = $this->em->getRepository(Invoice::class)->findBy(['customer' => $this->customer]);
        self::assertSame(3, array_sum(array_map(static fn (Invoice $i) => $i->getAmountTotalCents(), $invoices)));
    }

    public function testTariffCountsStartedMinutesWithoutFloatingPointMoney(): void
    {
        $start = new \DateTimeImmutable('2026-12-01 12:00:00');
        self::assertSame(0, MinuteTariff::minutes($start, $start));
        self::assertSame(1, MinuteTariff::minutes($start, $start->modify('+1 second')));
        self::assertSame(1, MinuteTariff::minutes($start, $start->modify('+60 seconds')));
        self::assertSame(2, MinuteTariff::minutes($start, $start->modify('+61 seconds')));
    }

    public function testJavaContractInstallKeepsNativeMemoryReserveInItsJob(): void
    {
        $this->product->getGameTemplate()->setInstallResolver(['type' => 'minecraft_vanilla']);
        $this->product->getNode()->recordHeartbeat(['os' => 'linux'], 'new-agent', '127.0.0.1', [], ['os' => 'linux', 'minecraft_managed_install' => true, 'instance_runtime_limits' => true]);
        $entry = new \App\Module\Core\Domain\Entity\MinecraftVersionCatalog('vanilla', '1.21.11', null, 'https://example.test/server.jar');
        $entry->setJavaVersion('21');
        $entry->setMetadata(['release_type' => 'release']);
        $this->em->persist($entry);
        $this->em->flush();
        $contract = $this->order();
        $this->service->run(new \DateTimeImmutable(), Request::create('/shop'));
        $job = $this->em->find(Job::class, $contract->getJobId());
        self::assertNotNull($job, $contract->getStatus());
        self::assertSame('2560', $job->getPayload()['ram_limit']);
        self::assertSame(2048, $contract->getOrder()->getInstance()->getRamLimit());
        self::assertSame('2048M', array_column($job->getPayload()['env_vars'], 'value', 'key')['JAVA_XMX']);
    }

    public function testJavaCapacityIncludesNativeReserveWithoutReducingPurchasedRam(): void
    {
        $this->product->getGameTemplate()->setInstallResolver(['type' => 'minecraft_vanilla']);
        $this->product->setRamLimit(4);
        $pool = $this->em->find(\App\Module\Core\Domain\Entity\RentalCapacityPool::class, 'agent:'.$this->product->getNode()->getId());
        $pool->configure(['cpu' => 2, 'ram_mib' => 5120, 'disk_mib' => 40960, 'systems' => 2], true);
        $this->em->flush();
        $quote = $this->quotes->build($this->product, ['billing_mode' => 'postpaid'], true);
        self::assertSame(4096, $quote['ram_mib']);
        self::assertSame(5120, $quote['runtime_ram_mib']);
        self::assertSame(43200, $quote['monthly_cents']);
        $contract = $this->order();
        self::assertSame(4096, $contract->getOrder()->getInstance()->getRamLimit());
        $capacity = self::getContainer()->get(\App\Module\Core\Application\Contracts\RentalCapacity::class);
        self::assertSame(0, $capacity->available($pool)['ram_mib']);
        // Historical leases did not include a native reserve. Account for it
        // without rewriting existing tariffs or reservations.
        $this->em->getConnection()->executeStatement('UPDATE rental_capacity_leases SET ram_mib=4096 WHERE contract_id=?', [$contract->getId()]);
        self::assertSame(0, $capacity->available($pool)['ram_mib']);
    }

    public function testCapacityIsHeldBeforeProvisioningAndReleasedWhenAnUnprovisionedOrderIsCancelled(): void
    {
        $pool = $this->em->find(\App\Module\Core\Domain\Entity\RentalCapacityPool::class, 'agent:'.$this->product->getNode()->getId());
        $pool->configure(['cpu' => 1, 'ram_mib' => 2048, 'disk_mib' => 20480, 'systems' => 1], true);
        $this->em->flush();
        $contract = $this->order();
        $capacity = self::getContainer()->get(\App\Module\Core\Application\Contracts\RentalCapacity::class);
        self::assertSame(0, $capacity->available($pool)['systems']);
        self::assertNotNull($contract->getOrder()->getInstance());
        $this->service->cancel($contract, $this->customer, Request::create('/shop'));
        self::assertSame(1, $capacity->available($pool)['systems']);
        self::assertSame('ended', $contract->getStatus());
        self::assertNotSame($contract->getId(), $this->order()->getId());
    }

    public function testProvisioningTimeoutStopsBillingWithoutPretendingThatAnInFlightInstallationWasDeleted(): void
    {
        $contract = $this->order();
        $start = new \DateTimeImmutable('2026-12-01 12:00:00');
        $this->service->run($start, Request::create('/shop'));
        $this->service->run($start->modify('+121 minutes'), Request::create('/shop'));
        self::assertSame('stopping', $contract->getStatus());
        self::assertSame(0, $contract->getChargedMinutes());
        self::assertNull($contract->getStartedAt());
        self::assertNull($contract->getStopJobId());
        self::assertSame(0, $contract->getAccount()->getPostpaidReservedUnits());
        self::assertSame(0, $this->em->getRepository(Invoice::class)->count([]));
    }

    public function testSchedulerOutageDoesNotDiscardAnInstallationThatCompletedWithinItsDeadline(): void
    {
        $contract = $this->order();
        $start = new \DateTimeImmutable('2026-12-01 12:00:00');
        $this->service->run($start, Request::create('/shop'));
        $job = $this->em->find(Job::class, $contract->getJobId());
        $job->transitionTo(JobStatus::Running);
        $job->transitionTo(JobStatus::Succeeded);
        (new \ReflectionProperty($job, 'updatedAt'))->setValue($job, $start->modify('+5 minutes'));
        $this->em->flush();
        $this->service->run($start->modify('+3 hours'), Request::create('/shop'));
        self::assertSame('active', $contract->getStatus());
        self::assertSame($start->getTimestamp() + 300, $contract->getStartedAt()->getTimestamp());
        self::assertSame(175, $contract->getChargedMinutes());
    }

    public function testWalletLedgerReconcilesSettledAndRunningUsageWithoutOnePostingPerMinute(): void
    {
        $account = $this->accounts->forCustomer($this->customer, 'EUR');
        $this->accounts->credit($account, $this->admin, 100, 'ledger-credit-01', 'Bank transfer');
        $contract = $this->order('wallet');
        $start = new \DateTimeImmutable('2026-12-01 12:00:00');
        $this->activate($contract, $start);
        $this->service->run($start->modify('+2 minutes'), Request::create('/shop'));
        self::assertSame(1, $this->em->getRepository(\App\Module\Core\Domain\Entity\WalletEntry::class)->count([]));
        $posted = (int) $this->em->getConnection()->fetchOne('SELECT SUM(units) FROM wallet_entries WHERE account_id=?', [$account->getId()]);
        self::assertSame($account->getBalanceUnits(), $posted - $contract->getPendingUnits());
        $this->service->cancel($contract, $this->customer, Request::create('/shop'), $start->modify('+121 seconds'));
        self::assertSame(2, $this->em->getRepository(\App\Module\Core\Domain\Entity\WalletEntry::class)->count([]));
        self::assertSame($account->getBalanceUnits(), (int) $this->em->getConnection()->fetchOne('SELECT SUM(units) FROM wallet_entries WHERE account_id=?', [$account->getId()]));
    }

    public function testCorrectionsAndRecordedRefundsAreAuditedAndIdempotent(): void
    {
        $account = $this->accounts->forCustomer($this->customer, 'EUR');
        $this->accounts->credit($account, $this->admin, 100, 'refund-credit-01', 'Bank transfer');
        $this->accounts->adjust($account, $this->admin, -40, 'payout-bank-001', 'Already paid to customer', true);
        $this->accounts->adjust($account, $this->admin, -40, 'payout-bank-001', 'Already paid to customer', true);
        self::assertSame(60, $account->getBalanceCents());
        self::assertSame(2, $this->em->getRepository(\App\Module\Core\Domain\Entity\WalletEntry::class)->count([]));
        self::assertSame($account->getBalanceUnits(), (int) $this->em->getConnection()->fetchOne('SELECT SUM(units) FROM wallet_entries'));
    }

    public function testCleanupRequiresNoticeGracePeriodAndAcknowledgedDeletionBeforeReleasingInventory(): void
    {
        $this->em->getConnection()->executeStatement('CREATE TABLE backup_plans (record_key VARCHAR(64) PRIMARY KEY, payload TEXT NOT NULL)');
        self::getContainer()->get(\App\Module\Core\Application\Contracts\RentalSettings::class)->save(['cleanup_enabled' => true, 'data_retention_days' => 1, 'backup_retention_days' => 12]);
        $contract = $this->order();
        $start = new \DateTimeImmutable('2026-12-01 12:00:00');
        $this->activate($contract, $start);
        $this->service->cancel($contract, $this->customer, Request::create('/shop'), $start->modify('+61 seconds'));
        $stop = $this->em->find(Job::class, $contract->getStopJobId());
        $stop->transitionTo(JobStatus::Running);
        $stop->transitionTo(JobStatus::Succeeded);
        $this->em->flush();
        $this->service->run($start->modify('+2 minutes'), Request::create('/shop'));
        $cleanup = self::getContainer()->get(\App\Module\Core\Application\Contracts\RentalCleanup::class);
        $cleanup->run($start->modify('+2 days'));
        self::assertNotNull($contract->getDeletionNoticeAt());
        self::assertSame($contract->getEndedAt()->modify('+12 days')->getTimestamp(), $contract->getDeleteAfterAt()->getTimestamp());
        self::assertSame([], $contract->getCleanupJobs());
        $contract->retain(true);
        $this->em->flush();
        $cleanup->run($start->modify('+10 days'));
        self::assertSame([], $contract->getCleanupJobs());
        $contract->retain(false);
        $this->em->flush();
        $cleanup->run($start->modify('+10 days'));
        self::assertSame([], $contract->getCleanupJobs(), 'A longer backup retention must protect colocated backups.');
        $cleanup->run($start->modify('+13 days'));
        self::assertCount(1, $contract->getCleanupJobs());
        self::assertNull($contract->getPurgedAt());
        $job = $this->em->find(Job::class, $contract->getCleanupJobs()[0]['id']);
        self::assertSame('instance.delete', $job->getType());
        $job->transitionTo(JobStatus::Running);
        $job->transitionTo(JobStatus::Succeeded);
        $this->em->flush();
        $cleanup->run($start->modify('+13 days +2 minutes'));
        self::assertNotNull($contract->getPurgedAt());
        self::assertNotNull($contract->getBackupsPurgedAt());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM rental_capacity_leases WHERE released_at IS NULL'));
    }

    public static function startedCleanupStates(): iterable
    {
        yield 'core deletion queued' => ['core'];
        yield 'agent deletion queued' => ['agent'];
        yield 'backup deletion queued' => ['backups'];
        yield 'resource already deleted' => ['purged'];
    }

    #[DataProvider('startedCleanupStates')]
    public function testRetentionProtectionCannotBePromisedAfterCleanupWasHandedOff(string $state): void
    {
        $contract = $this->order();
        if ('purged' === $state) {
            $contract->purged(new \DateTimeImmutable());
        } elseif ('backups' === $state) {
            $contract->backupCleanupJobs([['system' => 'agent', 'id' => 'queued-backup-delete']]);
        } else {
            $contract->cleanupJobs([['system' => $state, 'id' => 'queued-resource-delete']]);
        }
        $rejected = false;
        try {
            $contract->retain(true);
        } catch (\DomainException) {
            $rejected = true;
        }
        self::assertTrue($rejected, 'An already dispatched deletion cannot be recalled by changing a panel flag.');
        self::assertFalse($contract->isRetentionHold());
        $contract->retain(false);
        self::assertFalse($contract->isRetentionHold());
    }

    public function testLateFirstDeletionNoticeDisplaysTheActualSevenDayGraceDeadline(): void
    {
        self::getContainer()->get(\App\Module\Core\Application\Contracts\RentalSettings::class)->save(['cleanup_enabled' => true, 'data_retention_days' => 1, 'backup_retention_days' => 1]);
        $contract = $this->order();
        $start = new \DateTimeImmutable('2026-12-01 12:00:00');
        $this->activate($contract, $start);
        $this->service->cancel($contract, $this->customer, Request::create('/shop'), $start->modify('+61 seconds'));
        $stop = $this->em->find(Job::class, $contract->getStopJobId());
        $stop->transitionTo(JobStatus::Running);
        $stop->transitionTo(JobStatus::Succeeded);
        $this->em->flush();
        $this->service->run($start->modify('+2 minutes'), Request::create('/shop'));
        $noticed = $start->modify('+45 days');
        $cleanup = self::getContainer()->get(\App\Module\Core\Application\Contracts\RentalCleanup::class);
        $cleanup->run($noticed);
        self::assertSame($noticed->modify('+7 days')->getTimestamp(), $contract->getDeleteAfterAt()->getTimestamp());
        self::assertSame([], $contract->getCleanupJobs());
        $notice = $this->em->getRepository(\App\Module\Core\Domain\Entity\RentalNotice::class)->findOneBy(['eventKey' => 'rental.'.$contract->getId().'.deletion-notice']);
        self::assertNotNull($notice);
        self::assertStringContainsString($contract->getDeleteAfterAt()->format('d.m.Y'), $notice->getBody());
        $cleanup->run($noticed->modify('+6 days'));
        self::assertSame([], $contract->getCleanupJobs());
        self::assertNull($contract->getPurgedAt());
    }
}
