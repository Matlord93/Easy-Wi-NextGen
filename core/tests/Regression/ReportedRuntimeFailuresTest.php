<?php

declare(strict_types=1);

namespace App\Tests\Regression;

use App\Module\AgentOrchestrator\Application\AgentJobResultApplier;
use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\AgentOrchestrator\Domain\Enum\AgentJobStatus;
use App\Module\Cms\UI\Command\CmsMigrateLegacyCommand;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Application\QrCodeService;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\CmsBlock;
use App\Module\Core\Domain\Entity\CmsEvent;
use App\Module\Core\Domain\Entity\Domain;
use App\Module\Core\Domain\Entity\MailDomain;
use App\Module\Core\Domain\Entity\MailNode;
use App\Module\Core\Domain\Entity\MailPolicy;
use App\Module\Core\Domain\Entity\Site;
use App\Module\Core\Domain\Entity\Ts3Node;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\PanelAdmin\UI\Controller\Admin\AdminMailPolicyController;
use App\Module\PanelAdmin\UI\Controller\Admin\AdminMailSystemController;
use App\Module\PanelCustomer\UI\Controller\Api\BackupTargetApiController;
use App\Repository\CmsEventRepository;
use App\Repository\CmsPageRepository;
use App\Repository\CmsPostRepository;
use App\Repository\DomainRepository;
use App\Repository\MailDomainRepository;
use App\Repository\MailPolicyRepository;
use App\Repository\SiteRepository;
use App\Repository\TeamMemberRepository;
use App\Repository\Ts3NodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class ReportedRuntimeFailuresTest extends TestCase
{
    public function testMailPolicyUpdatePreservesIndependentSmtpAndAbuseSettings(): void
    {
        $domain = new Domain(new User('customer@example.test', UserType::Customer), null, 'example.test');
        $domainRepository = $this->createStub(DomainRepository::class);
        $domainRepository->method('find')->willReturn($domain);
        $policy = new MailPolicy($domain);
        $policy->apply(false, false, 100, 500, false, false, MailPolicy::SPAM_LOW, true);
        $policyRepository = $this->createStub(MailPolicyRepository::class);
        $policyRepository->method('findOneByDomain')->willReturn($policy);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $controller = new AdminMailPolicyController($entityManager, $domainRepository, $policyRepository, $this->createStub(AuditLogger::class));
        $request = Request::create('/api/v1/admin/mail/policies/domains/7', 'PUT', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'require_tls' => true,
            'max_recipients' => 30,
            'max_hourly_emails' => 150,
            'spam_protection_level' => 'high',
        ], JSON_THROW_ON_ERROR));
        $request->attributes->set('current_user', new User('admin@example.test', UserType::Admin));

        self::assertSame(200, $controller->upsertPolicy($request, 7)->getStatusCode());
        self::assertTrue($policy->isRequireTls());
        self::assertSame(30, $policy->getMaxRecipients());
        self::assertSame(150, $policy->getMaxHourlyEmails());
        self::assertSame(MailPolicy::SPAM_HIGH, $policy->getSpamProtectionLevel());
        self::assertFalse($policy->isSmtpEnabled());
        self::assertFalse($policy->isAbusePolicyEnabled());
    }

    public function testMailContainmentUsesTheDomainBehindItsMailBinding(): void
    {
        $domain = new Domain(new User('customer@example.test', UserType::Customer), null, 'example.test');
        $binding = new MailDomain($domain, new MailNode('mail', 'imap.example.test', 993, 'smtp.example.test', 587, null));
        $domainRepository = $this->createStub(MailDomainRepository::class);
        $domainRepository->method('find')->willReturn($binding);
        $policy = new MailPolicy($domain);
        $policy->apply(false, false, 100, 500, false, true, MailPolicy::SPAM_LOW, false);
        $policyRepository = $this->createMock(MailPolicyRepository::class);
        $policyRepository->expects(self::once())->method('findOneByDomain')->with($domain)->willReturn($policy);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(true);
        $controller = $this->withDependencies(AdminMailSystemController::class, [
            'mailDomainRepository' => $domainRepository,
            'mailPolicyRepository' => $policyRepository,
            'entityManager' => $entityManager,
            'auditLogger' => $this->createStub(AuditLogger::class),
            'csrfTokenManager' => $csrf,
        ]);
        $request = Request::create('/admin/mail/security/contain-domain', 'POST', ['domain_id' => '9', '_token' => 'valid']);
        $request->attributes->set('current_user', new User('admin@example.test', UserType::Admin));

        self::assertSame(303, $controller->containDomain($request)->getStatusCode());
        self::assertTrue($policy->isRequireTls());
        self::assertTrue($policy->isAbusePolicyEnabled());
        self::assertTrue($policy->isGreylistingEnabled());
        self::assertFalse($policy->isAllowExternalForwarding());
        self::assertFalse($policy->isSmtpEnabled());
        self::assertSame(25, $policy->getMaxRecipients());
        self::assertSame(200, $policy->getMaxHourlyEmails());
    }

    public function testQrCodeProducesPngWithTheInstalledEndroidApi(): void
    {
        self::assertTrue(extension_loaded('gd'), 'PNG regression requires the project GD extension.');
        $image = (new QrCodeService())->renderImage('otpauth://totp/Regression?secret=JBSWY3DPEHPK3PXP');
        self::assertSame('image/png', $image['mimeType']);
        self::assertStringStartsWith("\x89PNG\r\n\x1a\n", $image['content']);
    }

    public function testTs3InstallResultAcceptsAnAgentDependenciesPayload(): void
    {
        $agent = new Agent('node', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c']);
        $node = new Ts3Node('ts3', $agent, 'http://localhost', 'encrypted', 'https://example.test/ts3.tar.gz', '/opt/ts3', 'ts3', 'ts3');
        $inner = $this->createStub(EntityRepository::class);
        $inner->method('find')->willReturn($node);
        $repository = $this->repository(Ts3NodeRepository::class, $inner);
        $applier = $this->withDependencies(AgentJobResultApplier::class, ['ts3NodeRepository' => $repository, 'entityManager' => $this->createStub(EntityManagerInterface::class)]);
        $job = new AgentJob('test-install', $agent, 'ts3.install', ['node_id' => 1]);

        $applier->apply($job, AgentJobStatus::Success, ['installed_version' => '3.13.7', 'dependencies' => ['ts3_client_installed' => true]]);

        self::assertSame('installed', $node->getInstallStatus());
        self::assertSame('3.13.7', $node->getInstalledVersion());
    }

    public function testLegacyCmsEventsUseTheMappedStartDate(): void
    {
        $site = new Site('Example', 'example.test');
        $siteInner = $this->createStub(EntityRepository::class);
        $siteInner->method('findBy')->willReturn([$site]);
        $siteRepository = $this->repository(SiteRepository::class, $siteInner);
        $eventInner = $this->createMock(EntityRepository::class);
        $eventInner->expects(self::once())->method('findBy')->with(['site' => $site, 'isPublished' => true], ['startAt' => 'ASC'])->willReturn([
            new CmsEvent($site, 'Release <party>', 'release', '', new \DateTimeImmutable('2026-11-13 18:30:00')),
        ]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(CmsBlock::class)->willReturn($this->createStub(EntityRepository::class));
        $blocks = [];
        $entityManager->expects(self::exactly(6))->method('persist')->willReturnCallback(static function (object $entity) use (&$blocks): void {
            if ($entity instanceof CmsBlock) {
                $blocks[] = $entity;
            }
        });
        $entityManager->expects(self::once())->method('flush');
        $command = new CmsMigrateLegacyCommand($siteRepository, $this->repository(CmsPageRepository::class, $this->createStub(EntityRepository::class)), $this->repository(CmsPostRepository::class, $this->createStub(EntityRepository::class)), $this->repository(CmsEventRepository::class, $eventInner), $this->repository(TeamMemberRepository::class, $this->createStub(EntityRepository::class)), $entityManager);

        self::assertSame(0, (new CommandTester($command))->execute([]));
        self::assertCount(3, $blocks);
        self::assertStringContainsString('Release &lt;party&gt;', $blocks[1]->getContent());
        self::assertStringContainsString('2026-11-13 18:30', $blocks[1]->getContent());
    }

    public function testMailboxDetailShowsTheLastProvisioningFailure(): void
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $customer->setId(1);
        $domain = new Domain($customer, null, 'example.test');
        $mailbox = new \App\Module\Core\Domain\Entity\Mailbox($domain, 'user', 'hash', [], 100);
        (new \ReflectionProperty($mailbox, 'id'))->setValue($mailbox, 1);
        $mailboxRepository = $this->createStub(\App\Repository\MailboxRepository::class);
        $mailboxRepository->method('find')->willReturn($mailbox);
        $job = new \App\Module\Core\Domain\Entity\Job('mailbox.create', ['address' => 'user@example.test']);
        new \App\Module\Core\Domain\Entity\JobResult($job, \App\Module\Core\Domain\Enum\JobResultStatus::Failed, ['error_message' => 'Provisioning failed']);
        $jobRepository = $this->createStub(\App\Repository\JobRepository::class);
        $jobRepository->method('findLatestByType')->willReturnCallback(static fn (string $type): array => $type === 'mailbox.create' ? [$job] : []);
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturn(new \Symfony\Component\Security\Csrf\CsrfToken('mailbox', 'test-token'));
        $controller = $this->withDependencies(\App\Module\PanelCustomer\UI\Controller\Customer\CustomerMailController::class, [
            'mailboxRepository' => $mailboxRepository,
            'mailDomainRepository' => $this->createStub(MailDomainRepository::class),
            'mailPolicyRepository' => $this->createStub(MailPolicyRepository::class),
            'jobRepository' => $jobRepository,
            'csrfTokenManager' => $csrf,
            'twig' => new \Twig\Environment(new \Twig\Loader\ArrayLoader(['customer/mail/detail.html.twig' => '{{ last_job.error }}'])),
        ]);
        $request = Request::create('/mail/mailboxes/1');
        $request->attributes->set('current_user', $customer);

        $response = $controller->mailboxDetail($request, 1);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Provisioning failed', $response->getContent());
    }

    public function testS3TargetStoresCredentialsEncryptedAndReturnsOnlyPresenceFlags(): void
    {
        $encryption = $this->createMock(EncryptionService::class);
        $encryption->expects(self::exactly(2))->method('encrypt')->willReturnCallback(static fn (string $value): array => ['key_id' => 'test', 'nonce' => 'nonce', 'ciphertext' => base64_encode($value)]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::callback(static function (object $target): bool {
            self::assertInstanceOf(\App\Module\Core\Domain\Entity\BackupTarget::class, $target);
            self::assertSame('archive-bucket', $target->getConfig()['bucket']);
            self::assertSame(base64_encode('access-id'), $target->getEncryptedCredentials()['access_key']['ciphertext']);
            self::assertSame(base64_encode('private-secret'), $target->getEncryptedCredentials()['secret_key']['ciphertext']);

            return true;
        }));
        $entityManager->expects(self::once())->method('flush');
        $controller = $this->withDependencies(BackupTargetApiController::class, [
            'encryptionService' => $encryption,
            'entityManager' => $entityManager,
            'auditLogger' => $this->createStub(AuditLogger::class),
        ]);
        $request = Request::create('/api/backup-targets', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'type' => 's3', 'label' => 'Archive', 'config' => ['bucket' => 'archive-bucket'],
            'credentials' => ['access_key' => 'access-id', 'secret_key' => 'private-secret'],
        ], JSON_THROW_ON_ERROR));
        $request->attributes->set('current_user', new User('customer@example.test', UserType::Customer));

        $response = $controller->create($request);

        self::assertSame(201, $response->getStatusCode());
        self::assertStringNotContainsString('private-secret', (string) $response->getContent());
        self::assertStringContainsString('"secret_key":true', (string) $response->getContent());
    }

    public function testInvalidS3ConfigAndMissingCredentialsReturnValidationErrors(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $controller = $this->withDependencies(BackupTargetApiController::class, ['entityManager' => $entityManager]);
        foreach ([
            [],
            ['bucket' => ['invalid']],
            ['bucket' => 'archive-bucket', 'endpoint' => 'file:///tmp/backup'],
            ['bucket' => 'archive-bucket', 'endpoint' => 'https://user:password@example.test'],
            ['bucket' => 'archive-bucket'],
        ] as $config) {
            $request = Request::create('/api/backup-targets', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'type' => 's3', 'label' => 'Archive', 'config' => $config,
            ], JSON_THROW_ON_ERROR));
            $request->attributes->set('current_user', new User('customer@example.test', UserType::Customer));

            self::assertSame(400, $controller->create($request)->getStatusCode());
        }
    }

    public function testTeamspeakAssignmentsFlushThroughTheInjectedEntityManager(): void
    {
        foreach ([3, 6] as $version) {
            $agent = new Agent('node', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c']);
            $nodeClass = $version === 3 ? Ts3Node::class : \App\Module\Core\Domain\Entity\Ts6Node::class;
            $node = new $nodeClass('voice', $agent, 'http://localhost', 'encrypted', 'https://example.test/server.tar.gz', '/opt/voice', 'voice', 'voice');
            $server = $version === 3
                ? new \App\Module\Core\Domain\Entity\Ts3VirtualServer($node, 1, 42, 'Voice')
                : new \App\Module\Core\Domain\Entity\Ts6VirtualServer($node, 1, 42, 'Voice', 10);
            $repositoryClass = $version === 3 ? \App\Repository\Ts3VirtualServerRepository::class : \App\Repository\Ts6VirtualServerRepository::class;
            $inner = $this->createStub(EntityRepository::class);
            $inner->method('find')->willReturn($server);
            $customer = new User('customer@example.test', UserType::Customer);
            $userRepository = $this->createStub(\App\Repository\UserRepository::class);
            $userRepository->method('find')->willReturn($customer);
            $entityManager = $this->createMock(EntityManagerInterface::class);
            $entityManager->expects(self::once())->method('flush');
            $csrf = $this->createStub(CsrfTokenManagerInterface::class);
            $csrf->method('isTokenValid')->willReturn(true);
            $controllerClass = $version === 3 ? \App\Module\Teamspeak\UI\Controller\Admin\AdminTs3ServerController::class : \App\Module\Teamspeak\UI\Controller\Admin\AdminTs6ServerController::class;
            $controller = $this->withDependencies($controllerClass, [
                'virtualServerRepository' => $this->repository($repositoryClass, $inner),
                'userRepository' => $userRepository,
                'entityManager' => $entityManager,
                'csrfTokenManager' => $csrf,
                'translator' => $this->createStub(\Symfony\Contracts\Translation\TranslatorInterface::class),
            ]);
            $request = Request::create('/assign', 'POST', ['customer_id' => 7, '_token' => 'valid']);
            $request->attributes->set('current_user', new User('admin@example.test', UserType::Admin));
            $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()));

            self::assertSame(302, $controller->assign($request, 1)->getStatusCode());
            self::assertSame(7, $server->getCustomerId());
        }
    }

    /**
     * @template T of ServiceEntityRepository
     * @param class-string<T> $class
     * @return T
     */
    private function repository(string $class, EntityRepository $inner): ServiceEntityRepository
    {
        $repository = (new \ReflectionClass($class))->newInstance($this->createStub(ManagerRegistry::class));
        (new \ReflectionProperty(ServiceEntityRepository::class, 'repository'))->setValue($repository, $inner);

        return $repository;
    }

    /**
     * Instantiates an action with only the dependencies exercised by that action.
     *
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, object> $dependencies
     * @return T
     */
    private function withDependencies(string $class, array $dependencies): object
    {
        $reflection = new \ReflectionClass($class);
        $controller = $reflection->newInstanceWithoutConstructor();
        foreach ($dependencies as $name => $dependency) {
            $reflection->getProperty($name)->setValue($controller, $dependency);
        }

        return $controller;
    }
}
