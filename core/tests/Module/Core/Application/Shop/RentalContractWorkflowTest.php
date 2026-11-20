<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application\Shop;

use App\Module\Core\Application\Contracts\ContractQuote;
use App\Module\Core\Application\Contracts\RentalAccounts;
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

final class RentalContractWorkflowTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private User $customer;
    private User $admin;
    private ShopProduct $product;
    private RentalContracts $service;
    private ContractQuote $quotes;
    private RentalAccounts $accounts;
    private array $environment = [];

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        foreach (['DATABASE_URL' => 'sqlite:///:memory:', 'EASYWI_DB_CONFIG_PATH' => '/tmp/easywi-rental-no-config.json'] as $key => $value) {
            $this->environment[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null];
            $_SERVER[$key] = $_ENV[$key] = $value;
        }
        self::bootKernel();
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

    public function testKvmContractBindsTheNativeVmUuidAndRemovesCustomerAssignmentWhenCancelled(): void
    {
        $this->product->getNode()->setMetadata(['kvm_managed' => ['version' => 1, 'supported' => true]]);
        $this->product->configureKind(ShopProductKind::Kvm, ['kvm_template' => 'ubuntu--26.04-v1', 'network' => 'default', 'network_type' => 'network']);
        $this->em->flush();
        $contract = $this->order(extra: ['hostname' => 'customer-vm', 'ssh_keys' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFixtureKey']);
        $start = new \DateTimeImmutable('2026-12-01 12:00:00');
        $this->service->run($start, Request::create('/shop'));
        $job = $this->em->find(\App\Module\AgentOrchestrator\Domain\Entity\AgentJob::class, $contract->getJobId());
        self::assertNotNull($job);
        self::assertSame($contract->getResourceId(), $job->getPayload()['operation_id']);
        self::assertSame(2048, $job->getPayload()['memory_mib']);
        $job->markFinished(\App\Module\AgentOrchestrator\Domain\Enum\AgentJobStatus::Success, $start);
        $this->em->flush();
        $this->service->run($start->modify('+1 minute'), Request::create('/shop'));
        self::assertSame('active', $contract->getStatus());
        $this->service->cancel($contract, $this->customer, Request::create('/shop'), $start->modify('+61 seconds'));
        self::assertSame(0, $this->em->getRepository(\App\Module\Nodes\Domain\Entity\KvmGuestAssignment::class)->count(['customer' => $this->customer]));
        self::assertNotNull($contract->getStopJobId());
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
