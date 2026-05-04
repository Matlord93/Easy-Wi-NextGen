<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Application\MailAliasLoopGuard;
use App\Module\Core\Application\MailLimitEnforcer;
use App\Module\Core\Application\MailPasswordHasher;
use App\Module\Core\Domain\Entity\Mailbox;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\PanelCustomer\UI\Controller\Customer\CustomerMailController;
use App\Repository\DomainRepository;
use App\Repository\JobRepository;
use App\Repository\MailAliasRepository;
use App\Repository\MailDomainRepository;
use App\Repository\MailPolicyRepository;
use App\Repository\MailboxRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class CustomerMailboxDetailControllerTest extends TestCase
{
    public function testCustomerCanOpenMailboxDetail(): void
    {
        $customer = new User('c@example.com', UserType::Customer);
        $mailbox = $this->createMock(Mailbox::class);
        $mailbox->method('getCustomer')->willReturn($customer);
        $mailbox->method('getAddress')->willReturn('u@example.com');
        $mailbox->method('getDomain')->willReturn(new \App\Module\Core\Domain\Entity\Domain($customer, null, 'example.com'));
        $mailbox->method('isEnabled')->willReturn(true);
        $mailbox->method('getQuota')->willReturn(100);

        $mailboxRepo = $this->createMock(MailboxRepository::class);
        $mailboxRepo->method('find')->willReturn($mailbox);

        $controller = $this->buildController($mailboxRepo, new Environment(new ArrayLoader(['customer/mail/detail.html.twig' => '{{ mailbox.address }} {{ username }} {{ password_csrf }}'])));
        $request = new Request();
        $request->attributes->set('current_user', $customer);
        $response = $controller->mailboxDetail($request, 1);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('u@example.com', $response->getContent() ?: '');
        self::assertStringContainsString('csrf-token-value', $response->getContent() ?: '');
    }


    public function testDetailShowsQuotaUsageWhenAvailable(): void
    {
        $customer = new User('c@example.com', UserType::Customer);
        $customer->setId(1);
        $domain = new \App\Module\Core\Domain\Entity\Domain($customer, null, 'example.com');
        $mailbox = $this->createMock(Mailbox::class);
        $mailbox->method('getId')->willReturn(10);
        $mailbox->method('getCustomer')->willReturn($customer);
        $mailbox->method('getAddress')->willReturn('u@example.com');
        $mailbox->method('getDomain')->willReturn($domain);
        $mailbox->method('getQuota')->willReturn(100);

        $agent = new \App\Module\Core\Domain\Entity\Agent('a', ['key_id' => 'x', 'nonce' => 'y', 'ciphertext' => 'z']);
        $agent->recordHeartbeat(['mail' => ['mailbox_usage' => ['u@example.com' => ['used_bytes' => 52428800]], 'mailbox_usage_truncated' => false]], '1.0.0', null, ['mail'], null, \App\Module\Core\Domain\Entity\Agent::STATUS_ACTIVE);
        $webspace = $this->createMock(\App\Module\Core\Domain\Entity\Webspace::class);
        $webspace->method('getNode')->willReturn($agent);
        $domainStub = $this->createMock(\App\Module\Core\Domain\Entity\Domain::class);
        $domainStub->method('getWebspace')->willReturn($webspace);
        $mailNode = new \App\Module\Core\Domain\Entity\MailNode('n', 'imap', 993, 'smtp', 587, null);
        $mailDomain = $this->createMock(\App\Module\Core\Domain\Entity\MailDomain::class);
        $mailDomain->method('getNode')->willReturn($mailNode);
        $mailDomain->method('getDomain')->willReturn($domainStub);

        $mailboxRepo = $this->createMock(MailboxRepository::class); $mailboxRepo->method('find')->willReturn($mailbox);
        $mailDomainRepo = $this->createMock(MailDomainRepository::class); $mailDomainRepo->method('findOneByDomain')->willReturn($mailDomain);
        $twig = new Environment(new ArrayLoader(['customer/mail/detail.html.twig' => '{{ quota_usage.available ? quota_usage.used_mb : "missing" }}|{{ quota_usage.percent }}|{{ quota_usage.quota_mb }}']));
        $controller = $this->buildController($mailboxRepo, $twig, null, $mailDomainRepo);
        $r = new Request(); $r->attributes->set('current_user', $customer);
        $response = $controller->mailboxDetail($r, 10);
        self::assertStringContainsString('50|50|100', (string) $response->getContent());
    }


    public function testDetailShowsMissingUsageHintState(): void
    {
        $customer = new User('c@example.com', UserType::Customer);
        $domain = new \App\Module\Core\Domain\Entity\Domain($customer, null, 'example.com');
        $mailbox = $this->createMock(Mailbox::class);
        $mailbox->method('getId')->willReturn(11);
        $mailbox->method('getCustomer')->willReturn($customer);
        $mailbox->method('getAddress')->willReturn('missing@example.com');
        $mailbox->method('getDomain')->willReturn($domain);
        $mailbox->method('getQuota')->willReturn(100);

        $mailboxRepo = $this->createMock(MailboxRepository::class); $mailboxRepo->method('find')->willReturn($mailbox);
        $mailDomainRepo = $this->createMock(MailDomainRepository::class); $mailDomainRepo->method('findOneByDomain')->willReturn(null);
        $twig = new Environment(new ArrayLoader(['customer/mail/detail.html.twig' => '{{ quota_usage.available ? "available" : "missing" }}']));
        $controller = $this->buildController($mailboxRepo, $twig, null, $mailDomainRepo);
        $r = new Request(); $r->attributes->set('current_user', $customer);
        $response = $controller->mailboxDetail($r, 11);
        self::assertStringContainsString('missing', (string) $response->getContent());
    }

    public function testDetailSupportsUnlimitedQuotaWithoutDivisionByZero(): void
    {
        $customer = new User('c@example.com', UserType::Customer);
        $domain = new \App\Module\Core\Domain\Entity\Domain($customer, null, 'example.com');
        $mailbox = $this->createMock(Mailbox::class);
        $mailbox->method('getId')->willReturn(12);
        $mailbox->method('getCustomer')->willReturn($customer);
        $mailbox->method('getAddress')->willReturn('U@example.com');
        $mailbox->method('getDomain')->willReturn($domain);
        $mailbox->method('getQuota')->willReturn(0);

        $agent = new \App\Module\Core\Domain\Entity\Agent('a2', ['key_id' => 'x', 'nonce' => 'y', 'ciphertext' => 'z']);
        $agent->recordHeartbeat(['mail' => ['mailbox_usage' => ['u@example.com' => ['used_bytes' => 2097152]]]], '1.0.0', null, ['mail'], null, \App\Module\Core\Domain\Entity\Agent::STATUS_ACTIVE);
        $webspace = $this->createMock(\App\Module\Core\Domain\Entity\Webspace::class); $webspace->method('getNode')->willReturn($agent);
        $domainStub = $this->createMock(\App\Module\Core\Domain\Entity\Domain::class); $domainStub->method('getWebspace')->willReturn($webspace);
        $mailNode = new \App\Module\Core\Domain\Entity\MailNode('n', 'imap', 993, 'smtp', 587, null);
        $mailDomain = $this->createMock(\App\Module\Core\Domain\Entity\MailDomain::class);
        $mailDomain->method('getNode')->willReturn($mailNode);
        $mailDomain->method('getDomain')->willReturn($domainStub);

        $mailboxRepo = $this->createMock(MailboxRepository::class); $mailboxRepo->method('find')->willReturn($mailbox);
        $mailDomainRepo = $this->createMock(MailDomainRepository::class); $mailDomainRepo->method('findOneByDomain')->willReturn($mailDomain);
        $twig = new Environment(new ArrayLoader(['customer/mail/detail.html.twig' => '{{ quota_usage.used_mb }}|{{ quota_usage.quota_mb }}|{{ quota_usage.percent }}']));
        $controller = $this->buildController($mailboxRepo, $twig, null, $mailDomainRepo);
        $r = new Request(); $r->attributes->set('current_user', $customer);
        $response = $controller->mailboxDetail($r, 12);
        self::assertStringContainsString('2|0|0', (string) $response->getContent());
    }

    public function testWrongCustomerGetsForbidden(): void
    {
        $owner = new User('o@example.com', UserType::Customer);
        $actor = new User('a@example.com', UserType::Customer);
        $mailbox = $this->createMock(Mailbox::class);
        $mailbox->method('getCustomer')->willReturn($owner);

        $mailboxRepo = $this->createMock(MailboxRepository::class);
        $mailboxRepo->method('find')->willReturn($mailbox);

        $controller = $this->buildController($mailboxRepo, new Environment(new ArrayLoader(['customer/mail/detail.html.twig' => 'x'])));
        $request = new Request();
        $request->attributes->set('current_user', $actor);
        $response = $controller->mailboxDetail($request, 1);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testTemplateContainsNoContentFields(): void
    {
        $template = strtolower((string) file_get_contents(__DIR__ . '/../../templates/customer/mail/detail.html.twig'));
        foreach (['subject', 'body', 'from', 'sender', 'value=\"{{ password'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $template);
        }
    }


    public function testTemplateContainsSetupSectionsAndCopyActions(): void
    {
        $template = (string) file_get_contents(__DIR__ . '/../../templates/customer/mail/detail.html.twig');
        foreach (['IMAP', 'POP3', 'SMTP', 'customer_mailbox_detail_copy_imap', 'customer_mailbox_detail_copy_pop3', 'customer_mailbox_detail_copy_smtp', 'customer_mailbox_detail_copy_config', 'customer_mailbox_detail_webmail_missing', 'customer_mailbox_detail_setup_smtp_auth_enabled'] as $expected) {
            self::assertStringContainsString($expected, $template);
        }
    }

    public function testTemplateContainsWebmailBranching(): void
    {
        $template = (string) file_get_contents(__DIR__ . '/../../templates/customer/mail/detail.html.twig');
        self::assertStringContainsString('{% if webmail_url %}', $template);
        self::assertStringContainsString('customer_mailbox_detail_webmail_open', $template);
        self::assertStringContainsString('customer_mailbox_detail_webmail_missing', $template);
    }

    public function testWrongCustomerCannotResetPassword(): void
    {
        $owner = new User('o@example.com', UserType::Customer);
        $owner->setId(1);
        $actor = new User('a@example.com', UserType::Customer);
        $actor->setId(2);

        $mailbox = $this->createMock(Mailbox::class);
        $mailbox->method('getCustomer')->willReturn($owner);

        $mailboxRepo = $this->createMock(MailboxRepository::class);
        $mailboxRepo->method('find')->willReturn($mailbox);

        $controller = $this->buildController($mailboxRepo, new Environment(new ArrayLoader(['customer/mail/index.html.twig' => 'Mailbox not found.'])));
        $request = new Request([], ['password' => '12345678', '_csrf_token' => 'ok']);
        $request->attributes->set('current_user', $actor);
        $response = $controller->resetPassword($request, 1);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('Mailbox not found.', (string) $response->getContent());
    }

    public function testPasswordUnderEightCharsIsRejected(): void
    {
        $customer = new User('c@example.com', UserType::Customer);
        $customer->setId(1);

        $mailbox = $this->createMock(Mailbox::class);
        $mailbox->method('getCustomer')->willReturn($customer);

        $mailboxRepo = $this->createMock(MailboxRepository::class);
        $mailboxRepo->method('find')->willReturn($mailbox);

        $controller = $this->buildController($mailboxRepo, new Environment(new ArrayLoader(['customer/mail/index.html.twig' => 'Password must be at least 8 characters.'])));
        $request = new Request([], ['password' => '1234567', '_csrf_token' => 'ok']);
        $request->attributes->set('current_user', $customer);
        $response = $controller->resetPassword($request, 1);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('Password must be at least 8 characters.', (string) $response->getContent());
    }

    public function testSuccessfulResetQueuesJobAndRedirectsToMailboxDetail(): void
    {
        $customer = new User('c@example.com', UserType::Customer);
        $customer->setId(1);
        $domain = new \App\Module\Core\Domain\Entity\Domain($customer, null, 'example.com');
        $domain->setId(20);
        $node = new \App\Module\Core\Domain\Entity\MailNode('Mail Node', 'imap.example.com', 993, 'smtp.example.com', 587, null);
        $node->setId(99);
        $mailDomain = new \App\Module\Core\Domain\Entity\MailDomain($domain, $node);

        $mailbox = $this->createMock(Mailbox::class);
        $mailbox->method('getId')->willReturn(5);
        $mailbox->method('getCustomer')->willReturn($customer);
        $mailbox->method('getDomain')->willReturn($domain);
        $mailbox->method('getLocalPart')->willReturn('u');
        $mailbox->method('getAddress')->willReturn('u@example.com');
        $mailbox->expects(self::once())->method('setPassword');

        $mailboxRepo = $this->createMock(MailboxRepository::class);
        $mailboxRepo->method('find')->willReturn($mailbox);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::callback(static fn ($job): bool => $job instanceof \App\Module\Core\Domain\Entity\Job && $job->getType() === 'mailbox.password.reset'));
        $entityManager->expects(self::once())->method('flush');

        $mailDomainRepo = $this->createMock(MailDomainRepository::class);
        $mailDomainRepo->method('findOneByDomain')->willReturn($mailDomain);

        $hasher = $this->createMock(MailPasswordHasher::class);
        $hasher->method('hash')->willReturn('hashed-value');
        $encryption = $this->createMock(EncryptionService::class);
        $encryption->method('encrypt')->willReturn('secret-value');

        $controller = $this->buildController(
            $mailboxRepo,
            new Environment(new ArrayLoader(['customer/mail/index.html.twig' => 'x'])),
            $entityManager,
            $mailDomainRepo,
            $hasher,
            $encryption,
        );
        $request = new Request([], ['password' => '12345678', '_csrf_token' => 'ok']);
        $request->attributes->set('current_user', $customer);
        $response = $controller->resetPassword($request, 5);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/mail/mailboxes/5?password_scheduled=1', $response->headers->get('Location'));
    }

    private function buildController(
        MailboxRepository $mailboxRepository,
        Environment $twig,
        ?EntityManagerInterface $entityManager = null,
        ?MailDomainRepository $mailDomainRepository = null,
        ?MailPasswordHasher $mailPasswordHasher = null,
        ?EncryptionService $encryptionService = null,
    ): CustomerMailController
    {
        $csrfManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfManager->method('isTokenValid')->willReturn(true);
        $csrfManager->method('getToken')->willReturn(new CsrfToken('mailbox_password_1', 'csrf-token-value'));

        return new CustomerMailController(
            $mailboxRepository,
            $this->createMock(MailAliasRepository::class),
            $this->createMock(DomainRepository::class),
            $mailDomainRepository ?? $this->createMock(MailDomainRepository::class),
            $this->createMock(JobRepository::class),
            $this->createMock(MailPolicyRepository::class),
            $entityManager ?? $this->createMock(EntityManagerInterface::class),
            $this->createMock(AuditLogger::class),
            $encryptionService ?? $this->createMock(EncryptionService::class),
            $mailPasswordHasher ?? $this->createMock(MailPasswordHasher::class),
            $this->createMock(MailLimitEnforcer::class),
            $this->createMock(MailAliasLoopGuard::class),
            $csrfManager,
            $twig,
        );
    }
}
