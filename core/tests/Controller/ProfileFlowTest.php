<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Domain\Entity\Site;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\UserSession;
use App\Module\Core\Domain\Enum\UserType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class ProfileFlowTest extends WebTestCase
{
    private static bool $schemaBootstrapped = false;

    public function testHeaderShowsUsernameWhenLoggedIn(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $user = $this->seedSiteAndUser('header-user@example.test', 'Max Muster');

        $rawToken = 'header-session-token';
        $this->persistSession($user, $rawToken);
        $client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('easywi_session', $rawToken));

        $client->request('GET', '/contact');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('theme-user-email', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('header-user@example.test', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('/customer', (string) $client->getResponse()->getContent());
    }

    public function testProfileEditRequiresAuth(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->seedSiteAndUser('anon@example.test', null);

        $client->request('GET', '/profile/edit');

        self::assertResponseRedirects('/login?target=%2Fprofile%2Fedit');
    }

    public function testProfileEditPersistsChangesWithCsrf(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $user = $this->seedSiteAndUser('profile-user@example.test', 'Before Name');

        $rawToken = 'profile-session-token';
        $this->persistSession($user, $rawToken);
        $client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('easywi_session', $rawToken));

        $client->request('GET', '/profile/edit');
        $csrf = self::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('public_profile_edit')->getValue();

        $client->request('POST', '/profile/edit', [
            '_token' => $csrf,
            'name' => 'After Name',
            'email' => 'profile-updated@example.test',
        ]);

        self::assertResponseIsSuccessful();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->refresh($user);

        self::assertSame('After Name', $user->getName());
        self::assertSame('profile-updated@example.test', $user->getEmail());
    }

    private function seedSiteAndUser(string $email, ?string $name): User
    {
        self::bootKernel();

        /** @var EntityManagerInterface $em */
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
        $user->setCustomerAccessEnabled(false);
        $user->setName($name);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPasswordHash($hasher->hashPassword($user, 'P@ssw0rd!'));

        $em->persist($site);
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
