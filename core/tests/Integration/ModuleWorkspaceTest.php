<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\AuditLog;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\ModuleSetting;
use App\Module\Core\Domain\Entity\Template;
use App\Module\Core\Domain\Entity\Ts3Node;
use App\Module\Core\Domain\Entity\Ts3VirtualServer;
use App\Module\Core\Domain\Entity\Ts6Node;
use App\Module\Core\Domain\Entity\Ts6VirtualServer;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Gameserver\UI\Controller\Admin\AdminInstanceController;
use App\Module\Voice\UI\Controller\Admin\AdminVoiceWorkspaceController;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class ModuleWorkspaceTest extends KernelTestCase
{
    private array $previousDatabase;
    private EntityManagerInterface $em;
    private User $admin;
    private User $customer;
    private Agent $agent;
    private Request $request;

    protected function setUp(): void
    {
        $this->previousDatabase = [$_SERVER['DATABASE_URL'] ?? null, $_ENV['DATABASE_URL'] ?? null];
        $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->admin = new User('admin-ui@example.test', UserType::Admin);
        $this->customer = new User('customer-ui@example.test', UserType::Customer);
        $this->agent = new Agent('ui-agent', ['key_id' => 'fixture', 'nonce' => 'fixture', 'ciphertext' => 'fixture']);
        foreach ([$this->admin, $this->customer] as $user) {
            $user->setPasswordHash('fixture');
        }
        foreach ([$this->admin, $this->customer, $this->agent, new ModuleSetting('ts', '1', true), new ModuleSetting('ts6', '1', true)] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->request = Request::create('/admin/voice', 'POST');
        $this->request->setSession(new Session(new MockArraySessionStorage()));
        $this->request->attributes->set('current_user', $this->admin);
        self::getContainer()->get(RequestStack::class)->push($this->request);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(RequestStack::class)->pop();
        self::ensureKernelShutdown();
        foreach (['SERVER', 'ENV'] as $index => $global) {
            if (null === $this->previousDatabase[$index]) {
                unset($GLOBALS['_'.$global]['DATABASE_URL']);
            } else {
                $GLOBALS['_'.$global]['DATABASE_URL'] = $this->previousDatabase[$index];
            }
        }
        parent::tearDown();
    }

    public static function providers(): iterable
    {
        yield 'TS3' => ['ts3'];
        yield 'TS6' => ['ts6'];
    }

    #[DataProvider('providers')]
    public function testVoiceEditorPreservesNativeNameAndPersistsOnlyValidatedSettings(string $provider): void
    {
        $nodeClass = 'ts3' === $provider ? Ts3Node::class : Ts6Node::class;
        $node = new $nodeClass('UI node', $this->agent, 'http://agent.invalid', 'fixture', 'https://download.invalid', '/srv/voice', 'voice', 'voice');
        $server = 'ts3' === $provider ? new Ts3VirtualServer($node, $this->customer->getId(), 17, 'Native name') : new Ts6VirtualServer($node, $this->customer->getId(), 17, 'Native name', 64);
        foreach ([$node, $server] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $controller = self::getContainer()->get(AdminVoiceWorkspaceController::class);
        $this->request->setMethod('GET');
        self::assertStringContainsString('/admin/voice/'.$provider.'/'.$server->getId().'/edit', $controller->index($this->request)->getContent());
        self::assertSame(200, $controller->edit($this->request, $provider, $server->getId())->getStatusCode());
        $this->request->setMethod('POST');
        $this->request->request->replace(['_token' => self::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('admin_voice_edit_'.$provider.'_'.$server->getId())->getValue(), 'customer_id' => $this->customer->getId(), 'public_host' => 'voice.example.test']);
        self::assertSame(302, $controller->edit($this->request, $provider, $server->getId())->getStatusCode());
        $this->em->refresh($server);
        self::assertSame('voice.example.test', $server->getPublicHost());
        self::assertSame('Native name', $server->getName());
        self::assertCount(1, $this->em->getRepository(AuditLog::class)->findBy(['action' => 'voice.admin.settings']));
        $this->request->request->set('public_host', 'https://invalid.example/path');
        self::assertSame(422, $controller->edit($this->request, $provider, $server->getId())->getStatusCode());
        $this->em->refresh($server);
        self::assertSame('voice.example.test', $server->getPublicHost());
        $this->request->request->set('_token', 'invalid');
        $this->expectException(AccessDeniedHttpException::class);
        $controller->edit($this->request, $provider, $server->getId());
    }

    public function testCustomerCannotOpenAdminWorkspace(): void
    {
        $this->request->attributes->set('current_user', $this->customer);
        $this->expectException(AccessDeniedHttpException::class);
        self::getContainer()->get(AdminVoiceWorkspaceController::class)->index($this->request);
    }

    public function testGameEditorRejectsCsrfAndResourceReductionThenSavesValidatedIncrease(): void
    {
        $template = new Template('ui-test', 'UI Game', null, null, null, [], '', [], [], [], [], '', '', [], []);
        $instance = new Instance($this->customer, $template, $this->agent, 100, 1024, 2048, null, InstanceStatus::Stopped);
        foreach ([$template, $instance] as $entity) {
            $this->em->persist($entity);
        } $this->em->flush();
        $controller = self::getContainer()->get(AdminInstanceController::class);
        $page = $controller->edit($this->request, $instance->getId());
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('/admin/instances/'.$instance->getId().'/slots', $page->getContent());
        self::assertStringContainsString('name="_token"', $page->getContent());
        $this->request->attributes->set('_panel_editor', true);
        $this->request->request->replace(['cpu_limit' => '200', 'ram_limit' => '2048', 'disk_limit' => '4096']);
        self::assertSame(403, $controller->updateResources($this->request, $instance->getId())->getStatusCode());
        $this->request->request->set('_token', self::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('admin_instance_edit_'.$instance->getId())->getValue());
        $this->request->request->set('disk_limit', '1024');
        self::assertSame(422, $controller->updateResources($this->request, $instance->getId())->getStatusCode());
        self::assertSame(2048, $instance->getDiskLimit());
        $this->request->request->set('disk_limit', '4096');
        self::assertSame(302, $controller->updateResources($this->request, $instance->getId())->getStatusCode());
        $this->em->refresh($instance);
        self::assertSame(200, $instance->getCpuLimit());
        self::assertSame(2048, $instance->getRamLimit());
        self::assertSame(4096, $instance->getDiskLimit());
        $slots = self::getContainer()->get(\App\Module\Gameserver\UI\Controller\Admin\AdminInstanceSlotsController::class);
        self::assertSame(403, $slots->edit($this->request, $instance->getId())->getStatusCode());
        $this->request->setMethod('GET');
        self::assertStringContainsString('/admin/instances/'.$instance->getId().'/edit', $slots->edit($this->request, $instance->getId())->getContent());
        $this->request->attributes->set('current_user', $this->customer);
        self::assertSame(403, $controller->edit($this->request, $instance->getId())->getStatusCode());
    }

    public function testConfiguredServicesExposeCreationBeforeTheFirstDatabaseOrMailbox(): void
    {
        $this->request->attributes->set('current_user', $this->customer);
        $visibility = self::getContainer()->get(\App\Extension\Twig\CustomerModuleTwigExtension::class);
        self::assertFalse($visibility->customerHasModule('database'));
        self::assertFalse($visibility->customerHasModule('mail'));
        $node = new \App\Module\Core\Domain\Entity\DatabaseNode('Customer SQL', 'mysql', 'database.example.test', 3306, $this->agent);
        $domain = new \App\Module\Core\Domain\Entity\Domain($this->customer, null, 'customer.example.test', 'active');
        foreach ([$node, $domain] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        self::assertTrue($visibility->customerHasModule('database'));
        self::assertTrue($visibility->customerHasModule('mail'));
        $controller = self::getContainer()->get(\App\Module\PanelCustomer\UI\Controller\Customer\CustomerDatabaseController::class);
        $page = $controller->index($this->request);
        self::assertStringContainsString('Customer SQL', $page->getContent());
        self::assertStringContainsString('name="node_id"', $page->getContent());
        $this->request->request->replace(['node_id' => $node->getId(), 'name' => 'community']);
        self::assertSame(303, $controller->create($this->request)->getStatusCode());
        $database = $this->em->getRepository(\App\Module\Core\Domain\Entity\Database::class)->findOneBy(['customer' => $this->customer]);
        self::assertNotNull($database);
        self::assertSame($node, $database->getNode());
        self::assertSame($this->customer, $database->getCustomer());
        $jobs = $this->em->getRepository(\App\Module\Core\Domain\Entity\Job::class)->findBy(['type' => 'database.create']);
        self::assertCount(1, $jobs);
        self::assertSame($this->agent->getId(), $jobs[0]->getPayload()['agent_id']);
        $this->customer->setDatabaseLimit(1);
        $this->em->flush();
        $this->request->request->set('name', 'second');
        self::assertSame(400, $controller->create($this->request)->getStatusCode());
        self::assertSame(1, $this->em->getRepository(\App\Module\Core\Domain\Entity\Database::class)->count(['customer' => $this->customer]));
    }

    public function testMailWithoutProvisioningTargetDoesNotLeaveAnOrphanMailbox(): void
    {
        $domain = new \App\Module\Core\Domain\Entity\Domain($this->customer, null, 'no-mail.example.test', 'active');
        $this->em->persist($domain);
        $this->em->flush();
        $this->request->attributes->set('current_user', $this->customer);
        $this->request->request->replace(['domain_id' => $domain->getId(), 'local_part' => 'kontakt', 'password' => 'synthetic-test-password', 'quota' => '100', 'enabled' => '1', '_token' => self::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('mailbox_create')->getValue()]);
        $response = self::getContainer()->get(\App\Module\PanelCustomer\UI\Controller\Customer\CustomerMailController::class)->create($this->request);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame(0, $this->em->getRepository(\App\Module\Core\Domain\Entity\Mailbox::class)->count([]));
        self::assertSame(0, $this->em->getRepository(\App\Module\Core\Domain\Entity\Job::class)->count(['type' => 'mailbox.create']));
    }

    public function testAdminCanConfigureAMailServerAndAssignACustomerDomain(): void
    {
        $controller = self::getContainer()->get(\App\Module\PanelAdmin\UI\Controller\Admin\AdminMailServerWorkspaceController::class);
        $token = self::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('admin_mail_servers')->getValue();
        $this->request->request->replace(['_token' => $token, 'action' => 'create', 'name' => 'Customer Mail', 'imap_host' => 'imap.example.test', 'imap_port' => '993', 'smtp_host' => 'smtp.example.test', 'smtp_port' => '587', 'agent_id' => $this->agent->getId()]);
        self::assertSame(302, $controller->index($this->request)->getStatusCode());
        $node = $this->em->getRepository(\App\Module\Core\Domain\Entity\MailNode::class)->findOneBy(['name' => 'Customer Mail']);
        self::assertNotNull($node);
        self::assertSame($this->agent, $node->getAgent());
        $domain = new \App\Module\Core\Domain\Entity\Domain($this->customer, null, 'standalone.example.test', 'active');
        $this->em->persist($domain);
        $this->em->flush();
        $this->request->request->replace(['_token' => $token, 'action' => 'assign', 'node_id' => $node->getId(), 'domain_id' => $domain->getId()]);
        self::assertSame(302, $controller->index($this->request)->getStatusCode());
        self::assertSame($node, $this->em->getRepository(\App\Module\Core\Domain\Entity\MailDomain::class)->findOneBy(['domain' => $domain])->getNode());
        self::assertTrue($domain->hasMailCapability());
        $this->request->setMethod('GET');
        self::assertStringContainsString('Customer Mail', $controller->index($this->request)->getContent());
        $this->request->setMethod('POST');
        $this->request->request->set('_token', 'invalid');
        try {
            $controller->index($this->request);
            self::fail('An invalid CSRF token must be rejected.');
        } catch (AccessDeniedHttpException) {
            self::assertCount(1, $this->em->getRepository(\App\Module\Core\Domain\Entity\MailNode::class)->findAll());
        }
        $this->request->attributes->set('current_user', $this->customer);
        $this->expectException(AccessDeniedHttpException::class);
        $controller->index($this->request);
    }

    public function testCustomerOverviewPlotsOwnedMeasurementsAndRetainsConsoleControls(): void
    {
        $template = new Template('ui-test', 'UI Game', null, null, null, [], '', [], [], [], [], '', '', [], []);
        $instance = new Instance($this->customer, $template, $this->agent, 200, 1024, 2048, null, InstanceStatus::Stopped);
        $otherCustomer = new User('other-chart-owner@example.test', UserType::Customer);
        $otherCustomer->setPasswordHash('fixture');
        $foreign = new Instance($otherCustomer, $template, $this->agent, 100, 1024, 2048, null, InstanceStatus::Stopped);
        foreach ([$template, $instance, $otherCustomer, $foreign] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        foreach ([new \App\Module\Core\Domain\Entity\InstanceMetricSample($instance, 20.0, 104857600, 5, new \DateTimeImmutable('-2 minutes'), null), new \App\Module\Core\Domain\Entity\InstanceMetricSample($instance, 25.5, 209715200, 6, new \DateTimeImmutable('-1 minute'), null), new \App\Module\Core\Domain\Entity\InstanceMetricSample($foreign, 88.7, 999999, 2, new \DateTimeImmutable('-30 seconds'), null)] as $sample) {
            $this->em->persist($sample);
        }
        $this->em->flush();
        $this->request->setMethod('GET');
        $this->request->attributes->set('current_user', $this->customer);
        $controller = self::getContainer()->get(\App\Module\Gameserver\UI\Controller\Customer\CustomerInstanceController::class);
        $response = $controller->show($this->request, $instance->getId());
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('pm-chart-cpu', $response->getContent());
        self::assertStringContainsString('25,5 %', $response->getContent());
        self::assertStringNotContainsString('88,7 %', $response->getContent());
        self::assertStringContainsString('id="gameserver-console"', $response->getContent());
        self::assertStringContainsString('id="gs-console-command"', $response->getContent());
        self::assertStringContainsString('data-power-action="restart"', $response->getContent());
        $this->expectException(AccessDeniedHttpException::class);
        $controller->show($this->request, $foreign->getId());
    }

    public function testMailboxFragmentsRetainTheirTargetsAndAdminGuard(): void
    {
        $mailbox = $this->mailboxFixture();
        $controller = self::getContainer()->get(\App\Module\PanelAdmin\UI\Controller\Admin\AdminMailboxController::class);
        $this->request->setMethod('GET');
        $crawler = new \Symfony\Component\DomCrawler\Crawler($controller->index($this->request)->getContent());
        self::assertCount(1, $crawler->filterXPath('//*[@id="mailboxes-table"]'));
        self::assertCount(1, $crawler->filterXPath('//*[@id="mailbox-form"]'));
        for ($refresh = 0; $refresh < 2; ++$refresh) {
            $table = new \Symfony\Component\DomCrawler\Crawler($controller->table($this->request)->getContent());
            self::assertCount(1, $table->filterXPath('//*[@id="mailboxes-table"][@hx-trigger]'));
            self::assertCount(1, $table->filterXPath('//*[@data-panel-reveal="create"]'));
            $form = new \Symfony\Component\DomCrawler\Crawler($controller->edit($this->request, $mailbox->getId())->getContent());
            self::assertCount(1, $form->filterXPath('//*[@id="mailbox-form"]//form'));
        }
        $this->request->attributes->set('current_user', $this->customer);
        self::assertSame(403, $controller->index($this->request)->getStatusCode());
        self::assertSame(403, $controller->table($this->request)->getStatusCode());
        self::assertSame(403, $controller->edit($this->request, $mailbox->getId())->getStatusCode());
    }

    public function testMailboxInventoryLinksToOwnedDetailsAndRetainsProtectedActions(): void
    {
        $mailbox = $this->mailboxFixture();
        $controller = self::getContainer()->get(\App\Module\PanelCustomer\UI\Controller\Customer\CustomerMailController::class);
        $this->request->setMethod('GET');
        $this->request->attributes->set('current_user', $this->customer);
        $crawler = new \Symfony\Component\DomCrawler\Crawler($controller->index($this->request)->getContent());
        self::assertCount(2, $crawler->filterXPath('//a[@href="/mail/mailboxes/'.$mailbox->getId().'"]'));
        self::assertCount(1, $crawler->filterXPath('//form[@action="/mail/'.$mailbox->getId().'/delete"]//input[@name="_token"]'));
        self::assertCount(1, $crawler->filterXPath('//form[@action="/mail/'.$mailbox->getId().'/password"]//input[@name="_token"]'));
        $response = $controller->mailboxDetail($this->request, $mailbox->getId());
        self::assertSame(200, $response->getStatusCode());
        $detail = new \Symfony\Component\DomCrawler\Crawler($response->getContent());
        self::assertSame($mailbox->getAddress(), $detail->filterXPath('//h1')->text());
        self::assertCount(1, $detail->filterXPath('//*[@data-panel-section="security"]//form//input[@name="_csrf_token"]'));
    }

    public function testAliasFragmentsKeepUniqueTargetsAndOpenTheEditor(): void
    {
        $mailbox = $this->mailboxFixture();
        $alias = new \App\Module\Core\Domain\Entity\MailAlias($mailbox->getDomain(), 'info', [$mailbox->getAddress()]);
        $this->em->persist($alias);
        $this->em->flush();
        $controller = self::getContainer()->get(\App\Module\PanelAdmin\UI\Controller\Admin\AdminMailAliasController::class);
        $this->request->setMethod('GET');
        $index = new \Symfony\Component\DomCrawler\Crawler($controller->index($this->request)->getContent());
        self::assertCount(1, $index->filterXPath('//*[@id="mail-aliases-table"]'));
        self::assertCount(1, $index->filterXPath('//*[@id="mail-alias-form"]'));
        $form = new \Symfony\Component\DomCrawler\Crawler($controller->edit($this->request, $alias->getId())->getContent());
        self::assertCount(1, $form->filterXPath('//*[@id="mail-alias-form"]//form'));
        $table = new \Symfony\Component\DomCrawler\Crawler($controller->table($this->request)->getContent());
        self::assertCount(1, $table->filterXPath('//*[@id="mail-aliases-table"]'));
        self::assertCount(1, $table->filterXPath('//*[@data-panel-reveal="create"]'));
        $this->request->attributes->set('current_user', $this->customer);
        self::assertSame(403, $controller->edit($this->request, $alias->getId())->getStatusCode());
    }

    public function testDnsEditorAndTableFragmentsKeepTheirTargetsAndAdminGuard(): void
    {
        $mailbox = $this->mailboxFixture();
        $record = new \App\Module\Core\Domain\Entity\DnsRecord($mailbox->getDomain(), 'www', 'A', '192.0.2.10', 3600);
        $this->em->persist($record);
        $this->em->flush();
        $controller = self::getContainer()->get(\App\Module\PanelAdmin\UI\Controller\Admin\AdminDnsRecordController::class);
        $this->request->setMethod('GET');
        $index = new \Symfony\Component\DomCrawler\Crawler($controller->index($this->request)->getContent());
        self::assertCount(1, $index->filterXPath('//*[@id="dns-records-table"]'));
        self::assertCount(1, $index->filterXPath('//*[@id="dns-record-form"]'));
        $form = new \Symfony\Component\DomCrawler\Crawler($controller->edit($this->request, $record->getId())->getContent());
        self::assertCount(1, $form->filterXPath('//*[@id="dns-record-form"][@data-panel-validation]//form'));
        $table = new \Symfony\Component\DomCrawler\Crawler($controller->table($this->request)->getContent());
        self::assertCount(1, $table->filterXPath('//*[@id="dns-records-table"]'));
        self::assertCount(1, $table->filterXPath('//*[@data-panel-reveal="create"]'));
        $this->request->attributes->set('current_user', $this->customer);
        self::assertSame(403, $controller->edit($this->request, $record->getId())->getStatusCode());
    }

    public function testTicketWorkspacesPreserveInternalNotesAndCustomerReplyBoundary(): void
    {
        $ticket = new \App\Module\Core\Domain\Entity\Ticket($this->customer, 'Workspace support', \App\Module\Core\Domain\Enum\TicketCategory::Technical, \App\Module\Core\Domain\Enum\TicketPriority::Normal);
        $this->em->persist($ticket);
        $this->em->persist(new \App\Module\Core\Domain\Entity\TicketMessage($ticket, $this->admin, 'VISIBLE-CUSTOMER-REPLY'));
        $this->em->persist(new \App\Module\Core\Domain\Entity\TicketMessage($ticket, $this->admin, 'PRIVATE-STAFF-NOTE', true));
        $this->em->flush();
        $this->request->setMethod('GET');
        $admin = self::getContainer()->get(\App\Module\PanelAdmin\UI\Controller\Admin\AdminTicketController::class);
        $adminView = $admin->show($this->request, $ticket->getId())->getContent();
        self::assertStringContainsString('PRIVATE-STAFF-NOTE', $adminView);
        self::assertStringContainsString('/metadata', $adminView);
        $this->request->attributes->set('current_user', $this->customer);
        $customer = self::getContainer()->get(\App\Module\PanelCustomer\UI\Controller\Customer\CustomerTicketController::class);
        $customerView = $customer->show($this->request, $ticket->getId())->getContent();
        self::assertStringContainsString('VISIBLE-CUSTOMER-REPLY', $customerView);
        self::assertStringNotContainsString('PRIVATE-STAFF-NOTE', $customerView);
        $thread = new \Symfony\Component\DomCrawler\Crawler($customerView);
        self::assertCount(1, $thread->filterXPath('//*[@id="ticket-messages"]'));
        self::assertCount(1, $thread->filterXPath('//form[@hx-target="#ticket-messages"]//input[@name="_token"]'));
        $ticket->setStatus(\App\Module\Core\Domain\Enum\TicketStatus::Closed);
        $this->em->flush();
        $closed = new \Symfony\Component\DomCrawler\Crawler($customer->show($this->request, $ticket->getId())->getContent());
        self::assertCount(0, $closed->filterXPath('//form[@hx-target="#ticket-messages"]'));
    }

    public function testTicketCreationValidationRetainsReplaceableFormsAndCsrfChecks(): void
    {
        foreach ([true, false] as $isAdmin) {
            $this->request->attributes->set('current_user', $isAdmin ? $this->admin : $this->customer);
            $controller = self::getContainer()->get($isAdmin ? \App\Module\PanelAdmin\UI\Controller\Admin\AdminTicketController::class : \App\Module\PanelCustomer\UI\Controller\Customer\CustomerTicketController::class);
            $this->request->setMethod('GET');
            $index = new \Symfony\Component\DomCrawler\Crawler($controller->index($this->request)->getContent());
            self::assertCount(1, $index->filterXPath('//*[@id="ticket-form"]'));
            $this->request->setMethod('POST');
            $this->request->request->replace(['_token' => self::getContainer()->get(CsrfTokenManagerInterface::class)->getToken($isAdmin ? 'admin_ticket_create' : 'customer_ticket_create')->getValue(), 'subject' => '', 'message' => '']);
            $response = $controller->create($this->request);
            self::assertSame(400, $response->getStatusCode());
            $form = new \Symfony\Component\DomCrawler\Crawler($response->getContent());
            self::assertCount(1, $form->filterXPath('//*[@id="ticket-form"][@data-panel-validation]'));
            $this->request->request->set('_token', 'invalid');
            self::assertSame(401, $controller->create($this->request)->getStatusCode());
        }
        self::assertSame(0, $this->em->getRepository(\App\Module\Core\Domain\Entity\Ticket::class)->count([]));
    }

    public function testInvalidDomainCreationRetainsTheSubmittedNameInItsEditor(): void
    {
        $this->request->attributes->set('current_user', $this->customer);
        $this->request->setMethod('POST');
        $this->request->request->replace(['domain' => 'invalid..example.test', 'webspace_id' => '-1']);
        $response = self::getContainer()->get(\App\Module\PanelCustomer\UI\Controller\Customer\CustomerDomainController::class)->create($this->request);
        self::assertSame(400, $response->getStatusCode());
        $view = new \Symfony\Component\DomCrawler\Crawler($response->getContent());
        self::assertCount(1, $view->filterXPath('//*[@data-panel-active="create"]'));
        self::assertSame('invalid..example.test', $view->filterXPath('//input[@name="domain"]')->attr('value'));
        self::assertSame(0, $this->em->getRepository(\App\Module\Core\Domain\Entity\Domain::class)->count([]));
    }

    private function mailboxFixture(): \App\Module\Core\Domain\Entity\Mailbox
    {
        $domain = new \App\Module\Core\Domain\Entity\Domain($this->customer, null, 'workspace-mail.example.test', 'active');
        $mailbox = new \App\Module\Core\Domain\Entity\Mailbox($domain, 'contact', 'fixture', ['key_id' => 'fixture', 'nonce' => 'fixture', 'ciphertext' => 'fixture'], 1024);
        $this->em->persist($domain);
        $this->em->persist($mailbox);
        $this->em->flush();

        return $mailbox;
    }
}
