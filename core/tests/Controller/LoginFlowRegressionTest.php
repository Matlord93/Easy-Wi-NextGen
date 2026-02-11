<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Domain\Entity\CmsPage;
use App\Module\Core\Domain\Entity\Site;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\UserSession;
use App\Module\Core\Domain\Enum\UserType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class LoginFlowRegressionTest extends WebTestCase
{
    private static bool $schemaBootstrapped = false;

    public function testAdminRedirectsToAdminPanel(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->seedSite();
        self::getContainer()->get(AppSettingsService::class)->updateSettings([
            AppSettingsService::KEY_SECURITY_2FA_ADMIN_REQUIRED => false,
            AppSettingsService::KEY_SECURITY_2FA_GLOBAL_REQUIRED => false,
        ]);
        $this->createUser('admin@example.test', UserType::Admin);

        $client->request('POST', '/login', ['email' => 'admin@example.test', 'password' => 'P@ssw0rd!'], [], ['REMOTE_ADDR' => '127.0.0.11']);

        self::assertResponseRedirects('/admin');
    }

    public function testCustomerRedirectsToCustomerPanel(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->seedSite();
        $customer = $this->createUser('customer@example.test', UserType::Customer);
        $customer->setCustomerAccessEnabled(true);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('POST', '/login', ['email' => 'customer@example.test', 'password' => 'P@ssw0rd!'], [], ['REMOTE_ADDR' => '127.0.0.12']);

        self::assertResponseRedirects('/customer');
    }

    public function testResellerRedirectsToResellerPanel(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->seedSite();
        $this->createUser('reseller@example.test', UserType::Reseller);

        $client->request('POST', '/login', ['email' => 'reseller@example.test', 'password' => 'P@ssw0rd!'], [], ['REMOTE_ADDR' => '127.0.0.13']);

        self::assertResponseRedirects('/reseller');
    }

    public function testMemberStaysOnHomepageOrTarget(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->seedSite();
        $member = $this->createUser('member@example.test', UserType::Customer);
        $member->setMemberAccessEnabled(true);
        $member->setCustomerAccessEnabled(false);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('POST', '/login', [
            'email' => 'member@example.test',
            'password' => 'P@ssw0rd!',
            'target' => '/pages/impressum',
        ], [], ['REMOTE_ADDR' => '127.0.0.14']);

        self::assertResponseRedirects('/pages/impressum');
    }


    public function testLoginSetsSessionCookieAndHomepageShowsLoggedInHeader(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->seedSite();
        $member = $this->createUser('member-header@example.test', UserType::Customer);
        $member->setMemberAccessEnabled(true);
        $member->setCustomerAccessEnabled(false);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('POST', '/login', [
            'email' => 'member-header@example.test',
            'password' => 'P@ssw0rd!',
        ], [], ['REMOTE_ADDR' => '127.0.0.15']);

        self::assertResponseRedirects('/');
        self::assertTrue($client->getResponse()->headers->has('set-cookie'));

        $sessionCookie = $client->getCookieJar()->get('easywi_session');
        self::assertNotNull($sessionCookie);
        self::assertNotSame('', (string) $sessionCookie?->getValue());

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('member-header@example.test', $client->getResponse()->getContent() ?? '');
    }

    public function testNonAdminCannotAccessAdminCmsEvenWithValidSession(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->seedSite();
        $customer = $this->createUser('customer-admin-gate@example.test', UserType::Customer);

        $rawToken = 'plain-test-session-token';
        $this->persistSession($customer, $rawToken);
        $client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('easywi_session', $rawToken));

        $client->request('GET', '/admin/cms/pages');

        self::assertResponseStatusCodeSame(403);
    }

    private function seedSite(): void
    {
        self::bootKernel();

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->ensureSchema($em);
        $this->ensureInstallLock();

        $conn = $em->getConnection();
        $conn->executeStatement('DELETE FROM user_sessions');
        $conn->executeStatement('DELETE FROM users');
        $conn->executeStatement('DELETE FROM cms_pages');
        $conn->executeStatement('DELETE FROM sites');

        $site = new Site('Demo', 'localhost');
        $em->persist($site);
        $em->persist(new CmsPage($site, 'Startseite', 'startseite', true));
        $em->flush();
    }

    private function createUser(string $email, UserType $type): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User($email, $type);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPasswordHash($hasher->hashPassword($user, 'P@ssw0rd!'));
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function persistSession(User $user, string $rawToken): void
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $session = new UserSession($user, hash('sha256', $rawToken));
        $session->setLastUsedAt(new \DateTimeImmutable());
        $session->setExpiresAt((new \DateTimeImmutable())->modify('+30 days'));

        $em->persist($session);
        $em->flush();
    }

    private function ensureSchema(EntityManagerInterface $em): void
    {
        if (self::$schemaBootstrapped) {
            return;
        }

        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        if ($metadata !== []) {
            $tool->dropSchema($metadata);
            $tool->createSchema($metadata);
        }

        self::$schemaBootstrapped = true;
    }

    private function ensureInstallLock(): void
    {
        $path = dirname(__DIR__, 2) . '/srv/setup/state/install.lock';
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        if (!file_exists($path)) {
            file_put_contents($path, (new \DateTimeImmutable())->format(DATE_ATOM));
        }
    }
}
