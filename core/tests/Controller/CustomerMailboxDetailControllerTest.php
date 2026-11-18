<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\AuditLoggerInterface;
use App\Module\Core\Application\EncryptionServiceInterface;
use App\Module\Core\Application\MailAliasLoopGuard;
use App\Module\Core\Application\MailDnsCheckServiceInterface;
use App\Module\Core\Application\MailLimitEnforcer;
use App\Module\Core\Application\MailPasswordHasher;
use App\Module\Core\Application\MailboxStatsProviderInterface;
use App\Module\Core\Domain\Entity\Mailbox;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\PanelCustomer\UI\Controller\Customer\CustomerMailController;
use App\Repository\DomainRepositoryInterface;
use App\Repository\JobRepositoryInterface;
use App\Repository\MailAliasRepositoryInterface;
use App\Repository\MailDomainRepositoryInterface;
use App\Repository\MailPolicyRepositoryInterface;
use App\Repository\MailboxRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class CustomerMailboxDetailControllerTest extends TestCase
{
    public function testCustomerCanOpenMailboxDetail(): void
    {
        $customer = new User('c@example.com', UserType::Customer);
        $customer->setId(1);
        $mailbox = $this->createStub(Mailbox::class);
        $mailbox->method('getId')->willReturn(1);
        $mailbox->method('getCustomer')->willReturn($customer);
        $mailbox->method('getAddress')->willReturn('u@example.com');
        $mailbox->method('getDomain')->willReturn(new \App\Module\Core\Domain\Entity\Domain($customer, null, 'example.com'));
        $mailbox->method('isEnabled')->willReturn(true);
        $mailbox->method('getQuota')->willReturn(100);

        $mailboxRepo = $this->createStub(MailboxRepositoryInterface::class);
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
        $mailbox = $this->createStub(Mailbox::class);
        $mailbox->method('getId')->willReturn(10);
        $mailbox->method('getCustomer')->willReturn($customer);
        $mailbox->method('getAddress')->willReturn('u@example.com');
        $mailbox->method('getDomain')->willReturn($domain);
        $mailbox->method('getQuota')->willReturn(100);

        $agent = new \App\Module\Core\Domain\Entity\Agent('a', ['key_id' => 'x', 'nonce' => 'y', 'ciphertext' => 'z']);
        $agent->recordHeartbeat(['mail' => ['mailbox_usage' => ['u@example.com' => ['used_bytes' => 52428800]], 'mailbox_usage_truncated' => false]], '1.0.0', null, ['mail'], null, \App\Module\Core\Domain\Entity\Agent::STATUS_ACTIVE);
        $webspace = $this->createStub(\App\Module\Core\Domain\Entity\Webspace::class);
        $webspace->method('getNode')->willReturn($agent);
        $domainStub = $this->createStub(\App\Module\Core\Domain\Entity\Domain::class);
        $domainStub->method('getWebspace')->willReturn($webspace);
        $mailNode = new \App\Module\Core\Domain\Entity\MailNode('n', 'imap', 993, 'smtp', 587, null);
        $mailDomain = $this->createStub(\App\Module\Core\Domain\Entity\MailDomain::class);
        $mailDomain->method('getNode')->willReturn($mailNode);
        $mailDomain->method('getDomain')->willReturn($domainStub);

        $mailboxRepo = $this->createStub(MailboxRepositoryInterface::class); $mailboxRepo->method('find')->willReturn($mailbox);
        $mailDomainRepo = $this->createStub(MailDomainRepositoryInterface::class); $mailDomainRepo->method('findOneByDomain')->willReturn($mailDomain);
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
        $mailbox = $this->createStub(Mailbox::class);
        $mailbox->method('getId')->willReturn(11);
        $mailbox->method('getCustomer')->willReturn($customer);
        $mailbox->method('getAddress')->willReturn('missing@example.com');
        $mailbox->method('getDomain')->willReturn($domain);
        $mailbox->method('getQuota')->willReturn(100);

        $mailboxRepo = $this->createStub(MailboxRepositoryInterface::class); $mailboxRepo->method('find')->willReturn($mailbox);
        $mailDomainRepo = $this->createStub(MailDomainRepositoryInterface::class); $mailDomainRepo->method('findOneByDomain')->willReturn(null);
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
        $mailbox = $this->createStub(Mailbox::class);
        $mailbox->method('getId')->willReturn(12);
        $mailbox->method('getCustomer')->willReturn($customer);
        $mailbox->method('getAddress')->willReturn('U@example.com');
        $mailbox->method('getDomain')->willReturn($domain);
        $mailbox->method('getQuota')->willReturn(0);

        $agent = new \App\Module\Core\Domain\Entity\Agent('a2', ['key_id' => 'x', 'nonce' => 'y', 'ciphertext' => 'z']);
        $agent->recordHeartbeat(['mail' => ['mailbox_usage' => ['u@example.com' => ['used_bytes' => 2097152]]]], '1.0.0', null, ['mail'], null, \App\Module\Core\Domain\Entity\Agent::STATUS_ACTIVE);
        $webspace = $this->createStub(\App\Module\Core\Domain\Entity\Webspace::class); $webspace->method('getNode')->willReturn($agent);
        $domainStub = $this->createStub(\App\Module\Core\Domain\Entity\Domain::class); $domainStub->method('getWebspace')->willReturn($webspace);
        $mailNode = new \App\Module\Core\Domain\Entity\MailNode('n', 'imap', 993, 'smtp', 587, null);
        $mailDomain = $this->createStub(\App\Module\Core\Domain\Entity\MailDomain::class);
        $mailDomain->method('getNode')->willReturn($mailNode);
        $mailDomain->method('getDomain')->willReturn($domainStub);

        $mailboxRepo = $this->createStub(MailboxRepositoryInterface::class); $mailboxRepo->method('find')->willReturn($mailbox);
        $mailDomainRepo = $this->createStub(MailDomainRepositoryInterface::class); $mailDomainRepo->method('findOneByDomain')->willReturn($mailDomain);
        $twig = new Environment(new ArrayLoader(['customer/mail/detail.html.twig' => '{{ quota_usage.used_mb }}|{{ quota_usage.quota_mb }}|{{ quota_usage.percent }}']));
        $controller = $this->buildController($mailboxRepo, $twig, null, $mailDomainRepo);
        $r = new Request(); $r->attributes->set('current_user', $customer);
        $response = $controller->mailboxDetail($r, 12);
        self::assertStringContainsString('2|0|0', (string) $response->getContent());
    }

    public function testWrongCustomerGetsForbidden(): void
    {
        $owner = new User('o@example.com', UserType::Customer);
        $owner->setId(1);
        $actor = new User('a@example.com', UserType::Customer);
        $actor->setId(2);
        $mailbox = $this->createStub(Mailbox::class);
        $mailbox->method('getCustomer')->willReturn($owner);

        $mailboxRepo = $this->createStub(MailboxRepositoryInterface::class);
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

        $mailbox = $this->createStub(Mailbox::class);
        $mailbox->method('getCustomer')->willReturn($owner);

        $mailboxRepo = $this->createStub(MailboxRepositoryInterface::class);
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

        $mailbox = $this->createStub(Mailbox::class);
        $mailbox->method('getCustomer')->willReturn($customer);

        $mailboxRepo = $this->createStub(MailboxRepositoryInterface::class);
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
        $node = $this->createStub(\App\Module\Core\Domain\Entity\MailNode::class);
        $node->method('getId')->willReturn(99);
        $mailDomain = new \App\Module\Core\Domain\Entity\MailDomain($domain, $node);

        $mailbox = $this->createMock(Mailbox::class);
        $mailbox->method('getId')->willReturn(5);
        $mailbox->method('getCustomer')->willReturn($customer);
        $mailbox->method('getDomain')->willReturn($domain);
        $mailbox->method('getLocalPart')->willReturn('u');
        $mailbox->method('getAddress')->willReturn('u@example.com');
        $mailbox->expects(self::once())->method('setPassword');

        $mailboxRepo = $this->createStub(MailboxRepositoryInterface::class);
        $mailboxRepo->method('find')->willReturn($mailbox);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::callback(static fn ($job): bool => $job instanceof \App\Module\Core\Domain\Entity\Job && $job->getType() === 'mailbox.password.reset'));
        $entityManager->expects(self::once())->method('flush');

        $mailDomainRepo = $this->createStub(MailDomainRepositoryInterface::class);
        $mailDomainRepo->method('findOneByDomain')->willReturn($mailDomain);

        $hasher = new MailPasswordHasher('bcrypt');
        $encryption = $this->createStub(EncryptionServiceInterface::class);
        $encryption->method('encrypt')->willReturn(['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c']);

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
        MailboxRepositoryInterface $mailboxRepository,
        Environment $twig,
        ?EntityManagerInterface $entityManager = null,
        ?MailDomainRepositoryInterface $mailDomainRepository = null,
        ?MailPasswordHasher $mailPasswordHasher = null,
        ?EncryptionServiceInterface $encryptionService = null,
    ): CustomerMailController
    {
        $csrfManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfManager->method('isTokenValid')->willReturn(true);
        $csrfManager->method('getToken')->willReturn(new CsrfToken('mailbox_password_1', 'csrf-token-value'));

        return new CustomerMailController(
            $mailboxRepository,
            $this->createStub(MailAliasRepositoryInterface::class),
            $this->createDomainRepository(),
            $mailDomainRepository ?? $this->createStub(MailDomainRepositoryInterface::class),
            $this->createStub(JobRepositoryInterface::class),
            $this->createStub(MailPolicyRepositoryInterface::class),
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            $this->createStub(AuditLoggerInterface::class),
            $encryptionService ?? $this->createStub(EncryptionServiceInterface::class),
            $mailPasswordHasher ?? new MailPasswordHasher(),
            $this->createMailLimitEnforcer(),
            new MailAliasLoopGuard(),
            $this->createStub(MailDnsCheckServiceInterface::class),
            $csrfManager,
            $twig,
            $this->createStub(TranslatorInterface::class),
        );
    }

    private function createMailLimitEnforcer(): MailLimitEnforcer
    {
        $mailboxStatsProvider = $this->createStub(MailboxStatsProviderInterface::class);
        $entityManager = $this->createStub(EntityManagerInterface::class);

        return new MailLimitEnforcer($mailboxStatsProvider, $entityManager);
    }

    private function createDomainRepository(): DomainRepositoryInterface
    {
        $repo = $this->createStub(DomainRepositoryInterface::class);
        $repo->method('findByCustomer')->willReturn([]);
        return $repo;
    }

}
