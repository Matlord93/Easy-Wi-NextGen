<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Application\TwoFactorService;
use App\Module\Core\Domain\Entity\Site;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\UserSession;
use App\Module\Core\Domain\Enum\UserType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class AccountSecurityFlowTest extends WebTestCase
{
    private static bool $schemaBootstrapped = false;

    public function testSecurityPageRequiresReauthByDefault(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $user = $this->seedSiteAndUser('security-user@example.test');
        $this->loginWithSession($client, $user, 'security-session-a');

        $client->request('GET', '/account/security');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Sicherheitsbestätigung', (string) $client->getResponse()->getContent());
    }

    public function testConfirmThenPasswordChangeWorks(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $user = $this->seedSiteAndUser('security-change@example.test');
        $this->loginWithSession($client, $user, 'security-session-b');

        $client->request('GET', '/account/security');
        $csrfConfirm = self::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('account_security_confirm')->getValue();
        $client->request('POST', '/account/security/confirm', [
            '_token' => $csrfConfirm,
            'current_password' => 'P@ssw0rd!',
        ], [], ['REMOTE_ADDR' => '127.0.2.10']);
        self::assertResponseIsSuccessful();

        $csrfPassword = self::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('account_security_password')->getValue();
        $client->request('POST', '/account/security/password', [
            '_token' => $csrfPassword,
            'current_password' => 'P@ssw0rd!',
            'new_password' => 'N3wP@ssw0rd!',
            'new_password_confirm' => 'N3wP@ssw0rd!',
        ]);

        self::assertResponseIsSuccessful();

        $conn = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $remainingSessions = (int) $conn->fetchOne('SELECT COUNT(*) FROM user_sessions WHERE user_id = ?', [$user->getId()]);
        self::assertSame(0, $remainingSessions);

        $logoutEvents = (int) $conn->fetchOne("SELECT COUNT(*) FROM audit_logs WHERE action = 'logout'");
        self::assertGreaterThanOrEqual(1, $logoutEvents);
    }


    public function testSessionGetsInvalidWhenCredentialsVersionChanges(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $user = $this->seedSiteAndUser('security-version@example.test');
        $this->loginWithSession($client, $user, 'security-session-version');

        $user->setPasswordHash(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'ChangedP@ssw0rd!'));
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('GET', '/account/security');

        self::assertResponseRedirects('/login?target=%2Faccount%2Fsecurity');
    }

    public function testQrRouteNeedsLoginAndReturnsImageForLoggedInUser(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $user = $this->seedSiteAndUser('security-qr@example.test');

        $client->request('GET', '/2fa/qr');
        self::assertResponseStatusCodeSame(403);

        $secret = self::getContainer()->get(TwoFactorService::class)->generateSecret();
        $user->setTotpSecret($secret, self::getContainer()->get(SecretsCrypto::class));
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->loginWithSession($client, $user, 'security-session-c');
        $client->request('GET', '/2fa/qr');

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('image/', (string) $client->getResponse()->headers->get('Content-Type'));
    }

    public function testQrRouteReturnsNotFoundForUnreadableSecretPayload(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $user = $this->seedSiteAndUser('security-qr-corrupt@example.test');

        $reflection = new \ReflectionProperty($user, 'totpSecretEncrypted');
        $reflection->setValue($user, 'broken-secret-payload');
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->loginWithSession($client, $user, 'security-session-corrupt');
        $client->request('GET', '/2fa/qr');

        self::assertResponseStatusCodeSame(404);
    }

    public function testLegacySecurityRouteRedirectsToCanonical(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->seedSiteAndUser('legacy@example.test');

        $client->request('GET', '/profile/security');

        self::assertResponseRedirects('/account/security');
    }

    private function seedSiteAndUser(string $email): User
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->ensureSchema($em);
        $this->ensureInstallLock();

        $conn = $em->getConnection();
        $conn->executeStatement('DELETE FROM user_sessions');
        $conn->executeStatement('DELETE FROM users');
        $conn->executeStatement('DELETE FROM sites');

        $site = new Site('Demo', 'localhost');
        $user = new User($email, UserType::Customer);
        $user->setMemberAccessEnabled(true);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPasswordHash($hasher->hashPassword($user, 'P@ssw0rd!'));

        $em->persist($site);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function loginWithSession($client, User $user, string $rawToken): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $session = new UserSession($user, hash('sha256', $rawToken));
        $session->setLastUsedAt(new \DateTimeImmutable());
        $session->setExpiresAt((new \DateTimeImmutable())->modify('+30 days'));
        $em->persist($session);
        $em->flush();

        $client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('easywi_session', $rawToken));
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
